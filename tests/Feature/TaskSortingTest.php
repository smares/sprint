<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TaskSortingTest extends TestCase
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

    /**
     * @return list<int>
     */
    private function childIds(Task $parent): array
    {
        return $parent->children()->pluck('id')->all();
    }

    public function test_section_can_be_added_between_subtasks_and_does_not_count_for_progress(): void
    {
        $root = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $root])
            ->set("newSubtaskTitles.{$root->id}", 'Planung')
            ->call('addSection', $root->id)
            ->set("newSubtaskTitles.{$root->id}", 'Konzept schreiben')
            ->call('addSubtask', $root->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tasks', ['title' => 'Planung', 'is_section' => true, 'parent_id' => $root->id]);
        $this->assertSame(['done' => 0, 'total' => 1], $this->project->subtaskProgress([$root->id])[$root->id]);
    }

    public function test_sections_cannot_be_completed_or_used_as_parent(): void
    {
        $root = $this->task();
        $section = $this->task(['parent_id' => $root->id, 'is_section' => true, 'title' => 'Abschnitt']);

        Livewire::test('pages::tasks.show', ['task' => $root])
            ->call('toggleSubtask', $section->id)
            ->assertStatus(404);

        Livewire::test('pages::tasks.show', ['task' => $root])
            ->set("newSubtaskTitles.{$section->id}", 'Darunter')
            ->call('addSubtask', $section->id)
            ->assertStatus(404);
    }

    public function test_section_can_be_renamed_and_deleted_without_deleting_siblings(): void
    {
        $root = $this->task();
        $section = $this->task(['parent_id' => $root->id, 'is_section' => true, 'title' => 'Alt']);
        $sibling = $this->task(['parent_id' => $root->id, 'position' => 1]);

        Livewire::test('pages::tasks.show', ['task' => $root])
            ->set("sectionTitles.{$section->id}", 'Neu')
            ->assertSet("sectionTitles.{$section->id}", 'Neu');

        $this->assertSame('Neu', $section->fresh()->title);

        Livewire::test('pages::tasks.show', ['task' => $root])->call('deleteSection', $section->id);

        $this->assertDatabaseMissing('tasks', ['id' => $section->id]);
        $this->assertDatabaseHas('tasks', ['id' => $sibling->id]);
    }

    public function test_subtasks_can_be_reordered(): void
    {
        $root = $this->task();
        [$a, $b, $c] = [
            $this->task(['parent_id' => $root->id, 'position' => 0]),
            $this->task(['parent_id' => $root->id, 'position' => 1]),
            $this->task(['parent_id' => $root->id, 'position' => 2]),
        ];

        Livewire::test('pages::tasks.show', ['task' => $root])->call('moveSubtask', $c->id, 0, $root->id);

        $this->assertSame([$c->id, $a->id, $b->id], $this->childIds($root));
    }

    public function test_subtask_can_be_dragged_into_another_group(): void
    {
        $root = $this->task();
        $first = $this->task(['parent_id' => $root->id, 'position' => 0]);
        $second = $this->task(['parent_id' => $root->id, 'position' => 1]);
        $nested = $this->task(['parent_id' => $first->id]);

        Livewire::test('pages::tasks.show', ['task' => $root])->call('moveSubtask', $second->id, 1, $first->id);

        $this->assertSame($first->id, $second->fresh()->parent_id);
        $this->assertSame([$nested->id, $second->id], $this->childIds($first));
    }

    public function test_subtask_cannot_be_dropped_into_its_own_subtree(): void
    {
        $root = $this->task();
        $child = $this->task(['parent_id' => $root->id]);
        $grandchild = $this->task(['parent_id' => $child->id]);

        Livewire::test('pages::tasks.show', ['task' => $root])
            ->call('moveSubtask', $child->id, 0, $grandchild->id)
            ->assertStatus(422);

        Livewire::test('pages::tasks.show', ['task' => $root])
            ->call('moveSubtask', $child->id, 0, $child->id)
            ->assertStatus(422);

        $this->assertSame($root->id, $child->fresh()->parent_id);
    }

    public function test_tasks_outside_the_subtree_cannot_be_moved(): void
    {
        $root = $this->task();
        $other = $this->task();

        Livewire::test('pages::tasks.show', ['task' => $root])
            ->call('moveSubtask', $other->id, 0, $root->id)
            ->assertStatus(404);
    }

    public function test_list_can_be_reordered_manually(): void
    {
        [$a, $b, $c] = [
            $this->task(['position' => 0]),
            $this->task(['position' => 1]),
            $this->task(['position' => 2]),
        ];

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('moveTask', $c->id, 0)
            ->assertSeeInOrder([$c->title, $a->title, $b->title]);

        $this->assertSame(
            [$c->id, $a->id, $b->id],
            $this->project->tasks()->orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_moving_a_task_only_rewrites_the_positions_that_change(): void
    {
        $tasks = collect(range(0, 5))->map(fn (int $position) => $this->task(['position' => $position]));
        $ids = $tasks->pluck('id')->all();
        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with(strtolower($query->sql), 'update "tasks" set "position"')) {
                $updates++;
            }
        });

        $this->project->placeRootTask($tasks[4], array_values(array_diff($ids, [$ids[4]])), 3);

        $this->assertSame(2, $updates);
        $this->assertSame([$ids[0], $ids[1], $ids[2], $ids[4], $ids[3], $ids[5]], $this->project->tasks()->orderBy('position')->pluck('id')->all());
    }

    public function test_manual_order_is_shared_between_list_and_board(): void
    {
        $a = $this->task(['position' => 0]);
        $b = $this->task(['position' => 1, 'status_id' => $this->project->statuses[1]->id]);
        $c = $this->task(['position' => 2]);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('moveTask', $c->id, 0);

        $this->assertSame([$c->id, $a->id, $b->id], $this->project->tasks()->orderBy('position')->pluck('id')->all());

        $todoOrder = Livewire::test('pages::projects.board', ['project' => $this->project])
            ->instance()->columns[$this->project->statuses[0]->id]->pluck('id')->all();

        $this->assertSame([$c->id, $a->id], $todoOrder);
    }

    public function test_manual_order_is_rejected_while_sorted_by_a_column(): void
    {
        $task = $this->task();

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('sort', 'title')
            ->call('moveTask', $task->id, 0)
            ->assertStatus(422);
    }

    public function test_list_can_be_sorted_by_column_and_toggles_back_to_manual(): void
    {
        $this->task(['title' => 'Bbb', 'position' => 0]);
        $this->task(['title' => 'Aaa', 'position' => 1]);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->assertSeeInOrder(['Bbb', 'Aaa'])
            ->call('sort', 'title')
            ->assertSeeInOrder(['Aaa', 'Bbb'])
            ->call('sort', 'title')
            ->assertSet('sortDirection', 'desc')
            ->assertSeeInOrder(['Bbb', 'Aaa'])
            ->call('sort', 'title')
            ->assertSet('sortBy', '')
            ->assertSeeInOrder(['Bbb', 'Aaa']);
    }

    public function test_drag_and_drop_is_only_rendered_in_manual_order(): void
    {
        $task = $this->task();

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->assertSeeHtml('wire:sort="moveTask"')
            ->assertSeeHtml('wire:sort:item="'.$task->id.'"')
            ->call('sort', 'title')
            ->assertDontSeeHtml('wire:sort="moveTask"')
            ->assertDontSeeHtml('wire:sort:item=');
    }

    public function test_subtask_tree_renders_sortable_groups_and_sections(): void
    {
        $root = $this->task();
        $section = $this->task(['parent_id' => $root->id, 'is_section' => true, 'title' => 'Planung']);
        $child = $this->task(['parent_id' => $root->id, 'title' => 'Konzept', 'position' => 1]);

        $this->get(route('tasks.show', $root))
            ->assertOk()
            ->assertSeeHtml('wire:sort="moveSubtask"')
            ->assertSeeHtml('wire:sort:group-id="'.$root->id.'"')
            ->assertSeeHtml('wire:sort:item="'.$section->id.'"')
            ->assertSeeHtml('wire:sort:item="'.$child->id.'"')
            ->assertSee('Planung')
            ->assertSee('Konzept');
    }

    public function test_new_tasks_are_appended_to_the_manual_order(): void
    {
        $this->task(['position' => 4]);

        Livewire::test('task-create', ['project' => $this->project])
            ->set('title', 'Neu')
            ->call('create');

        $this->assertSame(5, Task::where('title', 'Neu')->value('position'));
    }
}
