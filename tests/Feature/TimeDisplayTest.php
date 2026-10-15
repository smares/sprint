<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Services\DailyDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Points in time (comments, history, attachments, token expiry, archiving) appear on the clock of the person looking;
 * calendar days (due date) never move. The application itself runs in UTC here, so stored values are unambiguous.
 */
class TimeDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        $this->project = Project::factory()->create();
        $this->task = Task::factory()->for($this->project)->create(['due_date' => '2026-10-20']);
    }

    private function viewer(?string $timezone, ProjectRole $role = ProjectRole::Editor): User
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        $this->project->setRole($user, $role);

        return $user;
    }

    private function utc(string $moment): Carbon
    {
        return Carbon::parse($moment, 'UTC');
    }

    private function commentAt(string $moment, string $body = 'Hallo'): Comment
    {
        return Comment::factory()->create(['task_id' => $this->task->id, 'body' => $body, 'created_at' => $moment, 'updated_at' => $moment]);
    }

    private function taskPage()
    {
        return Livewire::test('pages::tasks.show', ['task' => $this->task]);
    }

    public function test_a_moment_is_shown_on_the_persons_clock_without_changing_it(): void
    {
        $moment = $this->utc('2026-10-14 21:30:00');
        $auckland = User::factory()->make(['timezone' => 'Pacific/Auckland']);
        $inApplicationZone = User::factory()->make(['timezone' => null]);

        $local = $auckland->inTimezone($moment);

        $this->assertSame('2026-10-15 10:30', $local->format('Y-m-d H:i'));
        $this->assertTrue($local->equalTo($moment));
        $this->assertSame('UTC', $moment->getTimezone()->getName(), 'the original moment is not touched');
        $this->assertSame('2026-10-14 21:30', $inApplicationZone->inTimezone($moment)->format('Y-m-d H:i'));
    }

    public function test_the_hour_that_summer_time_skips_never_shows_up(): void
    {
        $berlin = User::factory()->make(['timezone' => 'Europe/Berlin']);

        $last = $berlin->inTimezone($this->utc('2027-03-28 00:59:59'));
        $first = $berlin->inTimezone($this->utc('2027-03-28 01:00:00'));

        $this->assertSame('2027-03-28 01:59:59', $last->format('Y-m-d H:i:s'));
        $this->assertSame('2027-03-28 03:00:00', $first->format('Y-m-d H:i:s'));
        $this->assertSame('CEST', $first->format('T'));

        $shown = collect(range(0, 47))
            ->map(fn (int $halfHour) => $berlin->inTimezone($this->utc('2027-03-27 23:00:00')->addMinutes(30 * $halfHour)))
            ->filter(fn ($moment) => $moment->format('Y-m-d') === '2027-03-28')
            ->map->isoFormat('L LT');

        $this->assertCount(46, $shown->unique(), 'a day of 23 hours, no time twice');
        $this->assertNotContains('2027-03-28 02:30', $shown->all());
    }

    public function test_the_hour_that_winter_time_repeats_shows_twice_and_stays_apart(): void
    {
        $berlin = User::factory()->make(['timezone' => 'Europe/Berlin']);

        $first = $berlin->inTimezone($this->utc('2026-10-25 00:30:00'));
        $second = $berlin->inTimezone($this->utc('2026-10-25 01:30:00'));

        $this->assertSame('2026-10-25 02:30', $first->isoFormat('L LT'));
        $this->assertSame('2026-10-25 02:30', $second->isoFormat('L LT'));
        $this->assertSame('+02:00', $first->format('P'));
        $this->assertSame('+01:00', $second->format('P'));
        $this->assertSame('CEST', $first->format('T'));
        $this->assertSame('CET', $second->format('T'));
        $this->assertTrue($first->lt($second));
        $this->assertSame(3600, (int) $first->diffInSeconds($second));
    }

    public function test_comments_and_history_follow_the_viewers_time_zone_across_the_clock_change(): void
    {
        $this->commentAt('2026-10-25 00:30:00', 'Erste');
        $this->commentAt('2026-10-25 01:30:00', 'Zweite');
        TaskActivity::factory()->create(['task_id' => $this->task->id, 'user_id' => null, 'created_at' => '2026-10-25 00:45:00']);

        $this->actingAs($this->viewer('Europe/Berlin'));

        $this->taskPage()
            ->assertSeeHtml('datetime="2026-10-25T02:30:00+02:00"')
            ->assertSeeHtml('datetime="2026-10-25T02:30:00+01:00"')
            ->assertSeeHtml('datetime="2026-10-25T02:45:00+02:00"')
            ->assertSeeHtml('title="Europe/Berlin"')
            ->assertSee('2026-10-25 02:45');
    }

    public function test_the_history_stays_in_the_order_of_the_moments_not_of_the_clock(): void
    {
        $this->commentAt('2026-10-25 00:30:00', 'Erste');
        $this->commentAt('2026-10-25 01:30:00', 'Zweite');
        TaskActivity::factory()->create(['task_id' => $this->task->id, 'user_id' => null, 'created_at' => '2026-10-25 01:00:00']);

        $this->actingAs($this->viewer('Europe/Berlin'));
        $entries = $this->taskPage()->instance()->activityFeed;

        $this->assertSame(
            ['2026-10-25 00:30:00', '2026-10-25 01:00:00', '2026-10-25 01:30:00'],
            $entries->map(fn (array $entry) => $entry['at']->copy()->utc()->format('Y-m-d H:i:s'))->take(-3)->values()->all(),
        );
    }

    public function test_the_day_changes_where_the_person_is(): void
    {
        $this->commentAt('2026-10-14 23:30:00');

        $this->actingAs($this->viewer('Pacific/Auckland'));

        $this->taskPage()->assertSee('2026-10-15 12:30')->assertDontSee('2026-10-14 23:30')->assertSeeHtml('title="Pacific/Auckland"');
    }

    public function test_without_a_zone_of_their_own_people_see_the_application_clock_without_a_hint(): void
    {
        $this->commentAt('2026-10-14 23:30:00');

        $this->actingAs($this->viewer(null));
        $page = $this->taskPage()->assertSee('2026-10-14 23:30');

        $this->assertMatchesRegularExpression('/<time datetime="2026-10-14T23:30:00\+00:00"\s*>/', $page->html());
    }

    public function test_the_same_comment_reads_differently_for_two_people(): void
    {
        $this->commentAt('2026-10-14 23:30:00');

        $this->actingAs($this->viewer('America/New_York'));
        $this->taskPage()->assertSee('2026-10-14 19:30');

        $this->actingAs($this->viewer('Asia/Tokyo'));
        $this->taskPage()->assertSee('2026-10-15 08:30');
    }

    public function test_the_upload_time_of_an_attachment_follows_the_viewer(): void
    {
        Attachment::factory()->create(['task_id' => $this->task->id, 'created_at' => '2026-10-14 23:30:00']);

        $this->actingAs($this->viewer('Pacific/Auckland'));

        $this->taskPage()->assertSee('2026-10-15 12:30');
    }

    public function test_a_due_date_never_moves_whatever_zone_the_person_is_in(): void
    {
        $this->task->update(['start_date' => '2026-10-19']);

        foreach (['Pacific/Auckland', 'Pacific/Pago_Pago', 'Europe/Berlin', null] as $zone) {
            $this->actingAs($this->viewer($zone));

            $this->taskPage()->assertSet('dueDate', '2026-10-20')->assertSet('startDate', '2026-10-19');
            Livewire::test('pages::projects.show', ['project' => $this->project])
                ->assertSee('2026-10-20')
                ->assertDontSee('2026-10-21');
        }

        $this->assertSame('2026-10-20', $this->task->fresh()->due_date->toDateString());
    }

    public function test_the_expiry_of_a_token_is_a_day_where_the_person_is(): void
    {
        $user = $this->viewer('Pacific/Auckland');
        $user->createToken('Laptop', ['*'], $this->utc('2099-06-20 23:30:00'));
        $this->actingAs($user);
        session(['auth.password_confirmed_at' => time()]);

        Livewire::test('api-tokens')->assertSee('2099-06-21')->assertDontSee('2099-06-20');
    }

    public function test_the_day_a_project_was_archived_is_the_day_where_the_person_is(): void
    {
        $this->project->update(['archived_at' => '2026-10-14 23:30:00']);

        $this->actingAs($this->viewer('Pacific/Auckland'));

        Livewire::test('pages::projects.index')->assertSee('2026-10-15')->assertDontSee('2026-10-14');
    }

    public function test_the_time_until_which_a_password_stays_confirmed_follows_the_person(): void
    {
        $confirmedAt = time() - 600;
        $until = Carbon::createFromTimestampUTC($confirmedAt + (int) config('auth.password_timeout', 10800));
        $this->actingAs($this->viewer('Asia/Tokyo'));
        session(['auth.password_confirmed_at' => $confirmedAt]);

        Livewire::test('security')
            ->assertSee($until->copy()->setTimezone('Asia/Tokyo')->format('H:i'))
            ->assertDontSee('bis '.$until->format('H:i').' Uhr');
    }

    public function test_a_due_date_in_the_digest_does_not_move_for_the_recipient(): void
    {
        $recipient = $this->viewer('Pacific/Auckland');
        $this->task->update(['assignee_id' => $recipient->id]);

        $tasks = app(DailyDigestService::class)->tasksFor($recipient, $this->utc('2026-10-20 08:00:00'));

        $this->assertSame('2026-10-20', $tasks['today']->first()?->due_date->toDateString());
    }
}
