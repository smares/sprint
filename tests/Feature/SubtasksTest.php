<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubtasksTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->project = Project::factory()->create();
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes);
    }

    public function test_subtask_can_be_added_to_a_task(): void
    {
        $task = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set("newSubtaskTitles.{$task->id}", 'Erster Schritt')
            ->call('addSubtask', $task->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tasks', ['title' => 'Erster Schritt', 'parent_id' => $task->id, 'project_id' => $this->project->id]);
    }

    public function test_subtasks_can_be_nested_to_any_depth(): void
    {
        $task = $this->task();
        $parent = $task;

        foreach (range(1, 6) as $level) {
            Livewire::test('pages::tasks.show', ['task' => $task])
                ->set("newSubtaskTitles.{$parent->id}", "Ebene $level")
                ->call('addSubtask', $parent->id)
                ->assertHasNoErrors();

            $parent = Task::where('title', "Ebene $level")->firstOrFail();
        }

        $this->assertSame(6, $task->fresh()->project->subtaskProgress()[$task->id]['total']);
    }

    public function test_subtask_title_is_required(): void
    {
        $task = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('addSubtask', $task->id)
            ->assertHasErrors("newSubtaskTitles.{$task->id}");
    }

    public function test_subtask_cannot_be_added_to_an_unrelated_task(): void
    {
        $task = $this->task();
        $other = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set("newSubtaskTitles.{$other->id}", 'Fremd')
            ->call('addSubtask', $other->id)
            ->assertStatus(404);
    }

    public function test_progress_counts_all_descendants(): void
    {
        $root = $this->task();
        $child = $this->task(['parent_id' => $root->id, 'status_id' => $this->project->doneStatus()->id]);
        $this->task(['parent_id' => $child->id, 'status_id' => $this->project->doneStatus()->id]);
        $this->task(['parent_id' => $child->id]);

        $progress = $this->project->subtaskProgress();

        $this->assertSame(['done' => 2, 'total' => 3], $progress[$root->id]);
        $this->assertSame(['done' => 1, 'total' => 2], $progress[$child->id]);
    }

    public function test_completing_all_subtasks_does_not_complete_the_parent(): void
    {
        $root = $this->task();
        $child = $this->task(['parent_id' => $root->id]);

        Livewire::test('pages::tasks.show', ['task' => $root])->call('toggleSubtask', $child->id);

        $this->assertTrue($child->fresh()->isDone());
        $this->assertFalse($root->fresh()->isDone());
    }

    public function test_only_descendants_can_be_toggled(): void
    {
        $root = $this->task();
        $unrelated = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $root])
            ->call('toggleSubtask', $unrelated->id)
            ->assertStatus(404);
    }

    public function test_task_can_be_moved_under_another_task(): void
    {
        $task = $this->task();
        $newParent = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('parentId', (string) $newParent->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($newParent->id, $task->fresh()->parent_id);
    }

    public function test_task_cannot_be_moved_under_itself_or_a_descendant(): void
    {
        $root = $this->task();
        $child = $this->task(['parent_id' => $root->id]);
        $grandchild = $this->task(['parent_id' => $child->id]);

        foreach ([$root, $child, $grandchild] as $invalid) {
            Livewire::test('pages::tasks.show', ['task' => $root])
                ->set('parentId', (string) $invalid->id)
                ->call('save')
                ->assertHasErrors('parentId');
        }

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_task_cannot_be_moved_under_a_task_of_another_project(): void
    {
        $task = $this->task();
        $foreign = Task::factory()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('parentId', (string) $foreign->id)
            ->call('save')
            ->assertHasErrors('parentId');
    }

    public function test_deleting_a_task_deletes_its_subtasks(): void
    {
        $root = $this->task();
        $child = $this->task(['parent_id' => $root->id]);
        $grandchild = $this->task(['parent_id' => $child->id]);

        Livewire::test('pages::tasks.show', ['task' => $root])->call('delete');

        $this->assertDatabaseMissing('tasks', ['id' => $grandchild->id]);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_list_and_board_show_only_top_level_tasks_with_progress(): void
    {
        $root = $this->task(['title' => 'Hauptaufgabe']);
        $this->task(['title' => 'Kleiner Schritt', 'parent_id' => $root->id, 'status_id' => $this->project->doneStatus()->id]);
        $this->task(['title' => 'Noch ein Schritt', 'parent_id' => $root->id]);

        $this->get(route('projects.show', $this->project))
            ->assertOk()->assertSee('Hauptaufgabe')->assertSee('1/2')->assertDontSee('Kleiner Schritt');
        $this->get(route('projects.board', $this->project))
            ->assertOk()->assertSee('Hauptaufgabe')->assertSee('1/2')->assertDontSee('Kleiner Schritt');
    }

    public function test_task_page_shows_ancestors_and_progress(): void
    {
        $root = $this->task(['title' => 'Oben']);
        $child = $this->task(['title' => 'Mitte', 'parent_id' => $root->id]);
        $this->task(['title' => 'Unten', 'parent_id' => $child->id, 'status_id' => $this->project->doneStatus()->id]);

        $this->get(route('tasks.show', $child))->assertOk()->assertSee('Oben')->assertSee('1 von 1 erledigt');
    }
}
