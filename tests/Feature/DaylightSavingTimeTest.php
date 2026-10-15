<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Enums\RepeatMode;
use App\Enums\RepeatUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDueTomorrow;
use App\Services\DailyDigestService;
use App\Services\ScheduleGuard;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The application runs in Europe/Berlin; the clocks went forward on 2027-03-28 (02:00 to 03:00, a 23-hour day) and
 * back on 2026-10-25 (03:00 to 02:00, a 25-hour day). Nothing may be swallowed or happen twice on those days.
 */
class DaylightSavingTimeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Berlin']);
        date_default_timezone_set('Europe/Berlin');

        $this->anna = User::factory()->admin()->create(['digest_enabled' => true, 'reminders_enabled' => true]);
        $this->actingAs($this->anna);
        $this->project = Project::factory()->create();
        $this->project->setRole($this->anna, ProjectRole::Editor);
    }

    private function task(?string $start, ?string $due, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create(['start_date' => $start, 'due_date' => $due, 'assignee_id' => $this->anna->id] + $attributes);
    }

    private function at(string $moment): Carbon
    {
        return Carbon::parse($moment, 'Europe/Berlin');
    }

    /**
     * @return list<string>
     */
    private function dueDates(iterable $tasks): array
    {
        return collect($tasks)->map(fn (Task $task) => $task->due_date->toDateString())->all();
    }

    public function test_no_daily_command_is_scheduled_in_the_hours_the_clocks_change(): void
    {
        $daily = collect(app(Schedule::class)->events())->reject(fn ($event) => $event->expression === '* * * * *');

        $this->assertGreaterThanOrEqual(4, $daily->count());

        foreach ($daily as $event) {
            [, $hour] = explode(' ', $event->expression);

            $this->assertNotContains((int) $hour, [1, 2], ($event->command ?? $event->description ?? 'event').' runs at '.$event->expression);
        }
    }

    /**
     * How often the scheduler would start the event on a day: it asks every real minute of the local day, in the zone the
     * application runs in.
     */
    private function runsOn(Event $event, string $day): int
    {
        $runs = 0;

        for ($moment = $this->at($day)->utc(), $end = $this->at($day)->addDay()->utc(); $moment < $end; $moment->addMinute()) {
            Carbon::setTestNow($moment->copy());
            $runs += $event->isDue($this->app) && $event->filtersPass($this->app) ? 1 : 0;
        }

        return $runs;
    }

    public function test_every_scheduled_command_runs_exactly_once_on_both_change_days(): void
    {
        // The digest runs on weekdays only and both change days are Sundays; the next day is a Monday
        $events = collect(app(Schedule::class)->events())->reject(fn ($event) => $event->expression === '* * * * *');

        $this->assertGreaterThanOrEqual(5, $events->count());

        foreach ($events as $event) {
            $event->timezone('Europe/Berlin');
            $expected = str_contains($event->command ?? '', 'digest') ? 0 : 1;

            foreach (['2027-03-28', '2026-10-25'] as $day) {
                $this->assertSame($expected, $this->runsOn($event, $day), "{$event->command} on {$day}");
            }

            foreach (['2027-03-29', '2026-10-26'] as $day) {
                $this->assertSame(1, $this->runsOn($event, $day), "{$event->command} on {$day}");
            }
        }
    }

    public function test_a_daily_command_set_into_the_hour_of_the_change_still_runs_once(): void
    {
        $schedule = new Schedule('Europe/Berlin');

        // 02:30 does not exist on 2027-03-28 and happens twice on 2026-10-25; the cron library also takes the first pass
        // of the repeated hour for 03:xx
        foreach (['00:30', '01:30', '02:00', '02:30', '02:59', '03:00', '03:15', '03:30', '04:30'] as $time) {
            $event = $schedule->command('inspire')->dailyAt($time)->when(ScheduleGuard::once("test-{$time}", $time));

            foreach (['2027-03-28', '2026-10-25', '2026-10-26'] as $day) {
                $this->assertSame(1, $this->runsOn($event, $day), "{$time} on {$day}");
            }
        }
    }

    public function test_a_time_in_the_missing_hour_is_moved_to_the_end_of_the_gap_not_lost(): void
    {
        $event = (new Schedule('Europe/Berlin'))->command('inspire')->dailyAt('02:30')->when(ScheduleGuard::once('moved', '02:30'));
        $ran = [];

        for ($moment = $this->at('2027-03-28')->utc(), $end = $this->at('2027-03-28')->addDay()->utc(); $moment < $end; $moment->addMinute()) {
            Carbon::setTestNow($moment->copy());

            if ($event->isDue($this->app) && $event->filtersPass($this->app)) {
                $ran[] = $moment->copy()->setTimezone('Europe/Berlin')->format('H:i');
            }
        }

        $this->assertSame(['03:30'], $ran);
    }

    public function test_reminders_find_the_tasks_due_tomorrow_on_the_days_the_clocks_change(): void
    {
        Notification::fake();
        $cases = [
            // Run at 08:00 on the day before, on the day itself, and late in the evening
            ['2027-03-27 08:00', '2027-03-28'],
            ['2027-03-28 08:00', '2027-03-29'],
            ['2027-03-28 23:30', '2027-03-29'],
            ['2026-10-24 08:00', '2026-10-25'],
            ['2026-10-25 08:00', '2026-10-26'],
            ['2026-10-25 23:30', '2026-10-26'],
        ];

        foreach ($cases as [$moment, $due]) {
            Task::query()->delete();
            Cache::flush();
            $this->travelTo($this->at($moment));
            $this->task(null, $due);
            $this->task(null, Carbon::parse($due)->addDay()->toDateString());
            $this->task(null, Carbon::parse($due)->subDay()->toDateString());

            $this->artisan('reminders:send')->expectsOutputToContain("tasks due on {$due}")->assertSuccessful();
        }

        Notification::assertSentToTimes($this->anna, TaskDueTomorrow::class, count($cases));
    }

    public function test_the_digest_sorts_tasks_correctly_around_the_change_days(): void
    {
        $service = app(DailyDigestService::class);
        $digest = fn (string $moment) => collect($service->tasksFor($this->anna, $this->at($moment)))->map(fn (iterable $tasks) => $this->dueDates($tasks))->all();

        // Friday before the spring change: the three days ahead reach Monday, over the changed Sunday
        $this->task(null, '2027-03-28');
        $this->task(null, '2027-03-29');
        $this->task(null, '2027-03-30');
        $this->task(null, '2027-03-26');
        $this->assertSame(['overdue' => [], 'today' => ['2027-03-26'], 'upcoming' => ['2027-03-28', '2027-03-29']], $digest('2027-03-26 07:30'));
        $this->assertSame(['overdue' => ['2027-03-26', '2027-03-28'], 'today' => ['2027-03-29'], 'upcoming' => ['2027-03-30']], $digest('2027-03-29 07:30'));

        Task::query()->delete();
        $this->task(null, '2026-10-25');
        $this->task(null, '2026-10-26');
        $this->task(null, '2026-10-27');
        $this->task(null, '2026-10-23');
        $this->assertSame(['overdue' => [], 'today' => ['2026-10-23'], 'upcoming' => ['2026-10-25', '2026-10-26']], $digest('2026-10-23 07:30'));
        $this->assertSame(['overdue' => ['2026-10-23', '2026-10-25'], 'today' => ['2026-10-26'], 'upcoming' => ['2026-10-27']], $digest('2026-10-26 07:30'));
    }

    public function test_overdue_and_due_soon_follow_the_calendar_day_not_the_hours(): void
    {
        $dueToday = $this->task(null, '2027-03-28');
        $dueYesterday = $this->task(null, '2027-03-27');

        foreach (['2027-03-28 00:00', '2027-03-28 03:00', '2027-03-28 23:59'] as $moment) {
            $this->travelTo($this->at($moment));

            $this->assertSame([$dueYesterday->id], Task::overdue()->pluck('id')->all(), $moment);
            $this->assertContains($dueToday->id, Task::dueSoon()->pluck('id')->all(), $moment);
        }
    }

    public function test_the_calendar_has_complete_weeks_and_no_day_twice_around_the_change_days(): void
    {
        foreach (['2027-03', '2027-04', '2026-10', '2026-11'] as $month) {
            $weeks = Livewire::test('pages::projects.calendar', ['project' => $this->project])->set('month', $month)->instance()->weeks;
            $days = collect($weeks)->flatten(1)->map(fn (array $day) => $day['date']->toDateString());

            $this->assertTrue(collect($weeks)->every(fn (array $week) => count($week) === 7), $month);
            $this->assertSame($days->unique()->count(), $days->count(), $month);
            $this->assertSame(0, Carbon::parse($days->first())->dayOfWeekIso - 1, $month);
            $this->assertSame(Carbon::parse($days->first())->addDays($days->count() - 1)->toDateString(), $days->last(), $month);
        }
    }

    public function test_the_calendar_shows_a_task_on_the_changed_day_once(): void
    {
        $this->task('2027-03-27', '2027-03-29', ['title' => 'Umstellung']);

        $days = collect(Livewire::test('pages::projects.calendar', ['project' => $this->project])->set('month', '2027-03')->instance()->weeks)
            ->flatten(1)->filter(fn (array $day) => $day['tasks']->isNotEmpty())->map(fn (array $day) => $day['date']->toDateString())->values()->all();

        $this->assertSame(['2027-03-27', '2027-03-28', '2027-03-29'], $days);
    }

    public function test_dragging_a_task_over_the_changed_weekend_moves_it_by_whole_days(): void
    {
        $spring = $this->task('2027-03-24', '2027-03-26');
        $autumn = $this->task('2026-10-21', '2026-10-23');

        Livewire::test('pages::projects.calendar', ['project' => $this->project])
            ->call('moveToDay', $spring->id, '2027-03-26', '2027-04-02')
            ->call('moveToDay', $autumn->id, '2026-10-23', '2026-10-30')
            ->call('moveToDay', $autumn->id, '2026-10-30', '2026-10-23');

        $this->assertSame(['2027-03-31', '2027-04-02'], [$spring->fresh()->start_date->toDateString(), $spring->fresh()->due_date->toDateString()]);
        $this->assertSame(['2026-10-21', '2026-10-23'], [$autumn->fresh()->start_date->toDateString(), $autumn->fresh()->due_date->toDateString()]);
    }

    public function test_the_timeline_places_bars_in_the_right_columns_over_the_change_days(): void
    {
        $spring = $this->task('2027-03-26', '2027-03-30', ['title' => 'Frühling']);
        $autumn = $this->task('2026-10-24', '2026-10-27', ['title' => 'Herbst']);

        // The window of six weeks starts on Monday 2027-03-22 and on Monday 2026-10-19
        $rows = Livewire::test('pages::projects.timeline', ['project' => $this->project])->set('from', '2027-03-22')->instance()->rows;
        $row = $rows->firstWhere(fn ($row) => $row['task']->is($spring));
        $this->assertSame([5, 9], [$row['first'], $row['last']]);

        $rows = Livewire::test('pages::projects.timeline', ['project' => $this->project])->set('from', '2026-10-19')->instance()->rows;
        $row = $rows->firstWhere(fn ($row) => $row['task']->is($autumn));
        $this->assertSame([6, 9], [$row['first'], $row['last']]);

        // A task in the last week of the window, behind the change day
        $late = $this->task(null, '2027-05-02');
        $rows = Livewire::test('pages::projects.timeline', ['project' => $this->project])->set('from', '2027-03-22')->instance()->rows;
        $this->assertSame([42, 42], [$rows->firstWhere(fn ($row) => $row['task']->is($late))['first'], $rows->firstWhere(fn ($row) => $row['task']->is($late))['last']]);
    }

    public function test_the_timeline_marks_today_in_the_right_column_on_the_change_days(): void
    {
        // The line sits 2 rem per day to the right of the first day, plus 1 rem for the middle of the day
        foreach ([['2027-03-28 12:00', '2027-03-22', 13], ['2027-03-29 00:30', '2027-03-22', 15], ['2026-10-25 12:00', '2026-10-19', 13], ['2026-10-26 00:30', '2026-10-19', 15]] as [$moment, $from, $offset]) {
            $this->travelTo($this->at($moment));
            $this->task($from, Carbon::parse($from)->addDays(10)->toDateString());

            Livewire::test('pages::projects.timeline', ['project' => $this->project])->set('from', $from)
                ->assertSeeHtml("inset-inline-start: {$offset}rem");
        }
    }

    public function test_the_statistics_count_whole_days_in_a_status_over_the_change_days(): void
    {
        $inProgress = $this->project->statuses()->where('name', 'In Arbeit')->value('id');

        foreach ([['2027-03-20 10:00', '2027-04-03 10:00'], ['2026-10-17 10:00', '2026-10-31 10:00']] as [$since, $now]) {
            Task::query()->delete();
            $this->travelTo($this->at($since));
            $this->task(null, null, ['title' => 'Haengt'])->update(['status_id' => $inProgress]);
            $this->travelTo($this->at($now));

            // Exactly fourteen days on the calendar, although the real time is an hour short (spring) or long (autumn)
            Livewire::test('pages::projects.statistics', ['project' => $this->project])->assertSeeInOrder(['Haengt', '14 Tage']);
        }
    }

    public function test_the_next_task_of_a_series_keeps_the_distance_of_its_dates_over_a_change_day(): void
    {
        // Monthly rules from the end of February and from the end of September reach over the spring and autumn change
        $cases = [
            ['2027-03-01', '2027-02-20', '2027-02-28', '2027-03-20', '2027-03-28', '2027-03-27'],
            ['2026-10-01', '2026-09-20', '2026-09-28', '2026-10-20', '2026-10-28', '2026-10-27'],
        ];

        foreach ($cases as [$today, $start, $due, $nextStart, $nextDue, $childDue]) {
            Task::query()->delete();
            $this->travelTo($this->at($today));
            $task = $this->task($start, $due, ['repeat_unit' => RepeatUnit::Month, 'repeat_interval' => 1, 'repeat_mode' => RepeatMode::Schedule]);
            Task::factory()->for($this->project)->create(['parent_id' => $task->id, 'start_date' => $start, 'due_date' => Carbon::parse($due)->subDay()]);

            $task->toggleDone();

            $next = Task::where('id', '!=', $task->id)->whereNull('parent_id')->firstOrFail();
            $this->assertSame([$nextStart, $nextDue], [$next->start_date->toDateString(), $next->due_date->toDateString()], $due);
            $this->assertSame('00:00', $next->due_date->format('H:i'));
            $this->assertSame($childDue, $next->children()->firstOrFail()->due_date->toDateString());
        }
    }

    public function test_day_and_week_rules_move_by_whole_calendar_days_over_a_change_day(): void
    {
        $cases = [
            [RepeatUnit::Day, 1, '2027-03-27', '2027-03-28'],
            [RepeatUnit::Day, 1, '2027-03-28', '2027-03-29'],
            [RepeatUnit::Day, 1, '2026-10-24', '2026-10-25'],
            [RepeatUnit::Day, 1, '2026-10-25', '2026-10-26'],
            [RepeatUnit::Week, 1, '2027-03-26', '2027-04-02'],
            [RepeatUnit::Week, 2, '2026-10-16', '2026-10-30'],
        ];

        foreach ($cases as [$unit, $interval, $from, $expected]) {
            $date = $unit->addTo($this->at($from), $interval);

            $this->assertSame($expected.' 00:00', $date->format('Y-m-d H:i'), "{$unit->value} {$from}");
        }
    }

    public function test_quiet_times_over_the_night_the_clocks_go_forward(): void
    {
        $user = User::factory()->make(['quiet_from' => '22:00', 'quiet_until' => '07:00']);

        // Real instants (UTC): 23:00 CET on the 27th is 22:00 UTC, the night is only nine hours long
        $this->assertFalse($user->isQuietAt(Carbon::parse('2027-03-27 20:59', 'UTC')));
        $this->assertTrue($user->isQuietAt(Carbon::parse('2027-03-27 21:00', 'UTC')));
        $this->assertTrue($user->isQuietAt(Carbon::parse('2027-03-28 00:59', 'UTC')), '01:59 CET');
        $this->assertTrue($user->isQuietAt(Carbon::parse('2027-03-28 01:00', 'UTC')), '03:00 CEST, right after the gap');
        $this->assertTrue($user->isQuietAt(Carbon::parse('2027-03-28 04:59', 'UTC')), '06:59 CEST');
        $this->assertFalse($user->isQuietAt(Carbon::parse('2027-03-28 05:00', 'UTC')), '07:00 CEST');
    }

    public function test_quiet_times_over_the_night_the_clocks_go_back(): void
    {
        $user = User::factory()->make(['quiet_from' => '22:00', 'quiet_until' => '07:00']);

        $this->assertFalse($user->isQuietAt(Carbon::parse('2026-10-24 19:59', 'UTC')), '21:59 CEST');
        $this->assertTrue($user->isQuietAt(Carbon::parse('2026-10-24 20:00', 'UTC')), '22:00 CEST');
        $this->assertTrue($user->isQuietAt(Carbon::parse('2026-10-25 00:30', 'UTC')), '02:30 CEST, first time');
        $this->assertTrue($user->isQuietAt(Carbon::parse('2026-10-25 01:30', 'UTC')), '02:30 CET, second time');
        $this->assertTrue($user->isQuietAt(Carbon::parse('2026-10-25 05:59', 'UTC')), '06:59 CET');
        $this->assertFalse($user->isQuietAt(Carbon::parse('2026-10-25 06:00', 'UTC')), '07:00 CET');
    }

    public function test_a_quiet_time_inside_the_missing_or_repeated_hour_is_neither_lost_nor_doubled(): void
    {
        $inGap = User::factory()->make(['quiet_from' => '02:15', 'quiet_until' => '02:45']);
        $spanning = User::factory()->make(['quiet_from' => '02:30', 'quiet_until' => '04:00']);

        // 02:15 to 02:45 does not exist on 2027-03-28: no instant is quiet, and 03:00 right after the gap is not either
        $quietInstants = 0;

        for ($moment = $this->at('2027-03-28')->utc(), $end = $this->at('2027-03-28')->addDay()->utc(); $moment < $end; $moment->addMinute()) {
            $quietInstants += $inGap->isQuietAt($moment) ? 1 : 0;
        }

        $this->assertSame(0, $quietInstants);

        // A window that starts in the gap starts right after it
        $this->assertFalse($spanning->isQuietAt(Carbon::parse('2027-03-28 00:59', 'UTC')), '01:59 CET');
        $this->assertTrue($spanning->isQuietAt(Carbon::parse('2027-03-28 01:00', 'UTC')), '03:00 CEST');
        $this->assertFalse($spanning->isQuietAt(Carbon::parse('2027-03-28 02:00', 'UTC')), '04:00 CEST');

        // In autumn 02:15 to 02:45 is quiet both times it happens
        $this->assertTrue($inGap->isQuietAt(Carbon::parse('2026-10-25 00:30', 'UTC')));
        $this->assertTrue($inGap->isQuietAt(Carbon::parse('2026-10-25 01:30', 'UTC')));
        $this->assertFalse($inGap->isQuietAt(Carbon::parse('2026-10-25 01:45', 'UTC')));
    }

    public function test_an_absence_starts_and_ends_by_the_calendar_of_the_person_on_their_change_days(): void
    {
        // New York changes on 2027-03-14 (02:00 to 03:00) and on 2026-11-01 (02:00 to 01:00)
        $user = User::factory()->make(['timezone' => 'America/New_York', 'absent_from' => '2027-03-14', 'absent_until' => '2027-03-14']);

        $this->travelTo(Carbon::parse('2027-03-14 04:59', 'UTC'));
        $this->assertFalse($user->isAbsent(), '23:59 EST on the 13th');
        $this->travelTo(Carbon::parse('2027-03-14 05:00', 'UTC'));
        $this->assertTrue($user->isAbsent(), '00:00 EST');
        $this->travelTo(Carbon::parse('2027-03-15 03:59', 'UTC'));
        $this->assertTrue($user->isAbsent(), '23:59 EDT');
        $this->travelTo(Carbon::parse('2027-03-15 04:00', 'UTC'));
        $this->assertFalse($user->isAbsent(), '00:00 EDT on the 15th');

        $user = User::factory()->make(['timezone' => 'America/New_York', 'absent_from' => '2026-11-01', 'absent_until' => '2026-11-01']);

        $this->travelTo(Carbon::parse('2026-11-01 03:59', 'UTC'));
        $this->assertFalse($user->isAbsent(), '23:59 EDT on the 31st');
        $this->travelTo(Carbon::parse('2026-11-01 04:00', 'UTC'));
        $this->assertTrue($user->isAbsent(), '00:00 EDT');
        $this->travelTo(Carbon::parse('2026-11-02 04:59', 'UTC'));
        $this->assertTrue($user->isAbsent(), '23:59 EST');
        $this->travelTo(Carbon::parse('2026-11-02 05:00', 'UTC'));
        $this->assertFalse($user->isAbsent(), '00:00 EST on the 2nd');
    }
}
