<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskCheckCellTest extends TestCase
{
    use RefreshDatabase;

    private const string CELL = 'grid shrink-0 place-items-center size-6 max-sm:size-10';

    private const string COMPACT_CELL = 'grid shrink-0 place-items-center size-6"';

    private const string SHOWN_WITH_THE_DONE_BUTTON = '<span class="contents" x-show="! $wire.selecting">';

    private const string SHOWN_WHILE_SELECTING = '<span class="contents" x-show="$wire.selecting" x-cloak>';

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 10:00:00');
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->user, ProjectRole::Editor);
        $this->actingAs($this->user);
    }

    private function task(array $attributes = [], ?Project $project = null): Task
    {
        return Task::factory()->for($project ?? $this->project)->create(['start_date' => '2026-10-10', 'due_date' => '2026-10-12', 'assignee_id' => $this->user->id] + $attributes);
    }

    private function viewedProject(): Project
    {
        $project = Project::factory()->create();
        $project->setRole($this->user, ProjectRole::Viewer);

        return $project;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function viewsWithCells(): array
    {
        return [
            'project list' => ['pages::projects.show', [], self::CELL],
            'project board' => ['pages::projects.board', [], self::COMPACT_CELL],
            'my tasks list' => ['pages::tasks.mine', [], self::CELL],
            'my tasks board' => ['pages::tasks.mine-board', [], self::COMPACT_CELL],
            'calendar agenda' => ['pages::projects.calendar', [], self::COMPACT_CELL],
            'timeline' => ['pages::projects.timeline', [], self::COMPACT_CELL],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function page(string $view, array $parameters = [], ?Project $project = null)
    {
        $needsProject = ! str_starts_with($view, 'pages::tasks.');

        return Livewire::test($view, $parameters + ($needsProject ? ['project' => $project ?? $this->project] : []));
    }

    /**
     * The markup without the blanks Blade leaves between attributes, so that tags can be compared as written in the templates.
     */
    private function squashed(string $html): string
    {
        return str_replace(' >', '>', preg_replace('/\s+/', ' ', $html));
    }

    /**
     * @dataProvider viewsWithCells
     */
    #[DataProvider('viewsWithCells')]
    public function test_the_done_button_and_the_box_share_one_cell_of_a_fixed_size(string $view, array $parameters, string $cell): void
    {
        $this->task(['title' => 'Gemeinsam']);

        $html = $this->squashed($this->page($view, $parameters)->html());

        $this->assertStringContainsString($cell, $html);
        $this->assertMatchesRegularExpression(
            '/place-items-center size-6[^"]*">\s*'.preg_quote(self::SHOWN_WITH_THE_DONE_BUTTON, '/').'.*?aria-label="Als erledigt markieren: Gemeinsam".*?'
            .preg_quote(self::SHOWN_WHILE_SELECTING, '/').'.*?aria-label="Aufgabe auswählen: Gemeinsam"/s',
            $html,
        );
    }

    public function test_only_the_cards_stay_at_24px_on_phones_too(): void
    {
        $this->task(['title' => 'Karte']);

        foreach (['pages::projects.show', 'pages::tasks.mine'] as $view) {
            $this->assertStringNotContainsString(self::COMPACT_CELL, $this->page($view)->html(), $view);
        }

        foreach (['pages::projects.board', 'pages::tasks.mine-board', 'pages::projects.calendar', 'pages::projects.timeline'] as $view) {
            $this->assertStringNotContainsString(self::CELL, $this->page($view)->html(), $view);
        }
    }

    /**
     * @dataProvider viewsWithCells
     */
    #[DataProvider('viewsWithCells')]
    public function test_every_task_has_its_box_before_the_selection_and_no_template_makes_it(string $view, array $parameters): void
    {
        $this->task(['title' => 'Eins']);
        $this->task(['title' => 'Zwei']);

        $page = $this->page($view, $parameters);
        $html = $this->squashed($page->assertSet('selecting', false)->html());

        $this->assertStringContainsString('aria-label="Aufgabe auswählen: Eins"', $html);
        $this->assertStringContainsString('aria-label="Aufgabe auswählen: Zwei"', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, self::SHOWN_WHILE_SELECTING));
        $this->assertGreaterThanOrEqual(2, substr_count($html, self::SHOWN_WITH_THE_DONE_BUTTON));

        // The cells and boxes of the rows are not made in a template (only the calendar chips on large screens still are)
        if ($view !== 'pages::projects.calendar') {
            $this->assertStringNotContainsString('x-if="$wire.selecting"', $html);
        }
    }

    /**
     * @dataProvider viewsWithCells
     */
    #[DataProvider('viewsWithCells')]
    public function test_the_cells_do_not_change_when_the_selection_starts(string $view, array $parameters): void
    {
        $this->task(['title' => 'Still']);

        $page = $this->page($view, $parameters);
        $before = substr_count($page->html(), 'aria-label="Aufgabe auswählen: Still"');

        $this->assertSame($before, substr_count($page->call('startSelecting')->html(), 'aria-label="Aufgabe auswählen: Still"'));
        $this->assertGreaterThan(0, $before);
    }

    public function test_viewers_get_no_box_and_their_done_button_is_not_hidden(): void
    {
        $readOnly = $this->viewedProject();
        $this->task(['title' => 'Nur lesen'], $readOnly);

        foreach (['pages::projects.show', 'pages::projects.board', 'pages::projects.calendar', 'pages::projects.timeline'] as $view) {
            $html = $this->squashed($this->page($view, [], $readOnly)->html());

            $this->assertStringContainsString('aria-label="Als erledigt markieren: Nur lesen"', $html, $view);
            $this->assertStringNotContainsString('aria-label="Aufgabe auswählen: Nur lesen"', $html, $view);
            $this->assertStringNotContainsString(self::SHOWN_WHILE_SELECTING, $html, $view);
            $this->assertStringNotContainsString(self::SHOWN_WITH_THE_DONE_BUTTON, $html, $view);
            $this->assertStringContainsString('<span class="contents">', $html, $view);
        }
    }

    public function test_my_tasks_of_a_project_one_may_not_edit_get_no_box_and_the_others_do(): void
    {
        $this->task(['title' => 'Eigene']);
        $this->task(['title' => 'Fremde'], $this->viewedProject());

        foreach (['pages::tasks.mine', 'pages::tasks.mine-board'] as $view) {
            $html = $this->squashed($this->page($view)->html());

            $this->assertStringContainsString('aria-label="Aufgabe auswählen: Eigene"', $html, $view);
            $this->assertStringNotContainsString('aria-label="Aufgabe auswählen: Fremde"', $html, $view);
            $this->assertStringContainsString('aria-label="Als erledigt markieren: Fremde"', $html, $view);
            $this->assertSame(1, substr_count($html, self::SHOWN_WHILE_SELECTING), $view);
            $this->assertSame(1, substr_count($html, self::SHOWN_WITH_THE_DONE_BUTTON), $view);
        }
    }

    public function test_the_list_offers_to_select_in_the_toolbar_for_editors_with_tasks(): void
    {
        $this->task(['title' => 'Eine']);

        $html = $this->page('pages::projects.show')->html();

        $this->assertMatchesRegularExpression('/<div[^>]*x-show="! \$wire\.selecting"[^>]*data-selection-toggle>/', $html);
        $this->assertStringContainsString('$wire.selecting = true; $wire.$refresh()', $html);
        $this->assertStringContainsString('Auswählen', $html);
        $this->assertLessThan(strpos($html, 'aria-label="Weitere Aktionen"'), strpos($html, 'x-show="! $wire.selecting"'));
        $this->assertStringNotContainsString('ends-when-empty', $html);
    }

    public function test_the_list_offers_no_selecting_without_tasks_or_for_viewers(): void
    {
        $toggle = '/x-show="! \$wire\.selecting"[^>]*data-selection-toggle>/';

        $this->assertDoesNotMatchRegularExpression($toggle, $this->page('pages::projects.show')->html());
        $this->page('pages::projects.show')->assertDontSee('Auswählen');

        $readOnly = $this->viewedProject();
        $this->task(['title' => 'Nur lesen'], $readOnly);

        $this->assertDoesNotMatchRegularExpression($toggle, $this->page('pages::projects.show', [], $readOnly)->html());
        $this->page('pages::projects.show', [], $readOnly)
            ->assertSee('Nur lesen')
            ->assertDontSee('Auswählen')
            ->assertDontSeeHtml('aria-label="Alle sichtbaren Aufgaben auswählen"')
            ->call('startSelecting')->assertForbidden();
    }

    public function test_the_button_of_the_list_starts_the_selection_with_the_bar(): void
    {
        $task = $this->task(['title' => 'Eine']);

        $this->page('pages::projects.show')
            ->assertSet('selecting', false)
            ->call('startSelecting')
            ->assertSet('selecting', true)
            ->assertSee('Auswahl beenden')
            ->assertSet('selected', [])
            ->set('selected', [(string) $task->id])
            ->call('stopSelecting')
            ->assertSet('selecting', false);
    }

    public function test_the_head_of_the_list_has_the_done_label_and_the_box_that_selects_the_page(): void
    {
        $one = $this->task(['title' => 'Eins']);
        $two = $this->task(['title' => 'Zwei']);

        $page = $this->page('pages::projects.show');
        $html = $this->squashed($page->html());
        $start = (int) strpos($html, 'data-flux-table');
        $head = substr($html, $start, strpos($html, '</thead>', $start) - $start);

        $this->assertMatchesRegularExpression('/<span class="sr-only" x-show="! \$wire\.selecting">Erledigt<\/span>/', $head);
        $this->assertMatchesRegularExpression('/size-6 items-center justify-center"[^>]*x-show="\$wire\.selecting"[^>]*>\s*<ui-checkbox[^>]*aria-label="Alle sichtbaren Aufgaben auswählen"/', $head);
        $this->assertStringContainsString('wire:click="togglePage"', $head);

        $page->call('togglePage')
            ->assertSet('selecting', true)
            ->assertSet('selected', [(string) $one->id, (string) $two->id]);
    }

    public function test_the_head_of_the_list_has_no_box_for_viewers_and_the_done_label_stays(): void
    {
        $readOnly = $this->viewedProject();
        $this->task(['title' => 'Nur lesen'], $readOnly);

        $html = $this->squashed($this->page('pages::projects.show', [], $readOnly)->html());

        $this->assertStringContainsString('<span class="sr-only">Erledigt</span>', $html);
        $this->assertStringNotContainsString('wire:click="togglePage"', $html);
    }

    public function test_the_first_column_of_the_list_is_narrow_and_the_title_is_pinned_next_to_it(): void
    {
        $this->task(['title' => 'Schmal']);

        $html = $this->page('pages::projects.show')->html();

        $this->assertStringContainsString('w-12 min-w-12 max-sm:w-16 max-sm:min-w-16', $html);
        $this->assertStringContainsString('pinned-column sm:start-12', $html);
        $this->assertStringNotContainsString('sm:start-20', $html);
        $this->assertStringNotContainsString('w-20 min-w-20', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function viewsThatMakeRoomForTheBar(): array
    {
        return [
            'project list' => ['pages::projects.show'],
            'project board' => ['pages::projects.board'],
            'my tasks list' => ['pages::tasks.mine'],
            'my tasks board' => ['pages::tasks.mine-board'],
            'timeline' => ['pages::projects.timeline'],
        ];
    }

    /**
     * @dataProvider viewsThatMakeRoomForTheBar
     */
    #[DataProvider('viewsThatMakeRoomForTheBar')]
    public function test_the_page_marks_itself_while_the_bar_floats_over_it(string $view): void
    {
        $this->task(['title' => 'Eine']);

        $page = $this->page($view);
        $root = '/wire:name="'.preg_quote($view, '/').'"\s+data-bar-floats\s/';

        $this->assertDoesNotMatchRegularExpression('/\sdata-bar-floats[\s>]/', $page->html());
        $this->assertStringContainsString('x-bind:data-bar-floats="$wire.selecting"', $page->html());
        $this->assertStringNotContainsString('pb-40', $page->html());

        $selecting = $page->call('startSelecting')->html();
        $this->assertMatchesRegularExpression($root, $selecting);
        $this->assertStringNotContainsString('pb-40 sm:pb-16', $selecting);

        $this->assertDoesNotMatchRegularExpression('/\sdata-bar-floats[\s>]/', $page->call('stopSelecting')->html());
    }

    public function test_the_calendar_still_makes_room_with_padding_classes(): void
    {
        $this->task(['title' => 'Eine']);

        foreach (['pages::projects.calendar'] as $view) {
            $page = $this->page($view);

            $this->assertDoesNotMatchRegularExpression('/\sdata-bar-floats[\s>]/', $page->html(), $view);
            $this->assertStringContainsString("x-bind:class=\"{ 'pb-40 sm:pb-16': \$wire.selecting }\"", $page->html(), $view);
            $this->assertMatchesRegularExpression('/class="pb-40 sm:pb-16"/', $page->call('startSelecting')->html(), $view);
            $this->assertDoesNotMatchRegularExpression('/\sdata-bar-floats[\s>]/', $page->html(), $view);
        }
    }

    public function test_the_quick_add_rows_stay_in_place_but_are_invisible_while_selecting(): void
    {
        $this->task(['title' => 'Eine']);
        $quickAdd = '/<div\s+x-data="\{\s*adding: false.*?\n\s*\}"\s*([^>]*)>/s';

        foreach (['pages::projects.show', 'pages::projects.board'] as $view) {
            foreach ([false, true] as $selecting) {
                $page = $this->page($view);
                $html = ($selecting ? $page->call('startSelecting') : $page)->html();

                $this->assertGreaterThan(0, preg_match_all($quickAdd, $html, $matches), $view);

                foreach ($matches[1] as $attributes) {
                    $this->assertStringContainsString("x-bind:class=\"{ 'invisible': \$wire.selecting }\"", $attributes, $view);
                    $this->assertStringNotContainsString('x-show', $attributes, $view);
                }
            }
        }
    }

    public function test_the_headers_of_my_tasks_keep_their_height_when_the_button_goes(): void
    {
        $this->task(['title' => 'Eine']);

        foreach (['pages::tasks.mine', 'pages::tasks.mine-board'] as $view) {
            $this->page($view)->assertSeeHtml('mb-6 flex min-h-10 items-center justify-between gap-3');
        }
    }
}
