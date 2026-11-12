<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhoneToolbarTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->actingAs(User::factory()->admin()->create(['locale' => 'en']));
        $this->project = Project::factory()->create();
    }

    public function test_the_board_counts_and_names_what_is_set(): void
    {
        $board = Livewire::test('pages::projects.board', ['project' => $this->project]);

        // As it starts: nothing set, and the line says what is shown
        $this->assertSame(0, $board->instance()->displayCount);
        $this->assertNull($board->instance()->sortSummary);
        $board->assertSeeHtml('data-view-summary')->assertSee('All tasks');

        $board->set('sortBy', 'due')->set('doneFilter', 'open');
        $this->assertSame(2, $board->instance()->displayCount);
        $this->assertSame('Sorted by Due ↑', $board->instance()->sortSummary);
        $board->assertSee('Open tasks · Sorted by Due ↑');

        $board->call('reverseSort');
        $this->assertSame('Sorted by Due ↓', $board->instance()->sortSummary);
    }

    public function test_the_direction_picked_in_the_dialog_sorts_like_the_button(): void
    {
        $statusId = $this->project->defaultStatus()->id;
        $board = Livewire::test('pages::projects.board', ['project' => $this->project])->set('sortBy', 'due')->call('loadMoreInColumn', $statusId);

        $this->assertNotSame([], $board->get('columnLimits'));

        $board->set('sortDirection', 'desc');

        // As with reverseSort(): "load more" starts again from the first cards
        $this->assertSame([], $board->get('columnLimits'));
    }

    public function test_the_list_calendar_and_timeline_count_against_their_own_default(): void
    {
        $list = Livewire::test('pages::projects.show', ['project' => $this->project]);
        $this->assertSame('open', $list->instance()->shownDoneFilter);
        $list->assertSee('Open tasks');

        $list->set('doneFilter', 'all');
        $this->assertSame('all', $list->instance()->shownDoneFilter);

        foreach (['calendar', 'timeline'] as $page) {
            $view = Livewire::test("pages::projects.{$page}", ['project' => $this->project]);

            $this->assertSame('all', $view->instance()->shownDoneFilter, $page);
            $view->assertSee('All tasks');

            $view->set('doneFilter', 'done');
            $this->assertSame('done', $view->instance()->shownDoneFilter, $page);
        }
    }

    public function test_an_edited_address_with_a_wrong_filter_is_shown_as_the_default(): void
    {
        $board = Livewire::test('pages::projects.board', ['project' => $this->project])->set('doneFilter', 'bogus');

        // The view shows everything then, and the button and the line say that instead of a setting that is none
        $this->assertSame('all', $board->instance()->shownDoneFilter);
        $this->assertSame(0, $board->instance()->displayCount);
        $board->assertSee('All tasks');
    }

    public function test_the_csv_menu_is_in_the_title_row_for_readers_and_import_only_for_editors(): void
    {
        $page = Livewire::test('pages::projects.show', ['project' => $this->project]);

        $page->assertSee('Export as CSV')->assertSee('Import CSV');

        $reader = User::factory()->create();
        $this->project->setRole($reader, ProjectRole::Viewer);
        $this->actingAs($reader);

        Livewire::test('pages::projects.show', ['project' => $this->project])->assertSee('Export as CSV')->assertDontSee('Import CSV');
    }
}
