<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlignAsanaSubtasksTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
    }

    private function imported(Task $task): Task
    {
        DB::table('asana_imports')->insert(['kind' => 'task', 'gid' => (string) (1000 + $task->id), 'model_id' => $task->id]);

        return $task;
    }

    public function test_open_subtasks_at_the_start_move_to_the_status_of_their_task(): void
    {
        $parent = $this->imported(Task::factory()->for($this->project)->inProgress()->create());
        $subtask = $this->imported(Task::factory()->for($this->project)->create(['parent_id' => $parent->id, 'status_id' => $this->project->defaultStatus()->id]));
        $nested = $this->imported(Task::factory()->for($this->project)->create(['parent_id' => $subtask->id, 'status_id' => $this->project->defaultStatus()->id]));

        $this->artisan('asana:align-subtasks')->expectsOutputToContain('2 subtasks moved')->assertSuccessful();

        $this->assertSame($parent->status_id, $subtask->fresh()->status_id);
        $this->assertSame($parent->status_id, $nested->fresh()->status_id);
    }

    public function test_a_dry_run_changes_nothing_and_a_second_run_finds_nothing_more(): void
    {
        $parent = $this->imported(Task::factory()->for($this->project)->inProgress()->create());
        $subtask = $this->imported(Task::factory()->for($this->project)->create(['parent_id' => $parent->id, 'status_id' => $this->project->defaultStatus()->id]));
        $before = $subtask->status_id;

        $this->artisan('asana:align-subtasks', ['--dry-run' => true])->expectsOutputToContain('1 subtasks would move')->assertSuccessful();
        $this->assertSame($before, $subtask->fresh()->status_id);

        $this->artisan('asana:align-subtasks')->assertSuccessful();
        $this->artisan('asana:align-subtasks')->expectsOutputToContain('0 subtasks moved')->assertSuccessful();
    }

    public function test_only_open_imported_subtasks_under_a_task_that_is_not_done_are_touched(): void
    {
        $open = $this->project->defaultStatus()->id;
        $done = $this->project->doneStatus()->id;
        $working = $this->imported(Task::factory()->for($this->project)->inProgress()->create());
        $finished = $this->imported(Task::factory()->for($this->project)->create(['status_id' => $done]));

        $completed = $this->imported(Task::factory()->for($this->project)->create(['parent_id' => $working->id, 'status_id' => $done]));
        $underDone = $this->imported(Task::factory()->for($this->project)->create(['parent_id' => $finished->id, 'status_id' => $open]));
        $own = Task::factory()->for($this->project)->create(['parent_id' => $working->id, 'status_id' => $open]);
        $alreadyThere = $this->imported(Task::factory()->for($this->project)->create(['parent_id' => $working->id, 'status_id' => $this->project->statuses[1]->id]));

        $this->artisan('asana:align-subtasks')->expectsOutputToContain('0 subtasks moved')->assertSuccessful();

        $this->assertSame($done, $completed->fresh()->status_id);
        $this->assertSame($open, $underDone->fresh()->status_id);
        $this->assertSame($open, $own->fresh()->status_id);
        $this->assertSame($working->status_id, $alreadyThere->fresh()->status_id);
    }

    public function test_a_dry_run_counts_nested_subtasks_like_the_real_run(): void
    {
        $parent = $this->imported(Task::factory()->for($this->project)->inProgress()->create());
        $subtask = $this->imported(Task::factory()->for($this->project)->create(['parent_id' => $parent->id, 'status_id' => $this->project->defaultStatus()->id]));
        $this->imported(Task::factory()->for($this->project)->create(['parent_id' => $subtask->id, 'status_id' => $this->project->defaultStatus()->id]));

        $this->artisan('asana:align-subtasks', ['--dry-run' => true])->expectsOutputToContain('2 subtasks would move')->assertSuccessful();
        $this->artisan('asana:align-subtasks')->expectsOutputToContain('2 subtasks moved')->assertSuccessful();
    }
}
