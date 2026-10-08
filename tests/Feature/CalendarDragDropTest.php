<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarDragDropTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 10:00:00');
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes + ['title' => 'Termin']);
    }

    private function calendar()
    {
        return Livewire::test('pages::projects.calendar', ['project' => $this->project]);
    }

    private function timeline()
    {
        return Livewire::test('pages::projects.timeline', ['project' => $this->project]);
    }

    private function dates(Task $task): array
    {
        $task->refresh();

        return [$task->start_date?->toDateString(), $task->due_date?->toDateString()];
    }

    public function test_dropping_a_task_with_only_a_due_date_moves_the_due_date(): void
    {
        $task = $this->task(['due_date' => '2026-10-10']);

        $this->calendar()->call('moveToDay', $task->id, '2026-10-10', '2026-10-14');

        $this->assertSame([null, '2026-10-14'], $this->dates($task));
    }

    public function test_dropping_a_task_with_a_span_moves_both_dates_by_the_same_distance(): void
    {
        $task = $this->task(['start_date' => '2026-10-08', 'due_date' => '2026-10-12']);

        $this->calendar()->call('moveToDay', $task->id, '2026-10-10', '2026-10-17');

        $this->assertSame(['2026-10-15', '2026-10-19'], $this->dates($task));
    }

    public function test_moving_backwards_and_dropping_on_the_same_day_work(): void
    {
        $task = $this->task(['start_date' => '2026-10-08', 'due_date' => '2026-10-12']);

        $this->calendar()->call('moveToDay', $task->id, '2026-10-12', '2026-10-05');
        $this->assertSame(['2026-10-01', '2026-10-05'], $this->dates($task));

        $this->calendar()->call('moveToDay', $task->id, '2026-10-05', '2026-10-05');
        $this->assertSame(['2026-10-01', '2026-10-05'], $this->dates($task));
    }

    public function test_a_start_date_only_task_moves_too(): void
    {
        $task = $this->task(['start_date' => '2026-10-08']);

        $this->calendar()->call('moveToDay', $task->id, '2026-10-08', '2026-10-09');

        $this->assertSame(['2026-10-09', null], $this->dates($task));
    }

    public function test_the_move_shows_up_in_the_activity_log(): void
    {
        $task = $this->task(['due_date' => '2026-10-10']);

        $this->calendar()->call('moveToDay', $task->id, '2026-10-10', '2026-10-11');

        $this->assertContains('hat die Fälligkeit von 10.10.2026 auf 11.10.2026 geändert', $task->activities()->get()->map->sentence()->all());
    }

    public function test_invalid_dates_and_foreign_tasks_are_rejected(): void
    {
        $task = $this->task(['due_date' => '2026-10-10']);
        $foreign = Task::factory()->for(Project::factory()->create())->create(['due_date' => '2026-10-10']);
        $heading = $this->task(['is_section' => true, 'due_date' => '2026-10-10']);
        $child = $this->task(['parent_id' => $task->id, 'due_date' => '2026-10-10']);

        $this->calendar()->call('moveToDay', $task->id, 'gestern', '2026-10-14')->assertStatus(422);
        $this->calendar()->call('moveToDay', $task->id, '2026-10-10', '2026-13-45')->assertStatus(422);
        $this->calendar()->call('moveToDay', $foreign->id, '2026-10-10', '2026-10-14')->assertNotFound();
        $this->calendar()->call('moveToDay', $heading->id, '2026-10-10', '2026-10-14')->assertNotFound();
        $this->calendar()->call('moveToDay', $child->id, '2026-10-10', '2026-10-14')->assertNotFound();
        $this->calendar()->call('moveToDay', $task->id, '2026-10-10', '9999-12-31');

        $this->assertSame([null, '2026-10-10'], $this->dates($task));
    }

    public function test_viewers_and_archived_projects_cannot_move_tasks(): void
    {
        $task = $this->task(['due_date' => '2026-10-10']);
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer);

        Livewire::test('pages::projects.calendar', ['project' => $this->project])->call('moveToDay', $task->id, '2026-10-10', '2026-10-14')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->project->update(['archived_at' => now()]);
        Livewire::test('pages::projects.calendar', ['project' => $this->project->fresh()])->call('moveToDay', $task->id, '2026-10-10', '2026-10-14')->assertForbidden();

        $this->assertSame([null, '2026-10-10'], $this->dates($task));
    }

    public function test_only_people_who_may_edit_get_draggable_chips_and_drop_zones(): void
    {
        $this->task(['due_date' => '2026-10-10']);

        $this->get(route('projects.calendar', $this->project))->assertSee('draggable="true"', false)->assertSee('moveToDay', false);

        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer)->get(route('projects.calendar', $this->project))->assertOk()->assertDontSee('draggable="true"', false)->assertDontSee('moveToDay', false);
    }

    public function test_dragging_a_bar_moves_the_task(): void
    {
        $task = $this->task(['start_date' => '2026-10-08', 'due_date' => '2026-10-10']);

        $this->timeline()->call('reschedule', $task->id, 'move', 3);
        $this->assertSame(['2026-10-11', '2026-10-13'], $this->dates($task));

        $this->timeline()->call('reschedule', $task->id, 'move', -5);
        $this->assertSame(['2026-10-06', '2026-10-08'], $this->dates($task));
    }

    public function test_dragging_the_right_edge_changes_the_due_date_only(): void
    {
        $task = $this->task(['start_date' => '2026-10-08', 'due_date' => '2026-10-10']);

        $this->timeline()->call('reschedule', $task->id, 'end', 4);
        $this->assertSame(['2026-10-08', '2026-10-14'], $this->dates($task));

        $this->timeline()->call('reschedule', $task->id, 'end', -2);
        $this->assertSame(['2026-10-08', '2026-10-12'], $this->dates($task));
    }

    public function test_dragging_the_left_edge_changes_the_start_date_only(): void
    {
        $task = $this->task(['start_date' => '2026-10-08', 'due_date' => '2026-10-12']);

        $this->timeline()->call('reschedule', $task->id, 'start', -3);
        $this->assertSame(['2026-10-05', '2026-10-12'], $this->dates($task));

        $this->timeline()->call('reschedule', $task->id, 'start', 2);
        $this->assertSame(['2026-10-07', '2026-10-12'], $this->dates($task));
    }

    public function test_the_edges_never_cross(): void
    {
        $task = $this->task(['start_date' => '2026-10-08', 'due_date' => '2026-10-10']);

        $this->timeline()->call('reschedule', $task->id, 'end', -9);
        $this->assertSame(['2026-10-08', '2026-10-08'], $this->dates($task));

        $this->timeline()->call('reschedule', $task->id, 'start', 9);
        $this->assertSame(['2026-10-08', '2026-10-08'], $this->dates($task));
    }

    public function test_a_single_day_task_can_be_stretched_into_a_span(): void
    {
        $task = $this->task(['due_date' => '2026-10-10']);

        $this->timeline()->call('reschedule', $task->id, 'end', 2);
        $this->assertSame(['2026-10-10', '2026-10-12'], $this->dates($task));

        $other = $this->task(['due_date' => '2026-10-10']);
        $this->timeline()->call('reschedule', $other->id, 'start', -2);
        $this->assertSame(['2026-10-08', '2026-10-10'], $this->dates($other));
    }

    public function test_rescheduling_rejects_unknown_modes_foreign_tasks_and_no_ops(): void
    {
        $task = $this->task(['start_date' => '2026-10-08', 'due_date' => '2026-10-10']);
        $foreign = Task::factory()->for(Project::factory()->create())->create(['due_date' => '2026-10-10']);

        $this->timeline()->call('reschedule', $task->id, 'schrumpfen', 1)->assertStatus(422);
        $this->timeline()->call('reschedule', $foreign->id, 'move', 1)->assertNotFound();
        $this->timeline()->call('reschedule', $task->id, 'move', 0);
        $this->timeline()->call('reschedule', $task->id, 'move', 99999);

        $this->assertSame(['2026-10-08', '2026-10-10'], $this->dates($task));
    }

    public function test_viewers_cannot_reschedule_and_see_no_handles(): void
    {
        $task = $this->task(['start_date' => '2026-10-08', 'due_date' => '2026-10-10']);
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        $this->get(route('projects.timeline', $this->project))->assertSee('timelineBar', false)->assertSee('cursor-ew-resize', false);

        $this->actingAs($viewer)->get(route('projects.timeline', $this->project))->assertOk()->assertDontSee('timelineBar', false)->assertDontSee('cursor-ew-resize', false);
        Livewire::test('pages::projects.timeline', ['project' => $this->project])->call('reschedule', $task->id, 'move', 1)->assertForbidden();

        $this->assertSame(['2026-10-08', '2026-10-10'], $this->dates($task));
    }

    public function test_edge_handles_are_hidden_where_the_bar_is_cut_off_by_the_window(): void
    {
        $this->task(['start_date' => '2026-08-01', 'due_date' => '2026-10-10']);

        $html = $this->get(route('projects.timeline', $this->project))->getContent();

        $this->assertSame(1, substr_count($html, "begin(\$event, 'end')"));
        $this->assertSame(0, substr_count($html, "begin(\$event, 'start')"));
    }
}
