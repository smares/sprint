<?php

namespace Tests\Feature;

use App\Models\PersonalColumn;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyTasksCollaboratingSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->project = Project::factory()->create();
        $this->actingAs($this->user);

        Task::factory()->for($this->project)->create(['title' => 'Mir zugewiesen', 'assignee_id' => $this->user->id]);
        Task::factory()->for($this->project)->create(['title' => 'Nur beteiligt'])->collaborators()->attach($this->user);
        Task::factory()->for($this->project)->create(['title' => 'Nicht meine']);
    }

    public function test_the_list_shows_collaborating_tasks_only_when_the_switch_is_on(): void
    {
        Livewire::test('pages::tasks.mine')
            ->assertSee('Mir zugewiesen')->assertDontSee('Nur beteiligt')
            ->set('includeCollaborating', true)
            ->assertSee('Mir zugewiesen')->assertSee('Nur beteiligt')->assertDontSee('Nicht meine')
            ->set('includeCollaborating', false)
            ->assertDontSee('Nur beteiligt');
    }

    public function test_the_board_follows_the_switch_and_the_choice_is_remembered_for_list_and_board(): void
    {
        $titles = fn ($board) => $board->instance()->columns[0]->pluck('title')->all();

        $board = Livewire::test('pages::tasks.mine-board');
        $this->assertSame(['Mir zugewiesen'], $titles($board));

        $board->set('includeCollaborating', true);
        $this->assertEqualsCanonicalizing(['Mir zugewiesen', 'Nur beteiligt'], $titles($board));

        // The choice sits in the session, so the list opens with it as well
        Livewire::test('pages::tasks.mine')->assertSet('includeCollaborating', true)->assertSee('Nur beteiligt');
    }

    public function test_a_collaborating_task_can_be_placed_while_the_switch_is_on_and_keeps_its_place_when_it_is_off(): void
    {
        $task = Task::firstWhere('title', 'Nur beteiligt');
        $column = PersonalColumn::factory()->create(['user_id' => $this->user->id, 'name' => 'Heute', 'position' => 0]);

        Livewire::test('pages::tasks.mine-board')->call('moveTask', $task->id, 0, (string) $column->id)->assertStatus(404);

        $board = Livewire::test('pages::tasks.mine-board')->set('includeCollaborating', true)->call('moveTask', $task->id, 0, (string) $column->id);
        $this->assertSame(['Nur beteiligt'], $board->instance()->columns[$column->id]->pluck('title')->all());

        $board->set('includeCollaborating', false);
        $this->assertSame([], $board->instance()->columns[$column->id]->pluck('title')->all());
        $this->assertSame(1, $column->placements()->count());
    }

    public function test_the_switch_is_on_both_pages_and_the_empty_text_names_both_cases(): void
    {
        foreach (['tasks.mine', 'tasks.mine.board'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('wire:model.live="includeCollaborating"', false);
        }

        Task::query()->delete();

        Livewire::test('pages::tasks.mine')
            ->assertSee('Dir sind keine offenen Aufgaben zugewiesen.')
            ->set('includeCollaborating', true)
            ->assertSee('Dir sind keine offenen Aufgaben zugewiesen, und du bist an keiner beteiligt.');
    }
}
