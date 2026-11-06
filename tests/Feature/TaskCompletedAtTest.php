<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
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

    public function test_it_is_set_when_a_task_is_finished_kept_while_it_moves_between_statuses_and_cleared_on_reopening(): void
    {
        $task = Task::factory()->for($this->project)->create();
        $statusId = $task->status_id;
        $this->assertNull($task->completed_at);
        $this->assertFalse($task->isDone());

        $task->toggleDone();
        $this->assertSame('2026-10-08 10:00:00', $task->completed_at->toDateTimeString());
        $this->assertTrue($task->isDone());
        $this->assertSame($statusId, $task->fresh()->status_id);

        $this->travel(2)->days();
        $other = $this->project->statuses()->skip(1)->firstOrFail();
        $task->update(['status_id' => $other->id]);
        $task->update(['title' => 'Umbenannt']);
        $this->assertSame('2026-10-08 10:00:00', $task->fresh()->completed_at->toDateTimeString());
        $this->assertTrue($task->fresh()->isDone());

        $task->toggleDone();
        $this->assertNull($task->fresh()->completed_at);
        $this->assertSame($other->id, $task->fresh()->status_id);

        $task->toggleDone();
        $this->assertSame('2026-10-10 10:00:00', $task->fresh()->completed_at->toDateTimeString());
    }

    public function test_a_task_created_as_done_counts_as_done_from_its_creation(): void
    {
        $task = Task::factory()->for($this->project)->done()->create();

        $this->assertSame('2026-10-08 10:00:00', $task->completed_at->toDateTimeString());
        $this->assertTrue($task->isDone());
    }

    public function test_a_task_in_any_status_can_be_done(): void
    {
        foreach ($this->project->statuses as $status) {
            $task = Task::factory()->for($this->project)->create(['status_id' => $status->id]);

            $task->toggleDone();

            $this->assertTrue($task->fresh()->isDone());
            $this->assertSame($status->id, $task->fresh()->status_id);
        }
    }

    public function test_the_status_does_not_decide_whether_a_task_is_done(): void
    {
        $last = $this->project->statuses()->orderByDesc('position')->firstOrFail();

        $this->assertFalse(Task::factory()->for($this->project)->create(['status_id' => $last->id])->isDone());
        $this->assertTrue(Task::factory()->for($this->project)->done()->create(['status_id' => $this->project->defaultStatus()->id])->isDone());
    }

    public function test_finishing_and_reopening_are_logged(): void
    {
        $task = Task::factory()->for($this->project)->create();

        $task->toggleDone();
        $task->fresh()->toggleDone();

        $types = $task->activities()->orderBy('id')->get()->pluck('type')->all();
        $this->assertSame([ActivityType::Created, ActivityType::Completed, ActivityType::Reopened], $types);
        $this->assertSame(['hat die Aufgabe erledigt', 'hat die Aufgabe wieder geöffnet'], $task->activities()->orderBy('id')->get()->skip(1)->map->sentence()->values()->all());
        $this->assertNotContains(ActivityType::StatusChanged, $types);
    }

    public function test_mark_done_is_idempotent_and_logs_once(): void
    {
        $task = Task::factory()->for($this->project)->create();

        $task->markDone();
        $this->travel(1)->day();
        $task->fresh()->markDone();

        $task->refresh();
        $this->assertSame('2026-10-08 10:00:00', $task->completed_at->toDateTimeString());
        $this->assertSame(1, $task->activities()->where('type', ActivityType::Completed)->count());
    }

    public function test_the_scopes_open_and_done_follow_completed_at(): void
    {
        $open = Task::factory()->for($this->project)->create();
        $done = Task::factory()->for($this->project)->done()->create();
        $doneInLastStatus = Task::factory()->for($this->project)->inProgress()->done()->create();
        $openInLastStatus = Task::factory()->for($this->project)->inProgress()->create();

        $this->assertEqualsCanonicalizing([$open->id, $openInLastStatus->id], Task::query()->open()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$done->id, $doneInLastStatus->id], Task::query()->done()->pluck('id')->all());
    }

    public function test_subtask_progress_counts_the_completion_of_the_subtasks_not_their_status(): void
    {
        $root = Task::factory()->for($this->project)->create();
        $first = Task::factory()->for($this->project)->done()->create(['parent_id' => $root->id]);
        Task::factory()->for($this->project)->inProgress()->create(['parent_id' => $root->id]);
        Task::factory()->for($this->project)->inProgress()->done()->create(['parent_id' => $first->id]);

        $progress = Task::subtaskProgress([$root->id, $first->id]);

        $this->assertSame(['done' => 2, 'total' => 3], $progress[$root->id]);
        $this->assertSame(['done' => 1, 'total' => 1], $progress[$first->id]);
    }

    public function test_a_blocked_task_is_free_once_its_blocker_is_finished_whatever_the_status_of_the_blocker(): void
    {
        $blocker = Task::factory()->for($this->project)->inProgress()->create();
        $blocked = Task::factory()->for($this->project)->create();
        $blocked->blockers()->attach($blocker);

        $this->assertTrue($blocked->fresh()->isBlocked());
        $this->assertTrue($blocked->fresh()->load('blockers')->isBlocked());

        $blocker->toggleDone();

        $this->assertFalse($blocked->fresh()->isBlocked());
        $this->assertFalse($blocked->fresh()->load('blockers')->isBlocked());
        $this->assertSame($this->project->statuses()->skip(1)->firstOrFail()->id, $blocker->fresh()->status_id);

        $blocker->toggleDone();

        $this->assertTrue($blocked->fresh()->isBlocked());
    }

    public function test_deleting_a_status_moves_its_tasks_and_leaves_their_completion_alone(): void
    {
        $extra = $this->project->statuses()->create(['name' => 'Warten', 'color' => '#71717a', 'position' => 9]);
        $open = Task::factory()->for($this->project)->create(['status_id' => $extra->id]);
        $done = Task::factory()->for($this->project)->done()->create(['status_id' => $extra->id]);
        $replacement = $this->project->defaultStatus();

        Livewire::test('project-statuses', ['project' => $this->project])
            ->call('confirmDelete', $extra->id)
            ->set('replacementId', (string) $replacement->id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertSame($replacement->id, $open->fresh()->status_id);
        $this->assertFalse($open->fresh()->isDone());
        $this->assertSame($replacement->id, $done->fresh()->status_id);
        $this->assertSame('2026-10-08 10:00:00', $done->fresh()->completed_at->toDateTimeString());
    }
}
