<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Notifications\TaskCommented;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DoNotDisturbTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $moment): Carbon
    {
        return Carbon::parse($moment, config('app.timezone'));
    }

    public function test_a_quiet_time_can_span_midnight(): void
    {
        $user = User::factory()->make(['quiet_from' => '18:30', 'quiet_until' => '08:00']);

        $this->assertTrue($user->isQuietAt($this->at('2026-10-14 18:30')));
        $this->assertTrue($user->isQuietAt($this->at('2026-10-14 23:59')));
        $this->assertTrue($user->isQuietAt($this->at('2026-10-15 07:59')));
        $this->assertFalse($user->isQuietAt($this->at('2026-10-15 08:00')));
        $this->assertFalse($user->isQuietAt($this->at('2026-10-15 18:29')));
    }

    public function test_a_quiet_time_within_one_day(): void
    {
        $user = User::factory()->make(['quiet_from' => '12:00', 'quiet_until' => '13:30']);

        $this->assertTrue($user->isQuietAt($this->at('2026-10-14 12:00')));
        $this->assertTrue($user->isQuietAt($this->at('2026-10-14 13:29')));
        $this->assertFalse($user->isQuietAt($this->at('2026-10-14 13:30')));
        $this->assertFalse($user->isQuietAt($this->at('2026-10-14 11:59')));
    }

    public function test_quiet_days_are_quiet_all_day(): void
    {
        $user = User::factory()->make(['quiet_days' => [6, 7]]);

        $this->assertTrue($user->isQuietAt($this->at('2026-10-17 00:00')));
        $this->assertTrue($user->isQuietAt($this->at('2026-10-18 23:59')));
        $this->assertFalse($user->isQuietAt($this->at('2026-10-16 12:00')));
        $this->assertFalse($user->isQuietAt($this->at('2026-10-19 00:00')));
        $this->assertFalse(User::factory()->make()->isQuietAt($this->at('2026-10-17 12:00')));
    }

    public function test_the_profile_saves_quiet_times_and_days(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::profile')
            ->set('quietHours', true)->set('quietFrom', '18:30')->set('quietUntil', '08:00')->set('quietDays', ['7', '6'])
            ->call('saveQuietTimes')->assertHasNoErrors()->assertDispatched('toast-show');

        $user->refresh();
        $this->assertSame(['18:30', '08:00', [6, 7]], [$user->quiet_from, $user->quiet_until, $user->quiet_days]);

        Livewire::test('pages::profile')
            ->assertSet('quietHours', true)->assertSet('quietFrom', '18:30')->assertSet('quietDays', ['6', '7'])
            ->set('quietHours', false)->set('quietDays', [])->call('saveQuietTimes');

        $user->refresh();
        $this->assertSame([null, null, null], [$user->quiet_from, $user->quiet_until, $user->quiet_days]);
    }

    public function test_invalid_quiet_times_are_refused(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::profile')->set('quietHours', true)->set('quietFrom', '09:00')->set('quietUntil', '09:00')->call('saveQuietTimes')->assertHasErrors('quietUntil');
        Livewire::test('pages::profile')->set('quietHours', true)->set('quietFrom', '25:00')->call('saveQuietTimes')->assertHasErrors('quietFrom');
        Livewire::test('pages::profile')->set('quietDays', ['8'])->call('saveQuietTimes')->assertHasErrors('quietDays.0');

        $this->assertNull($user->fresh()->quiet_from);
    }

    public function test_during_a_quiet_time_notifications_reach_only_the_inbox(): void
    {
        Notification::fake();
        $this->travelTo($this->at('2026-10-14 22:00'));
        $quiet = User::factory()->admin()->create(['quiet_from' => '18:30', 'quiet_until' => '08:00']);
        $awake = User::factory()->admin()->create(['quiet_from' => '12:00', 'quiet_until' => '13:00']);
        $task = Task::factory()->create(['assignee_id' => $quiet->id]);
        $task->collaborators()->attach($awake);
        $this->actingAs(User::factory()->admin()->create());

        Comment::factory()->for($task)->create(['user_id' => auth()->id(), 'body' => 'Spät noch']);

        Notification::assertSentTo($quiet, TaskCommented::class, fn ($sent, array $channels) => $channels === ['database']);
        Notification::assertSentTo($awake, TaskCommented::class, fn ($sent, array $channels) => $channels === ['mail', 'database']);
    }

    public function test_no_daily_digest_on_a_quiet_day(): void
    {
        Notification::fake();
        $this->travelTo($this->at('2026-10-16 07:00'));
        $quiet = User::factory()->admin()->create(['quiet_days' => [5]]);
        $other = User::factory()->admin()->create();
        foreach ([$quiet, $other] as $user) {
            Task::factory()->create(['assignee_id' => $user->id, 'due_date' => today()->subDay()]);
        }

        $this->artisan('digest:send')->assertSuccessful();

        Notification::assertNotSentTo($quiet, DailyDigest::class);
        Notification::assertSentTo($other, DailyDigest::class);
    }
}
