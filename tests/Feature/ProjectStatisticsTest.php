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

    public function test_a_project_without_activity_shows_empty_weeks_and_no_stuck_tasks(): void
    {
        Livewire::test('pages::projects.statistics', ['project' => $this->project])
            ->assertSee('Keine offene Aufgabe steht über den ersten Status hinaus.')
            ->assertSee('data-stat="open">0', false);
    }
}
