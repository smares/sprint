<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskDependenciesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes);
    }

    public function test_dependencies_can_be_saved_in_both_directions(): void
    {
        $task = $this->task();
        $blocker = $this->task();
        $waiting = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('blockerIds', [(string) $blocker->id])
            ->set('blockingIds', [(string) $waiting->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($task->blockers()->whereKey($blocker->id)->exists());
        $this->assertTrue($task->blocking()->whereKey($waiting->id)->exists());
    }

    public function test_task_is_blocked_only_while_a_blocker_is_open(): void
    {
        $task = $this->task();
        $blocker = $this->task(['status_id' => $this->project->statuses[1]->id]);
        $task->blockers()->attach($blocker);

        $this->assertTrue($task->isBlocked());

        $blocker->update(['status_id' => $this->project->doneStatus()->id]);

        $this->assertFalse($task->fresh()->isBlocked());
    }

    public function test_task_cannot_depend_on_itself_or_on_other_projects(): void
    {
        $task = $this->task();
        $foreign = Task::factory()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('blockerIds', [(string) $task->id])
            ->call('save')
            ->assertHasErrors('blockerIds.0');

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('blockingIds', [(string) $foreign->id])
            ->call('save')
            ->assertHasErrors('blockingIds.0');
    }

    public function test_direct_cycles_are_rejected_and_rolled_back(): void
    {
        $task = $this->task();
        $other = $this->task();
        $other->blockers()->attach($task);

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('blockerIds', [(string) $other->id])
            ->call('save')
            ->assertHasErrors('blockingIds');

        $this->assertCount(0, $task->blockers);
    }

    public function test_indirect_cycles_are_rejected(): void
    {
        [$a, $b, $c] = [$this->task(), $this->task(), $this->task()];
        $a->blocking()->attach($b);
        $b->blocking()->attach($c);

        Livewire::test('pages::tasks.show', ['task' => $a])
            ->set('blockerIds', [(string) $c->id])
            ->set('blockingIds', [(string) $b->id])
            ->call('save')
            ->assertHasErrors('blockingIds');

        $this->assertCount(0, $a->blockers);
    }

    public function test_same_task_cannot_block_and_be_blocked(): void
    {
        $task = $this->task();
        $other = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('blockerIds', [(string) $other->id])
            ->set('blockingIds', [(string) $other->id])
            ->call('save')
            ->assertHasErrors('blockingIds');
    }

    public function test_list_and_board_mark_blocked_tasks(): void
    {
        $task = $this->task(['title' => 'Wartet']);
        $task->blockers()->attach($this->task());

        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('Blockiert');
        $this->get(route('projects.board', $this->project))->assertOk()->assertSee('Blockiert');
    }
}
