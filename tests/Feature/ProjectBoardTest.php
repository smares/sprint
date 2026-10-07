<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_board_shows_tasks_in_their_status_columns(): void
    {
        $project = Project::factory()->create();
        Task::factory()->for($project)->create(['title' => 'Noch offen']);
        Task::factory()->for($project)->inProgress()->create(['title' => 'Läuft gerade']);

        $this->get(route('projects.board', $project))
            ->assertOk()
            ->assertSee('Noch offen')
            ->assertSee('Läuft gerade')
            ->assertSee('In Arbeit');
    }

    public function test_moving_a_task_changes_status_and_position(): void
    {
        $project = Project::factory()->create();
        $first = Task::factory()->for($project)->inProgress()->create(['position' => 0]);
        $second = Task::factory()->for($project)->inProgress()->create(['position' => 1]);
        $moved = Task::factory()->for($project)->create();
        $inProgress = $project->statuses[1];

        Livewire::test('pages::projects.board', ['project' => $project])
            ->call('moveTask', $moved->id, 1, (string) $inProgress->id);

        $this->assertSame($inProgress->id, $moved->refresh()->status_id);
        $this->assertSame(
            [$first->id, $moved->id, $second->id],
            $project->tasks()->where('status_id', $inProgress->id)->orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_task_cannot_be_moved_to_an_unknown_status(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();

        Livewire::test('pages::projects.board', ['project' => $project])
            ->call('moveTask', $task->id, 0, 'archived')
            ->assertStatus(422);

        $this->assertSame($project->defaultStatus()->id, $task->refresh()->status_id);
    }

    public function test_task_of_another_project_cannot_be_moved(): void
    {
        $project = Project::factory()->create();
        $foreign = Task::factory()->create();

        Livewire::test('pages::projects.board', ['project' => $project])
            ->call('moveTask', $foreign->id, 0, (string) $project->doneStatus()->id)
            ->assertStatus(404);

        $this->assertSame($foreign->project->defaultStatus()->id, $foreign->refresh()->status_id);
    }
}
