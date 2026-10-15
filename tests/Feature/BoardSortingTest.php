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

    public function test_a_card_moved_into_another_column_while_sorted_changes_its_status_only(): void
    {
        $other = $this->project->statuses()->where('is_done', false)->where('id', '!=', $this->statusId)->first()
            ?? $this->project->statuses()->create(['name' => 'Läuft', 'position' => 9]);
        $a = $this->task('Anton', ['position' => 0]);
        $b = $this->task('Berta', ['position' => 1]);

        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('sort', 'title')
            ->call('moveTask', $b->id, 0, (string) $other->id);

        $this->assertSame($other->id, $b->fresh()->status_id);
        $this->assertSame([0, 1], [$a->fresh()->position, $b->fresh()->position]);
    }
}
