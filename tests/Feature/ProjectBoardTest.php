<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_board_shows_tasks_in_their_status_columns(): void
    {
        $project = Project::factory()->create();
        Task::factory()->for($project)->create(['title' => 'Noch offen', 'status' => TaskStatus::Todo]);
        Task::factory()->for($project)->create(['title' => 'Läuft gerade', 'status' => TaskStatus::InProgress]);

        $this->get(route('projects.board', $project))
            ->assertOk()
            ->assertSee('Noch offen')
            ->assertSee('Läuft gerade')
            ->assertSee('In Arbeit');
    }

    public function test_moving_a_task_changes_status_and_position(): void
    {
        $project = Project::factory()->create();
        $first = Task::factory()->for($project)->create(['status' => TaskStatus::InProgress, 'position' => 0]);
        $second = Task::factory()->for($project)->create(['status' => TaskStatus::InProgress, 'position' => 1]);
        $moved = Task::factory()->for($project)->create(['status' => TaskStatus::Todo]);

        Livewire::test('pages::projects.board', ['project' => $project])
            ->call('moveTask', $moved->id, 1, 'in_progress');

        $this->assertSame(TaskStatus::InProgress, $moved->refresh()->status);
        $this->assertSame(
            [$first->id, $moved->id, $second->id],
            $project->tasks()->where('status', TaskStatus::InProgress)->orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_task_cannot_be_moved_to_an_unknown_status(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();

        Livewire::test('pages::projects.board', ['project' => $project])
            ->call('moveTask', $task->id, 0, 'archived')
            ->assertStatus(422);

        $this->assertSame(TaskStatus::Todo, $task->refresh()->status);
    }

    public function test_task_of_another_project_cannot_be_moved(): void
    {
        $project = Project::factory()->create();
        $foreign = Task::factory()->create(['status' => TaskStatus::Todo]);

        Livewire::test('pages::projects.board', ['project' => $project])
            ->call('moveTask', $foreign->id, 0, 'done')
            ->assertStatus(404);

        $this->assertSame(TaskStatus::Todo, $foreign->refresh()->status);
    }
}
