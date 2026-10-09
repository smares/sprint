<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Notifications\InboxPush;
use App\Notifications\TaskDueTomorrow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    private User $user;

    /** What the fake push service answers. */
    private int $pushServiceStatus = 201;

    protected function setUp(): void
    {
        parent::setUp();

        $keys = VAPID::createVapidKeys();
        config(['webpush.vapid.public_key' => $keys['publicKey'], 'webpush.vapid.private_key' => $keys['privateKey']]);

        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);

        Http::fake(fn () => Http::response('', $this->pushServiceStatus));
    }

    /**
     * A subscription with real browser keys, which the payload is encrypted for.
     *
     * @return array{0: string, 1: string}
     */
    private function browserKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [$encode("\x04".$ec['x'].$ec['y']), $encode(random_bytes(16))];
    }

    private function subscribe(User $user, string $endpoint = self::ENDPOINT): PushSubscription
    {
        [$publicKey, $authToken] = $this->browserKeys();

        return $user->updatePushSubscription($endpoint, $publicKey, $authToken, 'aes128gcm');
    }

    public function test_the_profile_stores_and_removes_this_browsers_subscription(): void
    {
        [$publicKey, $authToken] = $this->browserKeys();

        $profile = Livewire::test('pages::profile')
            ->assertSee('Push-Benachrichtigungen')
            ->assertSeeHtml('pushToggle(')
            ->call('savePushSubscription', self::ENDPOINT, $publicKey, $authToken, 'aes128gcm')
            ->assertHasNoErrors()
            ->assertReturned(true);

        $this->assertSame([self::ENDPOINT], $this->user->pushSubscriptions()->pluck('endpoint')->all());
        $profile->call('hasPushSubscription', self::ENDPOINT)->assertReturned(true);

        $profile->call('removePushSubscription', self::ENDPOINT);
        $this->assertSame(0, $this->user->pushSubscriptions()->count());
    }

    public function test_only_addresses_of_the_browsers_push_services_are_accepted(): void
    {
        [$publicKey, $authToken] = $this->browserKeys();

        foreach (['https://127.0.0.1/hook', 'http://fcm.googleapis.com/fcm/send/x', 'https://fcm.googleapis.com.evil.test/x', 'https://intranet.local/x'] as $endpoint) {
            Livewire::test('pages::profile')
                ->call('savePushSubscription', $endpoint, $publicKey, $authToken, 'aes128gcm')
                ->assertHasErrors('endpoint');
        }

        foreach (['https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/x', 'https://wns2-par02p.notify.windows.com/w/?token=x'] as $endpoint) {
            Livewire::test('pages::profile')->call('savePushSubscription', $endpoint, $publicKey, $authToken, 'aes128gcm')->assertHasNoErrors();
        }

        $this->assertSame(3, $this->user->pushSubscriptions()->count());
    }

    public function test_a_subscription_of_someone_else_is_neither_shown_nor_removed(): void
    {
        $this->subscribe(User::factory()->create());

        Livewire::test('pages::profile')
            ->call('hasPushSubscription', self::ENDPOINT)->assertReturned(false)
            ->call('removePushSubscription', self::ENDPOINT);

        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_without_keys_the_profile_explains_that_push_is_not_set_up(): void
    {
        config(['webpush.vapid.public_key' => null]);
        [$publicKey, $authToken] = $this->browserKeys();

        Livewire::test('pages::profile')
            ->assertSee('In dieser Installation noch nicht eingerichtet')
            ->call('savePushSubscription', self::ENDPOINT, $publicKey, $authToken, 'aes128gcm')
            ->assertStatus(404);
    }

    public function test_a_new_inbox_entry_goes_to_the_subscribed_devices(): void
    {
        $this->subscribe($this->user);
        $task = Task::factory()->create(['title' => 'Angebot schreiben', 'due_date' => today()->addDay()]);

        $this->user->notify(new TaskDueTomorrow($task));

        $this->assertSame(1, $this->user->notifications()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && $request->hasHeader('Authorization')
            && $request->header('Content-Encoding')[0] === 'aes128gcm'
            && $request->header('TTL')[0] === '86400');
    }

    public function test_nothing_is_pushed_without_a_subscription_or_while_away(): void
    {
        $task = Task::factory()->create(['due_date' => today()->addDay()]);
        $this->user->notify(new TaskDueTomorrow($task));

        $away = User::factory()->absent()->create();
        $this->subscribe($away);
        $away->notify(new TaskDueTomorrow($task));

        Http::assertNothingSent();
        $this->assertSame(1, $away->notifications()->count());
    }

    public function test_the_push_message_carries_the_task_the_inbox_sentence_and_the_link(): void
    {
        $task = Task::factory()->create(['title' => 'Angebot schreiben', 'due_date' => today()->addDay()]);
        $this->user->notify(new TaskDueTomorrow($task));

        $message = (new InboxPush($this->user->notifications()->first()))->toWebPush($this->user)->toArray();

        $this->assertSame('Angebot schreiben', $message['title']);
        $this->assertStringEndsWith(' · '.$task->project->name, $message['body']);
        $this->assertSame(['url' => route('tasks.show', $task, absolute: false)], $message['data']);
        $this->assertSame('task-'.$task->id, $message['tag']);
    }

    public function test_a_test_can_be_sent_from_the_profile_a_few_times_a_minute(): void
    {
        $this->subscribe($this->user);

        $profile = Livewire::test('pages::profile');

        foreach (range(1, 4) as $attempt) {
            $profile->call('sendTestPush');
        }

        Http::assertSentCount(3);
    }

    public function test_a_subscription_the_push_service_no_longer_knows_is_dropped(): void
    {
        $this->pushServiceStatus = 410;
        $this->subscribe($this->user);

        Livewire::test('pages::profile')->call('sendTestPush');

        $this->assertSame(0, $this->user->pushSubscriptions()->count());
    }

    public function test_logging_out_forgets_this_browsers_subscription_and_signing_out_everywhere_all_of_them(): void
    {
        $this->subscribe($this->user);
        $this->subscribe($this->user, 'https://updates.push.services.mozilla.com/wpush/v2/other');

        $this->post(route('logout'), ['push_endpoint' => self::ENDPOINT])->assertRedirect(route('login'));
        $this->assertSame(['https://updates.push.services.mozilla.com/wpush/v2/other'], $this->user->pushSubscriptions()->pluck('endpoint')->all());

        $this->user->signOutEverywhere();
        $this->assertSame(0, $this->user->pushSubscriptions()->count());
    }

    public function test_the_service_worker_shows_pushes_and_opens_their_page(): void
    {
        $script = $this->get('/sw.js')->assertOk()->getContent();

        $this->assertStringContainsString("addEventListener('push'", $script);
        $this->assertStringContainsString('showNotification(', $script);
        $this->assertStringContainsString("addEventListener('notificationclick'", $script);
    }
}
