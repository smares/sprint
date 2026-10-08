<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The keys themselves are handled in app.js; here: the overview, the hooks it relies on and the "e" action.
 */
class KeyboardShortcutsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        $this->task = Task::factory()->for($this->project)->create();
    }

    public function test_every_page_offers_the_overview_in_the_user_menu(): void
    {
        $this->get(route('projects.index'))->assertOk()
            ->assertSee('Tastenkürzel')
            ->assertSee('data-modal="keyboard-shortcuts"', false)
            ->assertSee('Nächste Aufgabe öffnen');
    }

    public function test_list_rows_and_board_cards_carry_the_task_id_for_j_and_k(): void
    {
        $this->get(route('projects.show', $this->project))->assertSee('data-task-id="'.$this->task->id.'"', false);
        $this->get(route('projects.board', $this->project))->assertSee('data-task-id="'.$this->task->id.'"', false);
    }

    public function test_e_marks_the_open_task_done_and_open_again(): void
    {
        $page = Livewire::test('pages::tasks.show', ['task' => $this->task, 'panel' => true]);

        $page->dispatch('shortcut-toggle-done')->assertDispatched('task-changed')->assertSet('statusId', (string) $this->project->doneStatus()->id);
        $this->assertTrue($this->task->fresh()->isDone());

        $page->dispatch('shortcut-toggle-done');
        $this->assertFalse($this->task->fresh()->isDone());
    }

    public function test_e_does_nothing_for_people_who_may_only_read(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        Livewire::actingAs($viewer)->test('pages::tasks.show', ['task' => $this->task])->dispatch('shortcut-toggle-done')->assertNotDispatched('task-changed');

        $this->assertFalse($this->task->fresh()->isDone());
    }
}
