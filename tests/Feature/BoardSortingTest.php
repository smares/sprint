<?php

namespace Tests\Feature;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BoardSortingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private int $statusId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        $this->statusId = $this->project->defaultStatus()->id;
    }

    private function task(string $title, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create(['title' => $title, 'status_id' => $this->statusId] + $attributes);
    }

    /**
     * @return list<string>
     */
    private function column(mixed $board): array
    {
        return $board->instance()->columns[$this->statusId]->pluck('title')->all();
    }

    public function test_tasks_that_are_equal_in_the_criterion_come_newest_first_in_both_directions(): void
    {
        $this->task('Älteste', ['position' => 0, 'due_date' => '2026-11-01', 'created_at' => '2026-10-01 10:00:00']);
        $this->task('Mittlere', ['position' => 1, 'due_date' => '2026-11-01', 'created_at' => '2026-10-05 10:00:00']);
        $this->task('Neueste', ['position' => 2, 'due_date' => '2026-11-01', 'created_at' => '2026-10-09 10:00:00']);
        $this->task('Früher', ['position' => 3, 'due_date' => '2026-10-20', 'created_at' => '2026-10-02 10:00:00']);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project])->set('sortBy', 'due');
        $this->assertSame(['Früher', 'Neueste', 'Mittlere', 'Älteste'], $this->column($board));

        $board->call('reverseSort');
        $this->assertSame(['Neueste', 'Mittlere', 'Älteste', 'Früher'], $this->column($board));

        // The same moment of creation: the higher id counts as newer
        $a = $this->task('Gleich A', ['due_date' => '2026-12-01', 'created_at' => '2026-10-10 10:00:00']);
        $b = $this->task('Gleich B', ['due_date' => '2026-12-01', 'created_at' => '2026-10-10 10:00:00']);
        $this->assertGreaterThan($a->id, $b->id);
        $this->assertSame(['Gleich B', 'Gleich A'], array_slice($this->column($board->call('resetSort')->set('sortBy', 'due')->call('reverseSort')), 0, 2));
    }

    public function test_the_list_breaks_ties_of_a_sort_by_the_newest_task_too(): void
    {
        $this->task('Älteste', ['position' => 0, 'created_at' => '2026-10-01 10:00:00']);
        $this->task('Neueste', ['position' => 1, 'created_at' => '2026-10-09 10:00:00']);
        $this->task('Mittlere', ['position' => 2, 'created_at' => '2026-10-05 10:00:00']);

        // Same status for all, so "status" ties them all
        $list = Livewire::test('pages::projects.show', ['project' => $this->project])->call('sort', 'status');
        $this->assertSame(['Neueste', 'Mittlere', 'Älteste'], $list->instance()->tasks->pluck('title')->all());

        $list->call('resetSort');
        $this->assertSame(['Älteste', 'Neueste', 'Mittlere'], $list->instance()->tasks->pluck('title')->all());
    }

    public function test_the_manual_order_is_the_default(): void
    {
        $this->task('Zebra', ['position' => 0]);
        $this->task('Anton', ['position' => 1]);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project]);

        $this->assertSame(['Zebra', 'Anton'], $this->column($board));
        $board->assertSet('sortBy', '')->assertDontSee('Zurücksetzen');
    }

    public function test_cards_can_be_sorted_by_due_date_with_the_undated_last_in_both_directions(): void
    {
        $this->task('Später', ['position' => 0, 'due_date' => '2026-12-01']);
        $this->task('Ohne', ['position' => 1, 'due_date' => null]);
        $this->task('Früher', ['position' => 2, 'due_date' => '2026-11-01']);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project])->call('sort', 'due');
        $this->assertSame(['Früher', 'Später', 'Ohne'], $this->column($board));

        $board->call('sort', 'due');
        $board->assertSet('sortDirection', 'desc');
        $this->assertSame(['Später', 'Früher', 'Ohne'], $this->column($board));
    }

    public function test_titles_are_sorted_without_regard_to_the_case(): void
    {
        $this->task('zebra', ['position' => 0]);
        $this->task('Anton', ['position' => 1]);
        $this->task('berta', ['position' => 2]);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project])->call('sort', 'title');

        $this->assertSame(['Anton', 'berta', 'zebra'], $this->column($board));
    }

    public function test_text_fields_are_sorted_without_regard_to_the_case(): void
    {
        $note = CustomField::factory()->for($this->project)->create(['name' => 'Notiz', 'type' => CustomFieldType::Text]);
        foreach (['Zebra' => 0, 'anton' => 1, 'Berta' => 2] as $value => $position) {
            $task = $this->task($value.' Aufgabe', ['position' => $position]);
            CustomFieldValue::create(['task_id' => $task->id, 'custom_field_id' => $note->id, 'value' => $value]);
        }
        $this->task('Ohne', ['position' => 3]);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project])->call('sort', 'field:'.$note->id);

        $this->assertSame(['anton Aufgabe', 'Berta Aufgabe', 'Zebra Aufgabe', 'Ohne'], $this->column($board));
    }

    public function test_the_menu_offers_the_fields_that_the_list_shows_as_columns(): void
    {
        CustomField::factory()->for($this->project)->create(['name' => 'Sichtbar', 'type' => CustomFieldType::Text, 'show_in_list' => true]);
        CustomField::factory()->for($this->project)->create(['name' => 'Versteckt', 'type' => CustomFieldType::Text, 'show_in_list' => false]);

        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->assertSee('Sichtbar')
            ->assertDontSee('Versteckt');
    }

    public function test_cards_can_be_sorted_by_title_and_by_a_custom_field(): void
    {
        $effort = CustomField::factory()->for($this->project)->create(['name' => 'Aufwand', 'type' => CustomFieldType::Number]);
        $a = $this->task('Anton', ['position' => 0]);
        $b = $this->task('Berta', ['position' => 1]);
        $this->task('Cäsar', ['position' => 2]);
        CustomFieldValue::create(['task_id' => $a->id, 'custom_field_id' => $effort->id, 'value' => '10']);
        CustomFieldValue::create(['task_id' => $b->id, 'custom_field_id' => $effort->id, 'value' => '9']);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project])->call('sort', 'title')->call('sort', 'title');
        $this->assertSame(['Cäsar', 'Berta', 'Anton'], $this->column($board));

        // 9 before 10 (a number, not text), the one without a value last
        $board->call('sort', 'title')->call('sort', 'field:'.$effort->id);
        $this->assertSame(['Berta', 'Anton', 'Cäsar'], $this->column($board));
    }

    public function test_a_third_click_and_the_reset_go_back_to_the_manual_order(): void
    {
        $this->task('Zebra', ['position' => 0]);
        $this->task('Anton', ['position' => 1]);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('sort', 'title')
            ->call('sort', 'title')
            ->call('sort', 'title')
            ->assertSet('sortBy', '');
        $this->assertSame(['Zebra', 'Anton'], $this->column($board));

        $board->call('sort', 'title')->assertSee('Zurücksetzen');
        $this->assertSame(['Anton', 'Zebra'], $this->column($board));

        $board->call('resetSort')->assertSet('sortBy', '')->assertSet('sortDirection', 'asc');
        $this->assertSame(['Zebra', 'Anton'], $this->column($board));
    }

    public function test_only_criteria_that_make_sense_for_a_column_are_taken(): void
    {
        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('sort', 'status')
            ->assertSet('sortBy', '')
            ->call('sort', 'field:999999')
            ->assertSet('sortBy', '')
            ->call('sort', 'title; drop table tasks')
            ->assertSet('sortBy', '');
    }

    public function test_the_first_cards_of_a_column_are_the_first_in_the_chosen_order(): void
    {
        foreach (range(1, 35) as $i) {
            // The manual order and the due dates run in opposite directions
            $this->task(sprintf('Aufgabe %02d', $i), ['position' => $i, 'due_date' => now()->addDays(100 - $i)->toDateString()]);
        }

        $board = Livewire::test('pages::projects.board', ['project' => $this->project]);
        $this->assertSame('Aufgabe 01', $this->column($board)[0]);

        $board->call('sort', 'due');
        $column = $this->column($board);
        $this->assertCount(30, $column);
        $this->assertSame('Aufgabe 35', $column[0]);
        $this->assertSame('Aufgabe 06', $column[29]);
    }

    public function test_a_card_moved_into_another_column_while_sorted_goes_first_in_its_manual_order(): void
    {
        $other = $this->project->statuses()->where('is_done', false)->where('id', '!=', $this->statusId)->first()
            ?? $this->project->statuses()->create(['name' => 'Läuft', 'position' => 9]);
        $a = $this->task('Anton', ['position' => 0]);
        $c = Task::factory()->for($this->project)->create(['title' => 'Cäsar', 'position' => 1, 'status_id' => $other->id]);
        $d = Task::factory()->for($this->project)->create(['title' => 'Dora', 'position' => 2, 'status_id' => $other->id]);
        $b = $this->task('Zacharias', ['position' => 3]);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('sort', 'title')
            // the place it is dropped at is not used while a sort is on
            ->call('moveTask', $b->id, 2, (string) $other->id);

        $this->assertSame($other->id, $b->fresh()->status_id);
        $this->assertSame([0, 1, 2, 3], collect([$a, $b, $c, $d])->map(fn (Task $task) => $task->fresh()->position)->sort()->values()->all());
        $this->assertTrue($b->fresh()->position < $c->fresh()->position && $c->fresh()->position < $d->fresh()->position);
        // the screen keeps the sort order; the manual one shows when the sort is reset
        $this->assertSame(['Cäsar', 'Dora', 'Zacharias'], $board->instance()->columns[$other->id]->pluck('title')->all());
        $this->assertSame(['Zacharias', 'Cäsar', 'Dora'], $board->call('resetSort')->instance()->columns[$other->id]->pluck('title')->all());
    }

    public function test_the_menu_sets_the_criterion_and_the_arrow_turns_the_direction(): void
    {
        $this->task('Zebra', ['position' => 0]);
        $this->task('Anton', ['position' => 1]);

        $board = Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('reverseSort')
            ->assertSet('sortDirection', 'asc')
            ->set('sortBy', 'title');
        $this->assertSame(['Anton', 'Zebra'], $this->column($board));

        $board->call('reverseSort')->assertSet('sortDirection', 'desc');
        $this->assertSame(['Zebra', 'Anton'], $this->column($board));

        // Back to the manual order from the menu: the direction starts again with ascending
        $board->set('sortBy', '')->assertSet('sortDirection', 'asc');
        $this->assertSame(['Zebra', 'Anton'], $this->column($board));
    }

    public function test_a_criterion_the_menu_does_not_offer_is_not_taken(): void
    {
        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->set('sortBy', 'status')
            ->assertSet('sortBy', '')
            ->set('sortBy', 'field:999999')
            ->assertSet('sortBy', '');
    }

    public function test_cards_can_only_be_reordered_by_dragging_in_the_manual_order(): void
    {
        $this->task('Eins');

        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->assertSeeHtml('cards-'.$this->statusId.'-manual')
            ->assertDontSeeHtml('sort: false')
            ->set('sortBy', 'title')
            ->assertSeeHtml('cards-'.$this->statusId.'-sorted')
            ->assertSeeHtml('sort: false')
            ->call('resetSort')
            ->assertSeeHtml('cards-'.$this->statusId.'-manual')
            ->assertDontSeeHtml('sort: false');
    }
}
