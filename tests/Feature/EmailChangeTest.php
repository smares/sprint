<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailChanged;
use App\Notifications\VerifyNewEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'geheim123', 'locale' => 'de']);
        $this->actingAs($this->user);
    }

    private function requestChange(string $email = 'neu@example.com'): void
    {
        Livewire::test('pages::profile')->set('email', $email)->set('emailPassword', 'geheim123')->call('saveProfile')->assertHasNoErrors();
    }

    private function confirmationUrl(): string
    {
        $url = null;
        Notification::assertSentTo($this->user, VerifyNewEmail::class, function (VerifyNewEmail $notification, array $channels, User $notifiable) use (&$url): true {
            $url = $notification->toMail($notifiable)->viewData['url'];

            return true;
        });

        return $url;
    }

    public function test_the_link_goes_to_the_new_address_in_the_persons_language_and_nothing_changes_yet(): void
    {
        $this->requestChange();

        Notification::assertSentTo($this->user, VerifyNewEmail::class, function (VerifyNewEmail $notification, array $channels, User $notifiable): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routeNotificationForMail($notification) === 'neu@example.com'
                && str_contains($mail->subject, 'bestätigen')
                && str_contains((string) $mail->render(), 'neu@example.com');
        });

        $this->assertSame('anna@example.com', $this->user->fresh()->email);
        $this->get(route('profile'))->assertSee('Bestätigung von neu@example.com ausstehend');
    }

    public function test_opening_the_link_changes_the_address_and_tells_the_old_one(): void
    {
        $this->requestChange();
        $url = $this->confirmationUrl();

        $this->get($url)->assertRedirect(route('profile'))->assertSessionHas('status');

        $user = $this->user->fresh();
        $this->assertSame('neu@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at);

        Notification::assertSentTo($user, EmailChanged::class, fn (EmailChanged $notification, array $channels, User $notifiable) => $notifiable->routeNotificationForMail($notification) === 'anna@example.com');
    }

    public function test_the_link_also_works_without_being_signed_in(): void
    {
        $this->requestChange();
        $url = $this->confirmationUrl();

        auth()->logout();

        $this->get($url)->assertRedirect(route('login'));
        $this->assertSame('neu@example.com', $this->user->fresh()->email);
        $this->get(route('login'))->assertSee('Deine E-Mail-Adresse ist jetzt neu@example.com.');
    }

    public function test_tampered_expired_and_outdated_links_do_nothing(): void
    {
        $this->requestChange();
        $url = $this->confirmationUrl();

        $this->get($url.'x')->assertForbidden();

        $this->travel(VerifyNewEmail::VALID_MINUTES + 1)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();

        // A second change makes the first link useless
        $this->requestChange('anders@example.com');
        $this->get($url)->assertRedirect(route('profile'))->assertSessionHas('warning');

        $this->assertSame('anna@example.com', $this->user->fresh()->email);
        $this->assertSame('anders@example.com', $this->user->fresh()->pending_email);
    }

    public function test_an_address_taken_in_the_meantime_is_not_used(): void
    {
        $this->requestChange();
        $url = $this->confirmationUrl();
        User::factory()->create(['email' => 'neu@example.com']);

        $this->get($url)->assertSessionHas('warning');

        $this->assertSame('anna@example.com', $this->user->fresh()->email);
        $this->assertNull($this->user->fresh()->pending_email);
    }

    public function test_the_change_can_be_cancelled_and_the_link_sent_again_a_few_times(): void
    {
        $this->requestChange();

        $page = Livewire::test('pages::profile');
        $page->call('resendEmailConfirmation')->call('resendEmailConfirmation')->call('resendEmailConfirmation');
        Notification::assertSentToTimes($this->user, VerifyNewEmail::class, 3);

        $page->call('cancelEmailChange');
        $this->assertNull($this->user->fresh()->pending_email);
        $this->get(route('profile'))->assertDontSee('ausstehend');
    }

    public function test_other_signed_links_cannot_be_reused_for_another_person(): void
    {
        $other = User::factory()->create(['email' => 'bernd@example.com']);
        $other->forceFill(['pending_email' => 'bernd-neu@example.com'])->save();

        $url = URL::temporarySignedRoute('email.confirm', now()->addHour(), ['user' => $other->id, 'hash' => sha1('neu@example.com')]);

        $this->get($url)->assertSessionHas('warning');
        $this->assertSame('bernd@example.com', $other->fresh()->email);
    }

    public function test_changing_the_address_again_and_again_sends_at_most_three_mails(): void
    {
        foreach (['eins@example.com', 'zwei@example.com', 'drei@example.com'] as $email) {
            $this->requestChange($email);
        }

        Livewire::test('pages::profile')->set('email', 'vier@example.com')->set('emailPassword', 'geheim123')
            ->call('saveProfile')->assertHasErrors('email');

        Notification::assertSentToTimes($this->user, VerifyNewEmail::class, 3);
        $this->assertSame('drei@example.com', $this->user->fresh()->pending_email);
    }
}
