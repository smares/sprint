<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskCompletedAtTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 10:00:00');
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
    }

    public function test_it_is_set_when_a_task_becomes_done_kept_between_done_statuses_and_cleared_on_reopening(): void
    {
        $task = Task::factory()->for($this->project)->create();
        $this->assertNull($task->completed_at);

        $task->update(['status_id' => $this->project->doneStatus()->id]);
        $this->assertSame('2026-10-08 10:00:00', $task->completed_at->toDateTimeString());

        $this->travel(2)->days();
        $archived = $this->project->statuses()->create(['name' => 'Archiv', 'color' => '#71717a', 'position' => 9, 'is_done' => true]);
        $task->update(['status_id' => $archived->id]);
        $task->update(['title' => 'Umbenannt']);
        $this->assertSame('2026-10-08 10:00:00', $task->fresh()->completed_at->toDateTimeString());

        $task->update(['status_id' => $this->project->defaultStatus()->id]);
        $this->assertNull($task->fresh()->completed_at);

        $task->toggleDone();
        $this->assertSame('2026-10-10 10:00:00', $task->fresh()->completed_at->toDateTimeString());
    }

    public function test_a_task_created_as_done_counts_as_done_from_its_creation(): void
    {
        $task = Task::factory()->for($this->project)->done()->create();

        $this->assertSame('2026-10-08 10:00:00', $task->completed_at->toDateTimeString());
    }

    public function test_turning_a_status_into_a_done_one_and_back_updates_its_tasks(): void
    {
        $inProgress = $this->project->statuses()->where('is_done', false)->orderByDesc('position')->firstOrFail();
        $task = Task::factory()->for($this->project)->create(['status_id' => $inProgress->id]);

        $inProgress->update(['is_done' => true]);
        $this->assertSame('2026-10-08 10:00:00', $task->fresh()->completed_at->toDateTimeString());

        $inProgress->update(['is_done' => false]);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_deleting_a_status_moves_its_tasks_with_the_fitting_completion_time(): void
    {
        $extra = $this->project->statuses()->create(['name' => 'Warten', 'color' => '#71717a', 'position' => 9, 'is_done' => false]);
        $task = Task::factory()->for($this->project)->create(['status_id' => $extra->id]);

        Livewire::test('project-statuses', ['project' => $this->project])
            ->call('confirmDelete', $extra->id)
            ->set('replacementId', (string) $this->project->doneStatus()->id)
            ->call('delete')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertTrue($task->isDone());
        $this->assertSame('2026-10-08 10:00:00', $task->completed_at->toDateTimeString());
    }
}
