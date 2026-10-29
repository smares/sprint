<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\PersonalColumn;
use App\Models\PersonalPlacement;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyTasksBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
        $this->project = Project::factory()->create();
    }

    private function mine(string $title, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create(['title' => $title, 'assignee_id' => $this->user->id] + $attributes);
    }

    private function column(string $name = 'Heute', ?User $user = null): PersonalColumn
    {
        $user ??= $this->user;

        return PersonalColumn::factory()->create(['user_id' => $user->id, 'name' => $name, 'position' => $user->personalColumns()->count()]);
    }

    private function board(): mixed
    {
        return Livewire::test('pages::tasks.mine-board');
    }

    /**
     * @return list<string>
     */
    private function titles(mixed $board, int $column): array
    {
        return $board->instance()->columns[$column]->pluck('title')->all();
    }

    public function test_tasks_nobody_put_in_a_column_are_unsorted_in_the_order_of_their_due_dates(): void
    {
        $this->mine('Spät', ['due_date' => '2026-12-01']);
        $this->mine('Ohne Termin');
        $this->mine('Früh', ['due_date' => '2026-11-01']);
        Task::factory()->for($this->project)->create(['title' => 'Nicht meine']);

        $this->assertSame(['Früh', 'Spät', 'Ohne Termin'], $this->titles($this->board(), 0));
    }

    public function test_the_board_has_a_switch_to_the_list_and_back(): void
    {
        $this->mine('Eins');

        $this->get(route('tasks.mine.board'))->assertOk()->assertSee(route('tasks.mine'));
        $this->get(route('tasks.mine'))->assertOk()->assertSee(route('tasks.mine.board'));
    }

    public function test_a_column_is_added_at_the_end_with_a_trimmed_name_and_nothing_for_an_empty_one(): void
    {
        $board = $this->board()
            ->set('newColumnName', '  Heute  ')->call('addColumn')
            ->set('newColumnName', 'Später')->call('addColumn')
            ->set('newColumnName', '   ')->call('addColumn');

        $this->assertSame(['Heute', 'Später'], $this->user->personalColumns()->pluck('name')->all());
        $this->assertSame('', $board->get('newColumnName'));
    }

    public function test_the_length_of_a_name_and_the_number_of_columns_are_limited(): void
    {
        $board = $this->board()->set('newColumnName', str_repeat('ä', 100))->call('addColumn');

        $this->assertSame(PersonalColumn::MAX_NAME_LENGTH, mb_strlen($this->user->personalColumns()->value('name')));

        PersonalColumn::factory()->count(PersonalColumn::MAX_PER_USER - 1)->create(['user_id' => $this->user->id]);
        $board->set('newColumnName', 'Eine zu viel')->call('addColumn');

        $this->assertSame(PersonalColumn::MAX_PER_USER, $this->user->personalColumns()->count());
    }

    public function test_dragging_puts_a_task_into_a_column_at_the_place_and_keeps_the_order_there(): void
    {
        $a = $this->mine('A');
        $b = $this->mine('B');
        $c = $this->mine('C');
        $column = $this->column();

        $board = $this->board()
            ->call('moveTask', $a->id, 0, (string) $column->id)
            ->call('moveTask', $b->id, 1, (string) $column->id)
            ->call('moveTask', $c->id, 0, (string) $column->id);

        $this->assertSame(['C', 'A', 'B'], $this->titles($board, $column->id));
        $this->assertSame([], $this->titles($board, 0));

        $board->call('moveTask', $b->id, 0, (string) $column->id);

        $this->assertSame(['B', 'C', 'A'], $this->titles($board, $column->id));
        $this->assertSame([0, 1, 2], $column->placements()->orderBy('position')->pluck('position')->all());
    }

    public function test_a_task_moves_between_columns_and_back_to_unsorted(): void
    {
        $task = $this->mine('Eins');
        $first = $this->column('Erste');
        $second = $this->column('Zweite');

        $board = $this->board()
            ->call('moveTask', $task->id, 0, (string) $first->id)
            ->call('moveTask', $task->id, 0, (string) $second->id);

        $this->assertSame([], $this->titles($board, $first->id));
        $this->assertSame(['Eins'], $this->titles($board, $second->id));
        $this->assertSame(1, PersonalPlacement::count());

        $board->call('moveTask', $task->id, 0, '0');

        $this->assertSame(['Eins'], $this->titles($board, 0));
        $this->assertSame(0, PersonalPlacement::count());
    }

    public function test_the_places_are_one_person_s_own(): void
    {
        $task = $this->mine('Gemeinsam');
        $other = User::factory()->admin()->create();
        $this->board()->call('moveTask', $task->id, 0, (string) $this->column()->id);

        $task->update(['assignee_id' => $other->id]);
        $this->actingAs($other);

        $this->assertSame(['Gemeinsam'], $this->titles($this->board(), 0));
    }

    public function test_the_column_of_somebody_else_cannot_be_used(): void
    {
        $task = $this->mine('Eins');
        $foreign = $this->column('Fremd', User::factory()->create());

        $this->board()->call('moveTask', $task->id, 0, (string) $foreign->id)->assertStatus(404);

        $this->assertSame(0, PersonalPlacement::count());
    }

    public function test_only_one_s_own_open_tasks_can_be_placed(): void
    {
        $foreign = Task::factory()->for($this->project)->create();
        $column = $this->column();

        $this->board()->call('moveTask', $foreign->id, 0, (string) $column->id)->assertStatus(404);

        $this->assertSame(0, PersonalPlacement::count());
    }

    public function test_deleting_a_column_sends_its_tasks_back_to_unsorted(): void
    {
        $task = $this->mine('Eins');
        $keep = $this->column('Bleibt');
        $gone = $this->column('Weg');
        $this->board()->call('moveTask', $task->id, 0, (string) $gone->id);

        $board = $this->board()->call('deleteColumn', $gone->id);

        $this->assertSame(['Eins'], $this->titles($board, 0));
        $this->assertSame(0, PersonalPlacement::count());
        $this->assertSame([$keep->id], $this->user->personalColumns()->pluck('id')->all());
    }

    public function test_one_cannot_delete_or_rename_the_column_of_somebody_else(): void
    {
        $foreign = $this->column('Fremd', User::factory()->create());

        $this->board()->call('renameColumn', $foreign->id, 'Meins')->assertStatus(404);
        $this->board()->call('deleteColumn', $foreign->id)->assertStatus(404);

        $this->assertSame('Fremd', $foreign->fresh()->name);
    }

    public function test_a_column_is_renamed_and_moved_one_place_at_a_time(): void
    {
        $a = $this->column('A');
        $b = $this->column('B');
        $c = $this->column('C');

        $this->board()
            ->call('renameColumn', $a->id, '  Neu  ')
            ->call('renameColumn', $b->id, '   ')
            ->call('moveColumn', $c->id, 'left');

        $this->assertSame(['Neu', 'C', 'B'], $this->user->personalColumns()->pluck('name')->all());

        $this->board()->call('moveColumn', $a->id, 'left')->call('moveColumn', $b->id, 'right');

        $this->assertSame(['Neu', 'C', 'B'], $this->user->personalColumns()->pluck('name')->all());

        $this->board()->call('moveColumn', $a->id, 'right');

        $this->assertSame(['C', 'Neu', 'B'], $this->user->personalColumns()->pluck('name')->all());
    }

    public function test_a_done_task_leaves_the_board_and_a_deleted_task_leaves_its_place(): void
    {
        $done = $this->mine('Erledigt');
        $deleted = $this->mine('Gelöscht');
        $column = $this->column();
        $this->board()->call('moveTask', $done->id, 0, (string) $column->id)->call('moveTask', $deleted->id, 1, (string) $column->id);

        $board = $this->board()->call('toggleDone', $done->id);

        $this->assertSame(['Gelöscht'], $this->titles($board, $column->id));

        $deleted->delete();

        $this->assertSame(1, PersonalPlacement::count());
    }

    public function test_the_place_counts_the_visible_cards_when_a_done_task_still_has_a_place_in_the_column(): void
    {
        $a = $this->mine('A');
        $b = $this->mine('B');
        $c = $this->mine('C');
        $d = $this->mine('D');
        $column = $this->column();
        $board = $this->board()
            ->call('moveTask', $a->id, 0, (string) $column->id)
            ->call('moveTask', $b->id, 1, (string) $column->id)
            ->call('moveTask', $c->id, 2, (string) $column->id)
            ->call('toggleDone', $a->id);

        $this->assertSame(['B', 'C'], $this->titles($board, $column->id));

        $board->call('moveTask', $d->id, 1, (string) $column->id);

        $this->assertSame(['B', 'D', 'C'], $this->titles($board, $column->id));
    }

    public function test_columns_show_a_page_of_cards_and_load_more_on_request(): void
    {
        foreach (range(1, 35) as $number) {
            $this->mine(sprintf('Aufgabe %02d', $number), ['due_date' => '2026-11-'.sprintf('%02d', min($number, 28))]);
        }

        $board = $this->board();

        $this->assertSame(30, $board->instance()->columns[0]->count());
        $this->assertSame(35, $board->instance()->columnTotals[0]);

        $board->call('loadMoreInColumn', 0);

        $this->assertSame(35, $board->instance()->columns[0]->count());
    }

    public function test_the_list_still_shows_the_tasks_and_can_tick_them_off(): void
    {
        $task = $this->mine('Eins');

        Livewire::test('pages::tasks.mine')
            ->assertSee('Eins')
            ->call('toggleDone', $task->id)
            ->assertDontSee('Eins');
    }

    public function test_the_card_menu_moves_a_task_to_the_end_of_another_column_without_dragging(): void
    {
        $heute = $this->column('Heute');
        $erste = $this->mine('Erste');
        $zweite = $this->mine('Zweite');
        $heute->place($erste, 0, []);

        $board = $this->board()
            ->assertSeeHtml('data-card-menu="'.$zweite->id.'"')
            ->assertSeeHtml('data-card-menu="'.$erste->id.'"')
            ->assertSee('In Spalte verschieben');

        $board->call('moveTask', $zweite->id, 100000, (string) $heute->id);

        $this->assertSame(['Erste', 'Zweite'], $this->titles($board, $heute->id));
        $this->assertSame([], $this->titles($board, 0));

        $board->call('moveTask', $erste->id, 100000, '0');

        $this->assertSame(['Erste'], $this->titles($board, 0));
    }

    public function test_the_columns_of_the_card_menu_are_in_the_page_once_not_once_for_every_card(): void
    {
        $heute = $this->column('Heute');
        $spaeter = $this->column('Später');

        foreach (range(1, 5) as $number) {
            $this->mine("Aufgabe {$number}");
        }

        $html = $this->board()->html();

        // One menu for the board: its entries are made in the browser from the columns that stand once around the cards
        $this->assertSame(1, substr_count($html, 'In Spalte verschieben'));
        $this->assertSame(5, substr_count($html, 'data-card-menu="'));
        $this->assertSame(1, substr_count($html, 'data-card-menu-list'));
        $this->assertSame(0, substr_count($html, 'moveTask('));
        $this->assertSame(
            [['id' => 0, 'name' => 'Unsortiert'], ['id' => $heute->id, 'name' => 'Heute'], ['id' => $spaeter->id, 'name' => 'Später']],
            json_decode(html_entity_decode((string) str($html)->after('data-move-targets="')->before('"')), true),
        );
    }

    public function test_a_card_button_tells_where_the_card_is_for_moving_up_and_down_within_its_column(): void
    {
        $heute = $this->column('Heute');
        $a = $this->mine('A');
        $b = $this->mine('B');
        $heute->place($a, 0, []);
        $heute->place($b, 1, [$a->id]);

        $board = $this->board()
            ->assertSeeHtml('data-card-menu="'.$a->id.'" data-column="'.$heute->id.'" data-index="0" data-last="0"')
            ->assertSeeHtml('data-card-menu="'.$b->id.'" data-column="'.$heute->id.'" data-index="1" data-last="1"')
            ->assertSee('Nach oben')
            ->assertSee('Nach unten');

        $board->call('moveTask', $a->id, 1, (string) $heute->id);

        $this->assertSame(['B', 'A'], $this->titles($board, $heute->id));
        $board->assertSeeHtml('data-card-menu="'.$a->id.'" data-column="'.$heute->id.'" data-index="1" data-last="1"');
    }

    public function test_every_card_that_can_be_dragged_has_the_menu_whatever_the_role_in_its_project(): void
    {
        $person = User::factory()->create();
        $this->actingAs($person);
        $heute = $this->column('Heute', $person);

        $viewing = Project::factory()->create();
        $viewing->setRole($person, ProjectRole::Viewer);
        $archived = Project::factory()->create(['archived_at' => now()]);
        $archived->setRole($person, ProjectRole::Admin);

        $asViewer = Task::factory()->for($viewing)->create(['title' => 'Nur gelesen', 'assignee_id' => $person->id]);
        $inArchive = Task::factory()->for($archived)->create(['title' => 'Im Archiv', 'assignee_id' => $person->id]);

        $board = $this->board()
            ->assertSeeHtml('data-card-menu="'.$asViewer->id.'"')
            ->assertSeeHtml('data-card-menu="'.$inArchive->id.'"')
            ->assertSeeHtml('wire:sort:item="'.$asViewer->id.'"')
            ->assertSeeHtml('wire:sort:item="'.$inArchive->id.'"');

        // The place is the person's own, so it needs no right to edit the project
        $board->call('moveTask', $asViewer->id, 100000, (string) $heute->id)
            ->call('moveTask', $inArchive->id, 100000, (string) $heute->id);

        $this->assertSame(['Nur gelesen', 'Im Archiv'], $this->titles($board, $heute->id));
        $this->assertSame(2, PersonalPlacement::where('user_id', $person->id)->count());
    }

    public function test_a_task_of_a_project_one_only_views_can_be_placed_only_while_it_is_one_s_own_open_task(): void
    {
        $person = User::factory()->create();
        $this->actingAs($person);
        $heute = $this->column('Heute', $person);

        $viewing = Project::factory()->create();
        $viewing->setRole($person, ProjectRole::Viewer);

        $notAssigned = Task::factory()->for($viewing)->create();
        $done = Task::factory()->for($viewing)->done()->create(['assignee_id' => $person->id]);
        $hidden = Task::factory()->for(Project::factory()->create())->create(['assignee_id' => $person->id]);

        foreach ([$notAssigned, $done, $hidden] as $task) {
            $this->board()->call('moveTask', $task->id, 0, (string) $heute->id)->assertStatus(404);
        }

        $this->assertSame(0, PersonalPlacement::count());
    }

    public function test_every_column_can_be_collapsed_and_is_remembered_on_the_server(): void
    {
        $own = $this->column('Heute');
        auth()->user()->rememberInterface(['mine_board_collapsed' => [$own->id => true]]);

        $this->get(route('tasks.mine.board'))
            ->assertOk()
            ->assertSee('?? ['.$own->id.']', false)
            // "Unsorted" (key 0) and the person's own columns alike
            ->assertSee('x-bind:data-collapsed="collapsed.includes(0)"', false)
            ->assertSee('x-bind:data-collapsed="collapsed.includes('.$own->id.')"', false)
            ->assertSee('x-on:click.stop="toggle(0)"', false)
            ->assertSee('x-on:click.stop="toggle('.$own->id.')"', false)
            // the label names the column, there is one of these buttons for each of them
            ->assertSee('Spalte Heute einklappen', false)
            ->assertSee('Spalte Unsortiert ausklappen', false)
            ->assertSee('Spalte einklappen')
            ->assertSee('Spalte ausklappen')
            // the menu of an own column is not on the strip of a collapsed one
            ->assertSee('x-show="! collapsed.includes('.$own->id.')"', false);
    }

    public function test_the_sidebar_marks_the_board_view_of_my_tasks_not_the_entry_itself(): void
    {
        $html = $this->get(route('tasks.mine.board'), ['X-Livewire-Navigate' => '1'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('tasks.mine.board'), '/').'"[^>]*data-current="data-current"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="'.preg_quote(route('tasks.mine'), '/').'"[^>]*data-current="data-current"/', $html);
    }
}
