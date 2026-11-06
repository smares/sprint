<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ListDoneFilterTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $editor;

    private Task $open;

    private Task $openInProgress;

    private Task $done;

    private Task $doneInProgress;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->editor = User::factory()->create();
        $this->project->setRole($this->editor, ProjectRole::Editor);

        $this->open = Task::factory()->for($this->project)->create(['title' => 'Alpha frisch']);
        $this->openInProgress = Task::factory()->for($this->project)->inProgress()->create(['title' => 'Beta laufend']);
        $this->done = Task::factory()->for($this->project)->done()->create(['title' => 'Gamma fertig']);
        $this->doneInProgress = Task::factory()->for($this->project)->inProgress()->done()->create(['title' => 'Delta laufend fertig']);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function list(array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->actingAs($this->editor)->test('pages::projects.show', ['project' => $this->project]);
    }

    public function test_the_list_shows_the_open_tasks_by_default(): void
    {
        $this->list()->assertSet('doneFilter', 'open')->assertSet('statusFilter', '')
            ->assertSee('Alpha frisch')->assertSee('Beta laufend')
            ->assertDontSee('Gamma fertig')->assertDontSee('Delta laufend fertig');
    }

    public function test_all_tasks_or_only_the_done_ones_can_be_shown(): void
    {
        $this->list()->set('doneFilter', 'all')
            ->assertSee('Alpha frisch')->assertSee('Beta laufend')->assertSee('Gamma fertig')->assertSee('Delta laufend fertig')
            ->set('doneFilter', 'done')
            ->assertSee('Gamma fertig')->assertSee('Delta laufend fertig')
            ->assertDontSee('Alpha frisch')->assertDontSee('Beta laufend');
    }

    public function test_the_done_filter_combines_with_the_status(): void
    {
        $inProgress = $this->project->statuses()->skip(1)->firstOrFail();

        $this->list()->set('statusFilter', (string) $inProgress->id)
            ->assertSee('Beta laufend')->assertDontSee('Delta laufend fertig')->assertDontSee('Alpha frisch')
            ->set('doneFilter', 'all')
            ->assertSee('Beta laufend')->assertSee('Delta laufend fertig')->assertDontSee('Gamma fertig')
            ->set('doneFilter', 'done')
            ->assertSee('Delta laufend fertig')->assertDontSee('Beta laufend');
    }

    public function test_the_done_menu_sits_in_the_toolbar_and_names_what_is_shown(): void
    {
        $page = $this->list()->assertSee('Offene Aufgaben')->assertSee('Alle Aufgaben')->assertSee('Erledigte Aufgaben')
            ->assertSeeHtml('aria-label="Aufgaben: Offene Aufgaben"');

        $page->set('doneFilter', 'done')->assertSeeHtml('aria-label="Aufgaben: Erledigte Aufgaben"');
        $page->set('doneFilter', 'all')->assertSeeHtml('aria-label="Aufgaben: Alle Aufgaben"');
    }

    public function test_the_filter_modal_has_no_select_for_the_done_filter_any_more(): void
    {
        $html = $this->list()->html();

        $this->assertSame(1, substr_count($html, 'wire:model.live="doneFilter"'), 'only the toolbar menu is bound to the done filter');
        $this->assertSame(1, substr_count($html, 'wire:model.live="statusFilter"'));
    }

    public function test_the_status_filter_shows_as_a_chip_that_removes_it(): void
    {
        $inProgress = $this->project->statuses()->skip(1)->firstOrFail();

        $page = $this->list()->set('doneFilter', 'all')->set('statusFilter', (string) $inProgress->id);

        $this->assertSame(["Status: {$inProgress->name}"], $page->instance()->activeFilters->pluck('label')->all());
        $this->assertSame('status', $page->instance()->activeFilters->first()['key']);
        $page->assertSee("Status: {$inProgress->name}")->assertSee('Beta laufend')->assertDontSee('Alpha frisch');

        $page->call('clearFilter', 'status')->assertSet('statusFilter', '')->assertSet('doneFilter', 'all')->assertSee('Alpha frisch');
        $this->assertSame(0, $page->instance()->activeFilters->count());
    }

    public function test_the_address_takes_the_done_filter(): void
    {
        $this->list(['done' => 'done'])->assertSet('doneFilter', 'done')->assertSee('Gamma fertig')->assertDontSee('Alpha frisch');
        $this->list(['done' => 'all'])->assertSet('doneFilter', 'all')->assertSee('Gamma fertig')->assertSee('Alpha frisch');
    }

    public function test_an_old_address_with_all_or_open_as_the_status_keeps_its_meaning(): void
    {
        $this->list(['status' => 'all'])->assertSet('statusFilter', '')->assertSet('doneFilter', 'all')->assertSee('Gamma fertig')->assertSee('Alpha frisch');
        $this->list(['status' => 'open'])->assertSet('statusFilter', '')->assertSet('doneFilter', 'open')->assertSee('Alpha frisch')->assertDontSee('Gamma fertig');
    }

    public function test_an_address_with_a_status_id_filters_by_that_status(): void
    {
        $inProgress = $this->project->statuses()->skip(1)->firstOrFail();

        $this->list(['status' => (string) $inProgress->id])->assertSet('statusFilter', (string) $inProgress->id)->assertSet('doneFilter', 'open')
            ->assertSee('Beta laufend')->assertDontSee('Delta laufend fertig');
    }

    public function test_resetting_goes_back_to_all_statuses_with_the_open_tasks(): void
    {
        $inProgress = $this->project->statuses()->skip(1)->firstOrFail();

        $this->list()->set('doneFilter', 'done')->set('statusFilter', (string) $inProgress->id)->set('assigneeFilter', 'me')
            ->call('resetFilters')
            ->assertSet('doneFilter', 'open')->assertSet('statusFilter', '')->assertSet('assigneeFilter', '');
    }

    public function test_headings_are_only_shown_while_done_tasks_are_not_the_only_thing_asked_for(): void
    {
        Task::factory()->for($this->project)->create(['title' => 'Abschnitt Eins', 'is_section' => true]);

        $this->list()->assertSee('Abschnitt Eins')
            ->set('doneFilter', 'all')->assertSee('Abschnitt Eins')
            ->set('doneFilter', 'done')->assertDontSee('Abschnitt Eins');
    }

    public function test_the_empty_state_offers_to_show_the_done_tasks_when_everything_is_done(): void
    {
        $project = Project::factory()->create();
        $project->setRole($this->editor, ProjectRole::Editor);
        Task::factory()->for($project)->done()->create(['title' => 'Nur fertig']);

        Livewire::actingAs($this->editor)->test('pages::projects.show', ['project' => $project])
            ->assertSee('Alle Aufgaben in diesem Projekt sind erledigt.')
            ->assertDontSee('Nur fertig')
            ->assertSee('Erledigte zeigen')
            ->call('$set', 'doneFilter', 'all')
            ->assertSee('Nur fertig');
    }

    public function test_the_empty_state_of_a_filter_offers_to_reset_it(): void
    {
        $this->list()->set('doneFilter', 'done')->set('assigneeFilter', 'me')->assertSee('Keine Aufgaben')->assertSee('Filter zurücksetzen');
    }

    public function test_done_tasks_are_dimmed_in_the_list_and_open_ones_are_not(): void
    {
        $html = $this->list()->set('doneFilter', 'all')->html();

        $classOf = function (Task $task) use ($html): string {
            preg_match('/<tr[^>]*data-task-id="'.$task->id.'"[^>]*class="([^"]*)"|<tr[^>]*class="([^"]*)"[^>]*data-task-id="'.$task->id.'"/', $html, $match);

            return $match[1] !== '' ? $match[1] : ($match[2] ?? '');
        };

        $this->assertStringContainsString('opacity-60 hover:opacity-100', $classOf($this->done));
        $this->assertStringContainsString('opacity-60 hover:opacity-100', $classOf($this->doneInProgress));
        $this->assertStringNotContainsString('opacity-60', $classOf($this->open));
        $this->assertStringNotContainsString('opacity-60', $classOf($this->openInProgress));
    }
}
