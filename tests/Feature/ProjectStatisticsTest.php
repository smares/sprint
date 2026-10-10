<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday; its week starts on Monday 2026-10-05
        $this->travelTo('2026-10-08 10:00:00');
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->user, ProjectRole::Viewer);
        $this->actingAs($this->user);
    }

    private function inProgress(): int
    {
        return $this->project->statuses()->where('name', 'In Arbeit')->value('id');
    }

    public function test_everyone_who_sees_the_project_gets_the_statistics_from_the_view_switch(): void
    {
        $this->get(route('projects.show', $this->project))->assertSee(route('projects.statistics', $this->project), false);
        $this->get(route('projects.statistics', $this->project))->assertOk()
            ->assertSee('Angelegt und erledigt pro Woche')
            ->assertSee('Hängengebliebene Aufgaben');

        $this->actingAs(User::factory()->create())->get(route('projects.statistics', $this->project))->assertForbidden();
    }

    public function test_the_weekly_flow_counts_created_and_completed_tasks_per_week_without_sections(): void
    {
        $this->travelTo('2026-09-29 09:00');
        $early = Task::factory()->for($this->project)->create();
        Task::factory()->for($this->project)->create();
        Task::factory()->for($this->project)->create(['is_section' => true]);

        $this->travelTo('2026-10-06 09:00');
        Task::factory()->for($this->project)->create();
        $early->update(['status_id' => $this->project->doneStatus()->id]);
        $this->travelTo('2026-10-08 10:00:00');

        $flow = app(ProjectStatisticsService::class)->weeklyFlow($this->project, 3);

        $this->assertSame(['KW 39', 'KW 40', 'KW 41'], array_column($flow, 'week'));
        $this->assertSame([0, 2, 1], array_column($flow, 'created'));
        $this->assertSame([0, 0, 1], array_column($flow, 'completed'));
        $this->assertSame('2026-09-28 – 2026-10-04', $flow[1]['range']);
    }

    public function test_stuck_tasks_are_the_open_ones_longest_in_their_status_outside_the_backlog(): void
    {
        $this->travelTo('2026-09-01 09:00');
        $old = Task::factory()->for($this->project)->create(['title' => 'Lange in Arbeit']);
        $old->update(['status_id' => $this->inProgress()]);
        Task::factory()->for($this->project)->create(['title' => 'Nur im Backlog']);

        $this->travelTo('2026-10-01 09:00');
        $recent = Task::factory()->for($this->project)->create(['title' => 'Kurz in Arbeit']);
        $recent->update(['status_id' => $this->inProgress()]);
        Task::factory()->for($this->project)->done()->create(['title' => 'Schon erledigt']);
        $this->travelTo('2026-10-08 10:00:00');

        $stuck = app(ProjectStatisticsService::class)->stuckTasks($this->project, 10);
        $this->assertSame(['Lange in Arbeit', 'Kurz in Arbeit'], $stuck->pluck('title')->all());

        Livewire::test('pages::projects.statistics', ['project' => $this->project])
            ->assertSeeInOrder(['Lange in Arbeit', '37 Tage', 'Kurz in Arbeit', '7 Tage'])
            ->assertDontSee('Nur im Backlog')
            ->assertDontSee('Schon erledigt')
            ->call('openTask', $old->id)->assertSet('openTaskId', (string) $old->id);
    }

    /**
     * A task created at the given time and completed some days later, with an optional due date.
     */
    private function completed(string $createdAt, float $days, ?string $dueDate = null): Task
    {
        $this->travelTo($createdAt);
        $task = Task::factory()->for($this->project)->create(['due_date' => $dueDate]);
        $this->travelTo(now()->addMinutes((int) round($days * 1440)));
        $task->update(['status_id' => $this->project->doneStatus()->id]);
        $this->travelTo('2026-10-08 10:00:00');

        return $task;
    }

    public function test_cycle_time_is_the_median_and_the_85th_percentile_per_month(): void
    {
        foreach ([1, 2, 3, 4, 10] as $days) {
            $this->completed('2026-09-01 08:00', $days);
        }
        $this->completed('2026-10-01 08:00', 0.5, '2026-10-01');
        $this->completed('2026-10-02 08:00', 3, '2026-10-04');
        Task::factory()->for($this->project)->create();

        $months = app(ProjectStatisticsService::class)->monthlyCycle($this->project, 3);

        $this->assertSame(['Aug 2026', 'Sep 2026', 'Okt 2026'], array_column($months, 'month'));
        $this->assertSame([0, 5, 2], array_column($months, 'completed'));
        $this->assertSame([null, 3.0, 0.5], array_column($months, 'median'));
        $this->assertSame([null, 10.0, 3.0], array_column($months, 'p85'));
        $this->assertSame([null, null, 50], array_column($months, 'on_time'));

        $recent = app(ProjectStatisticsService::class)->recentCycle($this->project, 90);
        $this->assertSame(7, $recent['completed']);
        $this->assertSame(3.0, $recent['median']);
        $this->assertSame(2, $recent['dated']);
        $this->assertSame(50, $recent['on_time']);
    }

    public function test_a_task_done_on_its_due_day_is_on_time_and_reopening_takes_it_out(): void
    {
        $task = $this->completed('2026-10-05 08:00', 2.4, '2026-10-07');
        $this->assertSame(100, app(ProjectStatisticsService::class)->recentCycle($this->project, 30)['on_time']);

        $task->update(['status_id' => $this->project->defaultStatus()->id]);
        $recent = app(ProjectStatisticsService::class)->recentCycle($this->project, 30);
        $this->assertSame(0, $recent['completed']);
        $this->assertNull($recent['on_time']);
    }

    public function test_the_page_shows_cycle_time_and_punctuality_in_words(): void
    {
        $this->completed('2026-10-01 08:00', 1.5, '2026-10-01');
        $this->completed('2026-10-01 08:00', 4, '2026-10-10');

        Livewire::test('pages::projects.statistics', ['project' => $this->project])
            ->assertSee('Durchlaufzeit')
            ->assertSeeHtml('data-stat="median">1,5 Tage')
            ->assertSeeHtml('data-stat="p85">4 Tage')
            ->assertSeeHtml('data-stat="on-time">50 %')
            ->assertSee('letzte 90 Tage, 2 Aufgaben mit Fälligkeit');
    }

    public function test_the_line_charts_leave_out_months_without_data_instead_of_drawing_them_as_zero(): void
    {
        $this->completed('2026-10-01 08:00', 2);

        Livewire::test('pages::projects.statistics', ['project' => $this->project])
            ->assertSee('Okt 2026')
            ->assertDontSee('Sep 2026')
            ->assertSee('In den letzten Monaten wurde keine Aufgabe mit Fälligkeit erledigt.');
    }

    public function test_a_project_without_activity_shows_empty_weeks_and_no_stuck_tasks(): void
    {
        Livewire::test('pages::projects.statistics', ['project' => $this->project])
            ->assertSee('Keine offene Aufgabe steht über den ersten Status hinaus.')
            ->assertSeeHtml('data-stat="median">–')
            ->assertSeeHtml('data-stat="on-time">–')
            ->assertSee('In den letzten Monaten wurde keine Aufgabe erledigt.')
            ->assertSee('data-stat="open">0', false);
    }
}
