<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class BoardDoneFilterTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private int $openId;

    private int $progressId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        $this->openId = $this->project->statuses[0]->id;
        $this->progressId = $this->project->statuses[1]->id;
    }

    private function task(string $title, bool $done = false, ?int $statusId = null): Task
    {
        $task = Task::factory()->for($this->project);

        return ($done ? $task->done() : $task)->create(['title' => $title, 'status_id' => $statusId ?? $this->openId]);
    }

    private function board(array $parameters = []): Testable
    {
        return Livewire::test('pages::projects.board', ['project' => $this->project] + $parameters);
    }

    /**
     * @return list<string>
     */
    private function column(mixed $board, int $statusId): array
    {
        return $board->instance()->columns[$statusId]->pluck('title')->all();
    }

    public function test_all_tasks_are_shown_by_default_and_the_done_ones_stay_in_their_own_column(): void
    {
        $this->task('Aufgabe offen');
        $this->task('Aufgabe erledigt', true);
        $this->task('Läuft', false, $this->progressId);
        $this->task('Läuft fertig', true, $this->progressId);

        $board = $this->board()->assertSet('doneFilter', 'all');

        $this->assertEqualsCanonicalizing(['Aufgabe offen', 'Aufgabe erledigt'], $this->column($board, $this->openId));
        $this->assertEqualsCanonicalizing(['Läuft', 'Läuft fertig'], $this->column($board, $this->progressId));
        $this->assertSame([$this->openId => 2, $this->progressId => 2], $board->instance()->columnTotals);
        $this->assertCount(2, $board->instance()->statuses);
    }

    public function test_completing_a_card_keeps_it_in_its_column(): void
    {
        $task = $this->task('Wird fertig', false, $this->progressId);

        $board = $this->board();
        $task->refresh()->toggleDone();
        $board->dispatch('task-changed');

        $this->assertTrue($task->fresh()->isDone());
        $this->assertSame($this->progressId, $task->fresh()->status_id);
        $this->assertSame(['Wird fertig'], $this->column($board, $this->progressId));
    }

    public function test_open_hides_the_done_tasks_and_the_totals_follow(): void
    {
        $this->task('Aufgabe offen');
        $this->task('Aufgabe erledigt', true);
        $this->task('Läuft fertig', true, $this->progressId);

        $board = $this->board()->set('doneFilter', 'open');

        $this->assertSame(['Aufgabe offen'], $this->column($board, $this->openId));
        $this->assertSame([], $this->column($board, $this->progressId));
        $this->assertSame([$this->openId => 1, $this->progressId => 0], $board->instance()->columnTotals);
        $board->assertDontSee('Aufgabe erledigt')->assertDontSee('Läuft fertig');
    }

    public function test_done_shows_only_the_done_tasks(): void
    {
        $this->task('Aufgabe offen');
        $this->task('Aufgabe erledigt', true);
        $this->task('Läuft fertig', true, $this->progressId);

        $board = $this->board()->set('doneFilter', 'done');

        $this->assertSame(['Aufgabe erledigt'], $this->column($board, $this->openId));
        $this->assertSame(['Läuft fertig'], $this->column($board, $this->progressId));
        $this->assertSame([$this->openId => 1, $this->progressId => 1], $board->instance()->columnTotals);
        $board->assertDontSee('Aufgabe offen');
    }

    public function test_the_count_badges_of_the_columns_follow_the_filter(): void
    {
        foreach (range(1, 3) as $i) {
            $this->task("Offen $i");
        }
        $this->task('Aufgabe erledigt', true);

        $this->assertSame(4, $this->board()->instance()->columnTotals[$this->openId]);
        $this->assertSame(3, $this->board(['doneFilter' => 'open'])->instance()->columnTotals[$this->openId]);
    }

    public function test_loading_more_in_a_column_counts_only_the_shown_tasks(): void
    {
        foreach (range(1, 35) as $i) {
            $this->task(sprintf('Offen %02d', $i));
        }
        foreach (range(1, 40) as $i) {
            $this->task(sprintf('Fertig %02d', $i), true);
        }

        $board = $this->board()->set('doneFilter', 'open')
            ->assertSee('Mehr laden (30 von 35)');
        $board->call('loadMoreInColumn', $this->openId)->assertDontSee('Mehr laden')->assertSee('Offen 35');
        $this->assertCount(35, $board->instance()->columns[$this->openId]);

        $this->board()->set('doneFilter', 'done')->assertSee('Mehr laden (30 von 40)');
        $this->board()->assertSee('Mehr laden (30 von 75)');
    }

    public function test_a_dropped_card_is_placed_among_the_shown_tasks(): void
    {
        $first = $this->task('Eins', false, $this->progressId);
        $this->task('Erledigt dazwischen', true, $this->progressId);
        $second = $this->task('Zwei', false, $this->progressId);
        $moved = $this->task('Gezogen');
        $first->update(['position' => 0]);
        $second->update(['position' => 2]);

        $board = $this->board()->set('doneFilter', 'open')
            ->call('moveTask', $moved->id, 1, (string) $this->progressId);

        $this->assertSame($this->progressId, $moved->fresh()->status_id);
        $this->assertSame(['Eins', 'Gezogen', 'Zwei'], $this->column($board, $this->progressId));
        $this->assertFalse($moved->fresh()->isDone());
    }

    public function test_finished_cards_come_after_open_ones_and_each_group_keeps_its_manual_order(): void
    {
        $this->task('Offen C')->update(['position' => 3]);
        $this->task('Fertig A', true)->update(['position' => 0]);
        $this->task('Offen A')->update(['position' => 1]);
        $this->task('Fertig B', true)->update(['position' => 2]);
        $this->task('Offen B')->update(['position' => 4]);

        $board = $this->board()->assertSet('doneFilter', 'all');

        $this->assertSame(['Offen A', 'Offen C', 'Offen B', 'Fertig A', 'Fertig B'], $this->column($board, $this->openId));
        $this->assertSame(['Offen A', 'Offen C', 'Offen B'], $this->column($board->set('doneFilter', 'open'), $this->openId));
        $this->assertSame(['Fertig A', 'Fertig B'], $this->column($board->set('doneFilter', 'done'), $this->openId));
    }

    public function test_finishing_a_card_moves_it_behind_the_open_ones_of_its_column(): void
    {
        $first = $this->task('Eins');
        $this->task('Zwei');
        $first->update(['position' => 0]);

        $board = $this->board();
        $first->refresh()->toggleDone();
        $board->dispatch('task-changed');

        $this->assertSame(['Zwei', 'Eins'], $this->column($board, $this->openId));

        $first->refresh()->toggleDone();
        $board->dispatch('task-changed');

        $this->assertSame(['Eins', 'Zwei'], $this->column($board, $this->openId));
    }

    public function test_a_dropped_card_lands_among_the_shown_cards_when_finished_ones_are_there_too(): void
    {
        $this->task('Eins', false, $this->progressId)->update(['position' => 1]);
        $this->task('Erledigt', true, $this->progressId)->update(['position' => 0]);
        $this->task('Zwei', false, $this->progressId)->update(['position' => 2]);
        $moved = $this->task('Gezogen');

        $board = $this->board()->assertSet('doneFilter', 'all');
        $this->assertSame(['Eins', 'Zwei', 'Erledigt'], $this->column($board, $this->progressId));

        $board->call('moveTask', $moved->id, 1, (string) $this->progressId);

        $this->assertSame($this->progressId, $moved->fresh()->status_id);
        $this->assertSame(['Eins', 'Gezogen', 'Zwei', 'Erledigt'], $this->column($board, $this->progressId));
        $this->assertFalse($moved->fresh()->isDone());

        $other = $this->task('Ganz vorn');
        $board->call('moveTask', $other->id, 0, (string) $this->progressId);

        $this->assertSame(['Ganz vorn', 'Eins', 'Gezogen', 'Zwei', 'Erledigt'], $this->column($board, $this->progressId));
    }

    public function test_dropping_a_finished_card_in_the_middle_keeps_the_open_cards_in_front(): void
    {
        $this->task('Eins', false, $this->progressId)->update(['position' => 0]);
        $this->task('Zwei', false, $this->progressId)->update(['position' => 1]);
        $done = $this->task('Fertig', true);

        $board = $this->board()->call('moveTask', $done->id, 1, (string) $this->progressId);

        $this->assertSame($this->progressId, $done->fresh()->status_id);
        $this->assertTrue($done->fresh()->isDone());
        $this->assertSame(['Eins', 'Zwei', 'Fertig'], $this->column($board, $this->progressId));
    }

    public function test_loading_more_counts_the_open_cards_first(): void
    {
        foreach (range(1, 35) as $i) {
            $this->task(sprintf('Offen %02d', $i));
        }
        foreach (range(1, 40) as $i) {
            $this->task(sprintf('Fertig %02d', $i), true);
        }

        $board = $this->board()->assertSee('Mehr laden (30 von 75)');
        $titles = $this->column($board, $this->openId);
        $this->assertCount(30, $titles);
        $this->assertSame(array_fill(0, 30, 'Offen'), array_map(fn (string $title) => explode(' ', $title)[0], $titles));
        $board->assertDontSee('Fertig 01');

        $board->call('loadMoreInColumn', $this->openId);
        $titles = $this->column($board, $this->openId);
        $this->assertCount(60, $titles);
        $this->assertSame(35, count(array_filter($titles, fn (string $title) => str_starts_with($title, 'Offen'))));
        $this->assertSame('Offen 35', $titles[34]);
        $this->assertSame('Fertig 01', $titles[35]);
        $this->assertSame('Fertig 25', $titles[59]);
    }

    public function test_the_address_sets_the_filter(): void
    {
        $this->task('Aufgabe offen');
        $this->task('Aufgabe erledigt', true);

        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('projects.board', [$this->project, 'done' => 'done']))->assertOk()->assertSee('Aufgabe erledigt')->assertDontSee('Aufgabe offen')->assertSee('Erledigte Aufgaben');
        $this->get(route('projects.board', [$this->project, 'done' => 'open']))->assertOk()->assertSee('Aufgabe offen')->assertDontSee('Aufgabe erledigt')->assertSee('Offene Aufgaben');
        $this->get(route('projects.board', $this->project))->assertOk()->assertSee('Aufgabe offen')->assertSee('Aufgabe erledigt')->assertSee('Alle Aufgaben');
    }

    public function test_a_saved_view_stores_and_restores_the_filter(): void
    {
        $this->task('Aufgabe offen');
        $this->task('Aufgabe erledigt', true);

        $this->board()->set('doneFilter', 'open')->set('saveName', 'Nur offen')->call('saveCurrentView')->assertHasNoErrors();

        $saved = SavedFilter::query()->where('name', 'Nur offen')->sole();
        $this->assertSame('open', $saved->filters['done']);

        $this->board()->assertSet('doneFilter', 'all')->call('applyView', $saved->id)->assertSet('doneFilter', 'open');
    }

    public function test_a_saved_view_without_the_setting_or_with_a_bad_one_shows_everything(): void
    {
        $without = $this->project->savedFilters()->create(['user_id' => auth()->id(), 'name' => 'Alt', 'view' => SavedFilter::BOARD, 'filters' => ['sort' => '', 'direction' => 'asc']]);
        $bad = $this->project->savedFilters()->create(['user_id' => auth()->id(), 'name' => 'Kaputt', 'view' => SavedFilter::BOARD, 'filters' => ['done' => 'weg']]);

        $this->board(['doneFilter' => 'done'])->call('applyView', $without->id)->assertSet('doneFilter', 'all');
        $this->board(['doneFilter' => 'done'])->call('applyView', $bad->id)->assertSet('doneFilter', 'all');
    }

    public function test_only_the_shown_tasks_can_be_selected(): void
    {
        $open = $this->task('Aufgabe offen');
        $done = $this->task('Aufgabe erledigt', true);

        $this->board()->set('doneFilter', 'open')
            ->call('startSelecting')
            ->set('selected', [(string) $open->id, (string) $done->id])
            ->assertSet('selectedIds', [$open->id])
            ->call('bulkDelete');

        $this->assertModelMissing($open);
        $this->assertModelExists($done);
    }

    public function test_changing_the_filter_ends_the_selection_of_tasks(): void
    {
        $open = $this->task('Aufgabe offen');

        $this->board()->call('startSelecting')->set('selected', [(string) $open->id])->set('doneFilter', 'done')->assertSet('selected', []);
    }

    public function test_done_cards_are_dimmed_and_open_ones_are_not(): void
    {
        $open = $this->task('Aufgabe offen');
        $done = $this->task('Aufgabe erledigt', true);

        $html = $this->board()->html();

        $this->assertSame(1, substr_count($html, 'opacity-60 hover:opacity-100'));
        $this->assertMatchesRegularExpression('/<[^>]*data-task-id="'.$done->id.'"[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<[^>]*opacity-60 hover:opacity-100[^>]*data-task-id="'.$open->id.'"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<[^>]*data-task-id="'.$open->id.'"[^>]*opacity-60/', $html);
    }

    public function test_the_toolbar_offers_the_three_choices(): void
    {
        $this->board()->assertSee('Alle Aufgaben')->assertSee('Offene Aufgaben')->assertSee('Erledigte Aufgaben')
            ->assertSeeHtml('wire:model.live="doneFilter"');
        $this->board(['doneFilter' => 'open'])->assertSeeHtml('Offene Aufgaben');
    }
}
