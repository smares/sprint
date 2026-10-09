<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskCollaboratorsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    public function test_multiple_collaborators_can_be_saved(): void
    {
        $task = Task::factory()->create();
        $people = User::factory()->admin()->count(3)->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('collaboratorIds', $people->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing($people->pluck('id')->all(), $task->collaborators()->pluck('users.id')->all());
    }

    public function test_collaborators_can_be_removed(): void
    {
        $task = Task::factory()->create();
        $task->collaborators()->attach(User::factory()->admin()->count(2)->create());

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('collaboratorIds', [])
            ->call('save');

        $this->assertCount(0, $task->collaborators);
    }

    public function test_unknown_users_are_rejected(): void
    {
        $task = Task::factory()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('collaboratorIds', ['999999'])
            ->call('save')
            ->assertHasErrors('collaboratorIds.0');
    }

    public function test_assignee_is_not_stored_as_collaborator(): void
    {
        $task = Task::factory()->create();
        $other = User::factory()->admin()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('assigneeId', (string) $this->user->id)
            ->set('collaboratorIds', [(string) $this->user->id, (string) $other->id])
            ->call('save');

        $this->assertSame([$other->id], $task->collaborators()->pluck('users.id')->all());
    }

    public function test_my_tasks_includes_open_tasks_where_i_am_a_collaborator(): void
    {
        Task::factory()->create(['title' => 'Ich bin beteiligt'])->collaborators()->attach($this->user);
        Task::factory()->done()->create(['title' => 'Beteiligt aber erledigt'])->collaborators()->attach($this->user);
        Task::factory()->create(['title' => 'Nicht meine']);

        $this->get(route('tasks.mine'))
            ->assertOk()
            ->assertSee('Ich bin beteiligt')
            ->assertDontSee('Beteiligt aber erledigt')
            ->assertDontSee('Nicht meine');
    }

    public function test_list_and_board_show_collaborator_count(): void
    {
        $task = Task::factory()->create();
        $task->collaborators()->attach($people = User::factory()->admin()->count(2)->create());

        // The list shows only the count; who it is shows on hover
        $this->get(route('projects.show', $task->project))->assertOk()->assertSee('+2')->assertSee('Beteiligte: '.$people->pluck('name')->join(', '));
        $this->get(route('projects.board', $task->project))->assertOk()->assertSee('+2');
    }
}
