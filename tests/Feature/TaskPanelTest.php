<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
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

    private function flyout()
    {
        return Livewire::test('task-flyout');
    }

    public function test_the_panel_is_closed_by_default(): void
    {
        $this->flyout()->assertSet('openTaskId', '')->assertDontSee('Bitte bis Freitag')->assertDontSeeHtml('data-modal="task-panel"');
    }

    public function test_the_task_in_the_url_opens_the_flyout_on_every_page(): void
    {
        foreach ([route('projects.show', ['project' => $this->project, 'task' => $this->task->id]), route('projects.board', ['project' => $this->project, 'task' => $this->task->id]), route('tasks.mine', ['task' => $this->task->id])] as $url) {
            $this->get($url)->assertOk()->assertSee('data-modal="task-panel"', false)->assertSee('Als Seite öffnen');
        }

        Livewire::withQueryParams(['task' => $this->task->id])->test('task-flyout')
            ->assertSet('openTaskId', (string) $this->task->id)
            ->assertSeeHtml('data-modal="task-panel"');
    }

    public function test_the_flyout_offers_the_link_to_the_task_itself_to_copy(): void
    {
        $this->get(route('projects.show', ['project' => $this->project, 'task' => $this->task->id, 'sort' => 'due']))->assertOk()
            ->assertSee('aria-label="Link kopieren"', false)
            ->assertSee('$copyText('.Js::from(route('tasks.show', $this->task)), false);
    }

    public function test_the_pages_do_not_draw_the_flyout_themselves(): void
    {
        // Opening and closing asks the server for the flyout only, not for the list or board behind it
        $this->list()->assertDontSeeHtml('data-modal="task-panel"')->assertSeeHtml('$openTask($event, '.$this->task->id.')');
        $this->assertFalse(property_exists($this->list()->instance(), 'openTaskId'));
    }

    public function test_the_task_opens_in_a_flyout_that_closes_the_task_again(): void
    {
        $flyout = $this->flyout()->call('openTask', $this->task->id);

        $flyout->assertSeeHtml('data-modal="task-panel"')->assertSeeHtml('data-flux-flyout')
            ->assertSeeHtml('wire:close="closeTask"')->assertSeeHtml('x-data="taskFlyout(');

        $this->flyout()->assertDontSeeHtml('data-modal="task-panel"');
    }

    public function test_closing_the_flyout_goes_through_the_check_for_unsaved_input(): void
    {
        // Flux does not close it by itself (no click outside, no Esc); taskFlyout in app.js asks first when something is unsaved
        $this->flyout()->call('openTask', $this->task->id)
            ->assertSeeHtml('disable-click-outside')
            ->assertSeeHtml('disable-escape')
            ->assertSeeHtml('x-on:keydown.window.capture="onKeydown($event)"')
            ->assertSeeHtml('x-on:click="onClick($event)"')
            ->assertSeeHtml('x-on:close-task="guard($event)"')
            ->assertSeeHtml('x-on:open-task="guard($event)"')
            ->assertSee('Ungespeicherte Änderungen an dieser Aufgabe verwerfen?');

        Livewire::test('pages::tasks.show', ['task' => $this->task, 'panel' => true])->assertSeeHtml('data-flyout-close');
    }

    public function test_the_check_for_unsaved_input_compares_the_task_form_with_the_server_and_follows_the_edits_of_the_others(): void
    {
        // The task form is compared with the state the server last sent, in the properties it names: the events of its
        // fields cannot tell (the lists and date pickers of Flux send some at their start, a search sends them too), and
        // Livewire's $dirty() is false again after any request, which takes along what was typed. taskFlyout in app.js
        // remembers a request sent with changes until "Save". The forms for a comment or a subtask follow their input events.
        $flyout = $this->flyout()->call('openTask', $this->task->id)
            ->assertSeeHtml('x-on:input.capture="track($event)"')
            ->assertSeeHtml('x-on:change.capture="track($event)"')
            ->assertSeeHtml('x-on:task-saved.window="forgetEdits()"');

        $html = $flyout->html();

        $this->assertSame(1, substr_count($html, 'data-task-form'));
        $this->assertStringContainsString('<form wire:submit="save" data-task-form data-dirty-properties="', $html);
        $this->assertStringContainsString('<form wire:submit="addComment"', $html);
    }

    public function test_the_properties_the_check_compares_are_those_the_task_form_stores(): void
    {
        CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);
        $this->task->update(['repeat_unit' => 'week']);

        $html = Livewire::test('pages::tasks.show', ['task' => $this->task, 'panel' => true])->html();
        $form = (string) str($html)->after('<form wire:submit="save"')->before('</form>');

        $listed = json_decode(html_entity_decode((string) str($form)->after('data-dirty-properties="')->before('"')), true);

        preg_match_all('/wire:model(\.[a-z.0-9]+)?="([^"]+)"/', $form, $matches, PREG_SET_ORDER);
        $bound = collect($matches)
            // What searches or saves itself (wire:model.live), but the repeat unit, which is live only to show its fields; typed
            // text for a new tag is no choice yet
            ->reject(fn (array $match) => str_contains($match[1], 'live') && $match[2] !== 'repeatUnit')
            ->map(fn (array $match) => explode('.', $match[2])[0])
            ->reject(fn (string $property) => $property === 'newTag')
            ->unique()->values()->all();

        $this->assertContains('fieldValues', $bound);
        $this->assertContains('repeatUnit', $bound);
        $this->assertEqualsCanonicalizing($bound, $listed, 'every bound property of the form must be named in data-dirty-properties, and none else');
        $this->assertStringContainsString('wire:model.live="repeatUnit"', $form);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="parentSearch"', $form);
    }

    public function test_opening_and_closing_by_event_and_action(): void
    {
        $other = Task::factory()->for($this->project)->create(['title' => 'Zweite']);

        $flyout = $this->flyout();
        $flyout->call('openTask', $this->task->id)->assertSet('openTaskId', (string) $this->task->id)->assertSeeHtml('data-modal="task-panel"');
        $flyout->dispatch('open-task', id: $other->id)->assertSet('openTaskId', (string) $other->id);
        $flyout->dispatch('close-task')->assertSet('openTaskId', '')->assertDontSeeHtml('data-modal="task-panel"');

        $flyout->call('openTask', $this->task->id)->call('closeTask')->assertSet('openTaskId', '');
    }

    public function test_headings_nonsense_and_tasks_of_hidden_projects_do_not_open(): void
    {
        $heading = Task::factory()->for($this->project)->create(['parent_id' => $this->task->id, 'is_section' => true, 'title' => 'Überschrift']);
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $hidden = Task::factory()->for(Project::factory()->create())->create(['title' => 'Fremde Aufgabe']);
        $this->actingAs($viewer);

        $flyout = $this->flyout();
        $flyout->call('openTask', $hidden->id)->assertSet('openTaskId', '');
        $flyout->call('openTask', $heading->id)->assertSet('openTaskId', '');
        $flyout->call('openTask', 99999)->assertSet('openTaskId', '');
        $flyout->call('openTask', $this->task->id)->assertSet('openTaskId', (string) $this->task->id);

        Livewire::withQueryParams(['task' => $hidden->id])->test('task-flyout')
            ->assertDontSee('Fremde Aufgabe')->assertDontSeeHtml('data-modal="task-panel"');
        Livewire::withQueryParams(['task' => 'abc'])->test('task-flyout')
            ->assertDontSeeHtml('data-modal="task-panel"');
    }

    public function test_only_people_who_may_see_the_project_get_a_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.show', ['project' => $this->project, 'task' => $this->task->id]))->assertForbidden();
        Livewire::withQueryParams(['task' => $this->task->id])->test('task-flyout')->assertDontSeeHtml('data-modal="task-panel"');
    }

    public function test_viewers_see_the_panel_read_only(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer);

        Livewire::withQueryParams(['task' => $this->task->id])->test('task-flyout')
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

    public function test_the_flyout_closes_when_its_task_was_deleted_and_stays_when_another_one_was(): void
    {
        $other = Task::factory()->for($this->project)->create();
        $flyout = $this->flyout()->call('openTask', $this->task->id);

        $other->delete();
        $flyout->dispatch('tasks-deleted')->assertSet('openTaskId', (string) $this->task->id);

        $this->task->delete();
        $flyout->dispatch('tasks-deleted')->assertSet('openTaskId', '');
        $this->flyout()->call('openTask', $other->id)->assertSet('openTaskId', '');
    }

    public function test_the_list_and_board_draw_again_when_the_flyout_reports_a_change(): void
    {
        $this->task->update(['title' => 'Angebot überarbeitet']);

        $this->list()->dispatch('task-changed')->assertSee('Angebot überarbeitet');
        $this->board()->dispatch('task-deleted')->assertSee('Angebot überarbeitet');
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

    public function test_whole_cards_and_rows_open_the_task(): void
    {
        // app.js opens the task for a click anywhere on an element with data-opens-task
        $this->list()->assertSeeHtml('data-opens-task="'.$this->task->id.'"');
        $this->board()->assertSeeHtml('data-opens-task="'.$this->task->id.'"');
    }

    public function test_task_titles_in_list_and_board_still_link_to_the_full_page_for_new_tabs(): void
    {
        $this->list()->assertSeeHtml('href="'.route('tasks.show', $this->task).'"');
        $this->board()->assertSeeHtml('href="'.route('tasks.show', $this->task).'"');
    }

    public function test_calendar_timeline_search_and_workload_open_tasks_in_the_flyout_too(): void
    {
        $task = Task::factory()->for($this->project)->create(['title' => 'Überall offen', 'due_date' => today()]);

        foreach (['pages::projects.calendar', 'pages::projects.timeline'] as $view) {
            Livewire::test($view, ['project' => $this->project])->assertSeeHtml('$openTask($event, '.$task->id.')');
        }

        $me = User::factory()->create();
        $this->project->setRole($me, ProjectRole::Editor);
        $task->update(['assignee_id' => $me->id]);
        $this->actingAs($me);

        Livewire::test('pages::search', ['query' => 'Überall'])->assertSeeHtml('$openTask($event, '.$task->id.')');
        Livewire::test('pages::workload')->assertSeeHtml('$openTask($event, '.$task->id.')');
    }
}
