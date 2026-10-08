<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarAndTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 10:00:00');
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create(['name' => 'Zeitplan']);
    }

    private function task(string $title, ?string $start, ?string $due, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create(['title' => $title, 'start_date' => $start, 'due_date' => $due] + $attributes);
    }

    public function test_start_date_is_saved_on_the_task_page_and_logged(): void
    {
        $task = $this->task('Plan', null, '2026-10-20');

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('startDate', '2026-10-10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-10-10', $task->fresh()->start_date->toDateString());
        $this->assertContains('hat den Beginn von – auf 2026-10-10 geändert', $task->activities()->get()->map->sentence()->all());
    }

    public function test_start_date_must_not_be_after_the_due_date(): void
    {
        $task = $this->task('Plan', null, null);

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('dueDate', '2026-10-10')
            ->set('startDate', '2026-10-20')
            ->call('save')
            ->assertHasErrors('startDate');

        $this->assertNull($task->fresh()->start_date);
    }

    public function test_start_date_can_be_set_when_creating_a_task(): void
    {
        Livewire::test('task-create', ['project' => $this->project])
            ->set('title', 'Neu')->set('startDate', '2026-10-01')->set('dueDate', '2026-10-05')
            ->call('create')->assertHasNoErrors();

        $this->assertSame('2026-10-01', Task::where('title', 'Neu')->firstOrFail()->start_date->toDateString());

        Livewire::test('task-create', ['project' => $this->project])
            ->set('title', 'Falsch')->set('startDate', '2026-10-09')->set('dueDate', '2026-10-05')
            ->call('create')->assertHasErrors('startDate');
    }

    public function test_task_span_uses_whatever_dates_exist(): void
    {
        $this->assertSame('2026-10-01', $this->task('A', '2026-10-01', '2026-10-05')->spanStart()->toDateString());
        $this->assertSame('2026-10-05', $this->task('B', '2026-10-01', '2026-10-05')->spanEnd()->toDateString());
        $this->assertSame('2026-10-05', $this->task('C', null, '2026-10-05')->spanStart()->toDateString());
        $this->assertSame('2026-10-01', $this->task('D', '2026-10-01', null)->spanEnd()->toDateString());
        $this->assertNull($this->task('E', null, null)->spanStart());
    }

    public function test_overlap_scope_finds_tasks_touching_a_period(): void
    {
        $this->task('Davor', '2026-09-01', '2026-09-10');
        $this->task('Hinein', '2026-09-28', '2026-10-03');
        $this->task('Mittendrin', '2026-10-10', '2026-10-12');
        $this->task('Darüber', '2026-09-01', '2026-11-30');
        $this->task('Nur Fälligkeit', null, '2026-10-31');
        $this->task('Ohne Datum', null, null);
        $this->task('Danach', '2026-12-01', '2026-12-05');

        $titles = Task::overlapping(now()->parse('2026-10-01'), now()->parse('2026-10-31'))->orderBy('id')->pluck('title')->all();

        $this->assertSame(['Hinein', 'Mittendrin', 'Darüber', 'Nur Fälligkeit'], $titles);
    }

    public function test_calendar_shows_the_current_month_with_tasks_on_their_days(): void
    {
        $this->task('Kurz', null, '2026-10-15');
        $this->task('Mehrtägig', '2026-10-05', '2026-10-07');
        $this->task('Novemberaufgabe', null, '2026-11-20');

        $this->get(route('projects.calendar', $this->project))->assertOk()
            ->assertSee('Oktober 2026')->assertSee('Kurz')->assertSee('Mehrtägig')->assertDontSee('Novemberaufgabe');
    }

    public function test_multi_day_tasks_appear_on_every_day_of_their_span(): void
    {
        $this->task('Mehrtägig', '2026-10-05', '2026-10-07');

        $component = Livewire::test('pages::projects.calendar', ['project' => $this->project])->instance();
        $days = collect($component->weeks)->flatten(1)->filter(fn ($day) => $day['tasks']->isNotEmpty())->map(fn ($day) => $day['date']->toDateString())->values()->all();

        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], $days);
    }

    public function test_calendar_navigates_between_months_and_back_to_today(): void
    {
        $this->task('Im November', null, '2026-11-20');

        Livewire::test('pages::projects.calendar', ['project' => $this->project])
            ->assertDontSee('Im November')
            ->call('nextMonth')->assertSee('November 2026')->assertSee('Im November')
            ->call('previousMonth')->call('previousMonth')->assertSee('September 2026')
            ->call('today')->assertSee('Oktober 2026');
    }

    public function test_calendar_ignores_garbage_in_the_month_parameter(): void
    {
        $this->get(route('projects.calendar', ['project' => $this->project, 'month' => 'nonsense']))->assertOk()->assertSee('Oktober 2026');
        $this->get(route('projects.calendar', ['project' => $this->project, 'month' => '2026-13']))->assertOk()->assertSee('Oktober 2026');
        $this->get(route('projects.calendar', ['project' => $this->project, 'month' => '2026-12']))->assertOk()->assertSee('Dezember 2026');
    }

    public function test_calendar_limits_the_tasks_per_day_and_counts_undated_ones(): void
    {
        foreach (range(1, 5) as $number) {
            $this->task("Aufgabe $number", null, '2026-10-15');
        }
        $this->task('Ohne', null, null);

        $this->get(route('projects.calendar', $this->project))->assertOk()
            ->assertSee('+ 2 weitere')->assertSee('1 Aufgabe hat kein Datum');
    }

    public function test_calendar_and_timeline_only_show_top_level_tasks(): void
    {
        $parent = $this->task('Oben', null, '2026-10-15');
        $this->task('Unten', null, '2026-10-15', ['parent_id' => $parent->id]);
        $this->task('Überschrift', null, '2026-10-15', ['parent_id' => $parent->id, 'is_section' => true]);

        $this->get(route('projects.calendar', $this->project))->assertOk()->assertSee('Oben')->assertDontSee('Unten')->assertDontSee('Überschrift');
        $this->get(route('projects.timeline', $this->project))->assertOk()->assertSee('Oben')->assertDontSee('Unten')->assertDontSee('Überschrift');
    }

    public function test_timeline_places_bars_in_the_right_columns(): void
    {
        $this->task('Balken', '2026-10-07', '2026-10-09');
        $this->task('Ein Tag', null, '2026-10-12');

        $rows = Livewire::test('pages::projects.timeline', ['project' => $this->project])->instance()->rows;

        // The window starts on Monday 2026-10-05.
        $balken = $rows->firstWhere(fn ($row) => $row['task']->title === 'Balken');
        $einTag = $rows->firstWhere(fn ($row) => $row['task']->title === 'Ein Tag');

        $this->assertSame([3, 5], [$balken['first'], $balken['last']]);
        $this->assertSame([8, 8], [$einTag['first'], $einTag['last']]);
    }

    public function test_timeline_clips_tasks_that_start_or_end_outside_the_window(): void
    {
        $this->task('Lang', '2026-09-01', '2026-12-31');

        $row = Livewire::test('pages::projects.timeline', ['project' => $this->project])->instance()->rows->first();

        $this->assertSame([1, 42], [$row['first'], $row['last']]);
    }

    public function test_timeline_lists_tasks_in_start_order_and_skips_tasks_outside_the_window(): void
    {
        $this->task('Später', '2026-10-20', '2026-10-22');
        $this->task('Früher', '2026-10-06', '2026-10-08');
        $this->task('Weit weg', '2027-03-01', '2027-03-05');
        $this->task('Ohne', null, null);

        Livewire::test('pages::projects.timeline', ['project' => $this->project])
            ->assertSeeInOrder(['Früher', 'Später'])
            ->assertDontSee('Weit weg')
            ->assertSee('1 Aufgabe liegt außerhalb dieser Wochen')
            ->assertSee('1 Aufgabe hat kein Datum');
    }

    public function test_timeline_navigates_by_two_weeks(): void
    {
        $this->task('Im Dezember', '2026-12-01', '2026-12-03');

        $component = Livewire::test('pages::projects.timeline', ['project' => $this->project])
            ->call('later')->assertSet('from', '2026-10-19')
            ->call('earlier')->call('earlier')->assertSet('from', '2026-09-21')
            ->call('today')->assertSet('from', '2026-10-05');

        $this->get(route('projects.timeline', ['project' => $this->project, 'from' => '2026-11-30']))->assertOk()->assertSee('Im Dezember');
        $this->get(route('projects.timeline', ['project' => $this->project, 'from' => 'quatsch']))->assertOk();
        $this->assertNotNull($component);
    }

    public function test_timeline_marks_blocked_tasks_and_shows_the_status_color(): void
    {
        $blocked = $this->task('Wartet', '2026-10-07', '2026-10-09');
        $blocked->blockers()->attach($this->task('Blocker', '2026-10-05', '2026-10-06'));

        $this->get(route('projects.timeline', $this->project))->assertOk()->assertSee('Blockiert');
    }

    public function test_the_view_switcher_links_to_the_other_three_views(): void
    {
        $routes = ['projects.show', 'projects.board', 'projects.calendar', 'projects.timeline'];

        foreach ($routes as $current) {
            $response = $this->get(route($current, $this->project))->assertOk()
                ->assertSee('Liste')->assertSee('Board')->assertSee('Kalender')->assertSee('Zeitleiste');

            foreach (array_diff($routes, [$current]) as $other) {
                $response->assertSee('href="'.route($other, $this->project).'"', false);
            }

            $response->assertDontSee('href="'.route($current, $this->project).'"', false);
        }
    }

    public function test_people_without_access_cannot_open_the_new_views(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('projects.calendar', $this->project))->assertForbidden();
        $this->actingAs($outsider)->get(route('projects.timeline', $this->project))->assertForbidden();

        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer)->get(route('projects.calendar', $this->project))->assertOk();
        $this->actingAs($viewer)->get(route('projects.timeline', $this->project))->assertOk();
    }
}
