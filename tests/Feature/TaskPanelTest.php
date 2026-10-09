<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskPanelTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        $this->task = Task::factory()->for($this->project)->create(['title' => 'Angebot schreiben', 'description' => 'Bitte bis Freitag']);
    }

    private function list()
    {
        return Livewire::test('pages::projects.show', ['project' => $this->project]);
    }

    private function board()
    {
        return Livewire::test('pages::projects.board', ['project' => $this->project]);
    }

    public function test_the_panel_is_closed_by_default(): void
    {
        $this->list()->assertSet('openTaskId', '')->assertDontSee('Bitte bis Freitag');
        $this->board()->assertSet('openTaskId', '')->assertDontSee('Bitte bis Freitag');
    }

    public function test_the_task_in_the_url_opens_next_to_the_list_and_the_board(): void
    {
        $this->get(route('projects.show', ['project' => $this->project, 'task' => $this->task->id]))
            ->assertOk()->assertSee('aria-label="Aufgabe"', false)->assertSee('Als Seite öffnen');

        Livewire::withQueryParams(['task' => $this->task->id])->test('pages::projects.show', ['project' => $this->project])
            ->assertSet('openTaskId', (string) $this->task->id)
            ->assertSeeHtml('aria-label="Aufgabe"');

        Livewire::withQueryParams(['task' => $this->task->id])->test('pages::projects.board', ['project' => $this->project])
            ->assertSeeHtml('aria-label="Aufgabe"');
    }

    public function test_the_task_opens_in_a_flyout_that_closes_the_task_again(): void
    {
        $list = $this->list()->call('openTask', $this->task->id);

        $list->assertSeeHtml('data-modal="task-panel"')->assertSeeHtml('data-flux-flyout')
            ->assertSeeHtml('wire:close="closeTask"')->assertSeeHtml('$flux.modal(\'task-panel\').show()');

        $this->list()->assertDontSeeHtml('data-modal="task-panel"');
    }

    public function test_opening_and_closing_by_event_and_action(): void
    {
        $other = Task::factory()->for($this->project)->create(['title' => 'Zweite']);

        $list = $this->list();
        $list->call('openTask', $this->task->id)->assertSet('openTaskId', (string) $this->task->id)->assertSeeHtml('aria-label="Aufgabe"');
        $list->dispatch('open-task', id: $other->id)->assertSet('openTaskId', (string) $other->id);
        $list->dispatch('close-task')->assertSet('openTaskId', '')->assertDontSeeHtml('aria-label="Aufgabe"');

        $board = $this->board();
        $board->call('openTask', $this->task->id)->assertSet('openTaskId', (string) $this->task->id);
        $board->call('closeTask')->assertSet('openTaskId', '');
    }

    public function test_foreign_tasks_headings_and_nonsense_do_not_open(): void
    {
        $foreign = Task::factory()->for(Project::factory()->create())->create(['title' => 'Fremde Aufgabe']);
        $heading = Task::factory()->for($this->project)->create(['parent_id' => $this->task->id, 'is_section' => true, 'title' => 'Überschrift']);

        $list = $this->list();
        $list->call('openTask', $foreign->id)->assertSet('openTaskId', '');
        $list->call('openTask', $heading->id)->assertSet('openTaskId', '');
        $list->call('openTask', 99999)->assertSet('openTaskId', '');

        Livewire::withQueryParams(['task' => $foreign->id])->test('pages::projects.show', ['project' => $this->project])
            ->assertDontSee('Fremde Aufgabe')->assertDontSeeHtml('aria-label="Aufgabe"');
        Livewire::withQueryParams(['task' => 'abc'])->test('pages::projects.show', ['project' => $this->project])
            ->assertDontSeeHtml('aria-label="Aufgabe"');
    }

    public function test_only_people_who_may_see_the_project_get_a_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.show', ['project' => $this->project, 'task' => $this->task->id]))->assertForbidden();
    }

    public function test_viewers_see_the_panel_read_only(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer);

        Livewire::withQueryParams(['task' => $this->task->id])->test('pages::projects.show', ['project' => $this->project])
            ->assertSee('Nur ansehen');
    }

    public function test_saving_in_the_panel_announces_the_change_and_updates_the_list(): void
    {
        $panel = Livewire::test('pages::tasks.show', ['task' => $this->task, 'panel' => true])
            ->set('title', 'Angebot überarbeiten')->call('save')
            ->assertHasNoErrors()->assertDispatched('task-changed');

        $this->assertSame('Angebot überarbeiten', $this->task->fresh()->title);
        $this->list()->assertSee('Angebot überarbeiten');
        $this->assertNotNull($panel);
    }

    public function test_the_description_fields_have_a_placeholder_like_the_comment_field(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task->fresh()->forceFill(['description' => null])])
            ->assertSeeHtml('placeholder="Aufgabe beschreiben … (Markdown, @ für Erwähnungen)"');

        Livewire::test('task-create', ['project' => $this->project])
            ->assertSeeHtml('placeholder="Aufgabe beschreiben … (Markdown, @ für Erwähnungen)"');
    }

    public function test_the_page_itself_does_not_dispatch_panel_events(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('title', 'Geändert')->call('save')->assertNotDispatched('task-changed');
    }

    public function test_deleting_in_the_panel_announces_it_instead_of_redirecting(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task, 'panel' => true])
            ->call('delete')->assertDispatched('task-deleted')->assertNoRedirect();

        $this->assertModelMissing($this->task);
    }

    public function test_deleting_on_the_page_still_redirects_to_the_project(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->call('delete')->assertRedirect(route('projects.show', $this->project));
    }

    public function test_the_list_closes_the_panel_when_its_task_was_deleted(): void
    {
        $this->list()->call('openTask', $this->task->id)->dispatch('task-deleted')->assertSet('openTaskId', '');
    }

    public function test_subtasks_in_the_panel_switch_the_panel_instead_of_navigating(): void
    {
        $child = Task::factory()->for($this->project)->create(['parent_id' => $this->task->id, 'title' => 'Unteraufgabe']);

        $panel = Livewire::test('pages::tasks.show', ['task' => $this->task, 'panel' => true])->assertSee('Unteraufgabe');
        $panel->assertSeeHtml("open-task', { id: {$child->id} }");
        $panel->assertDontSeeHtml('href="'.route('tasks.show', $child).'"');

        Livewire::test('pages::tasks.show', ['task' => $this->task])->assertSeeHtml('href="'.route('tasks.show', $child).'"');
    }

    public function test_the_panel_has_no_page_breadcrumbs_but_a_link_to_the_full_page(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task, 'panel' => true])
            ->assertDontSee('Projekte')->assertSeeHtml('href="'.route('tasks.show', $this->task).'"');

        Livewire::test('pages::tasks.show', ['task' => $this->task])->assertSee('Projekte');
    }

    public function test_task_titles_in_list_and_board_still_link_to_the_full_page_for_new_tabs(): void
    {
        $this->list()->assertSeeHtml('href="'.route('tasks.show', $this->task).'"');
        $this->board()->assertSeeHtml('href="'.route('tasks.show', $this->task).'"');
    }
}
