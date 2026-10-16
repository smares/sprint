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

    public function test_my_tasks_lists_only_open_tasks_assigned_to_me_not_those_where_i_only_collaborate(): void
    {
        Task::factory()->create(['title' => 'Mir zugewiesen', 'assignee_id' => $this->user->id]);
        Task::factory()->done()->create(['title' => 'Zugewiesen aber erledigt', 'assignee_id' => $this->user->id]);
        Task::factory()->create(['title' => 'Ich bin nur beteiligt'])->collaborators()->attach($this->user);
        Task::factory()->create(['title' => 'Nicht meine']);

        $this->get(route('tasks.mine'))
            ->assertOk()
            ->assertSee('Mir zugewiesen')
            ->assertDontSee('Zugewiesen aber erledigt')
            ->assertDontSee('Ich bin nur beteiligt')
            ->assertDontSee('Nicht meine');
    }

    public function test_list_and_board_show_collaborators_as_stacked_avatars(): void
    {
        $task = Task::factory()->create();
        $people = User::factory()->admin()->count(4)->sequence(fn ($sequence) => ['name' => 'Person '.($sequence->index + 1)])->create();
        $task->collaborators()->attach($people);

        // Three faces with their names on hover, the fourth behind "+1"
        foreach (['projects.show', 'projects.board'] as $route) {
            $this->get(route($route, $task->project))->assertOk()
                ->assertSee('Person 1')->assertSee('Person 3')
                ->assertSee('+1');
        }
    }
}
