<?php

namespace Tests\Feature;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CustomFieldsInListTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private CustomField $priority;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        $this->priority = $this->project->customFields()->where('name', 'Priorität')->firstOrFail();
    }

    private function task(string $title, ?string $priority = null, int $position = 0): Task
    {
        $task = Task::factory()->for($this->project)->create(['title' => $title, 'position' => $position]);

        if ($priority !== null) {
            $task->fieldValues()->create([
                'custom_field_id' => $this->priority->id,
                'option_id' => $this->priority->options->firstWhere('name', $priority)->id,
            ]);
        }

        return $task;
    }

    private function list(): Testable
    {
        return Livewire::test('pages::projects.show', ['project' => $this->project]);
    }

    public function test_list_shows_a_column_with_the_priority_of_each_task(): void
    {
        $this->task('Eilig', 'Dringend');
        $this->task('Ohne Wert');

        $this->list()->assertSee('Priorität')->assertSee('Dringend');
    }

    public function test_columns_can_be_switched_off_per_field(): void
    {
        $text = CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);
        $this->task('Mit Kunde')->fieldValues()->create(['custom_field_id' => $text->id, 'value' => 'Müller AG']);

        $this->list()->assertSee('Müller AG')->assertSeeHtml("sort('field:{$text->id}')");

        $text->update(['show_in_list' => false]);

        $this->list()->assertDontSee('Müller AG')->assertDontSeeHtml("sort('field:{$text->id}')");
    }

    public function test_every_column_shows_on_phones_too_and_the_title_stays_in_place_when_scrolling_sideways(): void
    {
        $this->task('Eilig', 'Dringend');

        $html = $this->list()->html();
        $start = (int) strpos($html, 'data-flux-table');
        $table = substr($html, $start, strpos($html, '</table>', $start) - $start);

        $this->assertStringNotContainsString('hidden" data-flux-c', $table);
        $this->assertSame(2, substr_count($table, 'pinned-column sm:start-20'));
        $this->assertSame(2, substr_count($table, 'sm:sticky sm:start-0'));
    }

    public function test_text_number_and_date_fields_are_formatted(): void
    {
        $task = $this->task('Mit Werten');
        $number = CustomField::factory()->for($this->project)->create(['name' => 'Aufwand', 'type' => CustomFieldType::Number]);
        $date = CustomField::factory()->for($this->project)->create(['name' => 'Review am', 'type' => CustomFieldType::Date]);
        $text = CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);
        $task->fieldValues()->create(['custom_field_id' => $number->id, 'value' => '13']);
        $task->fieldValues()->create(['custom_field_id' => $date->id, 'value' => '2026-12-24']);
        $task->fieldValues()->create(['custom_field_id' => $text->id, 'value' => 'Müller AG']);

        $this->list()->assertSee('Aufwand')->assertSee('13')->assertSee('2026-12-24')->assertSee('Müller AG');
    }

    public function test_list_can_be_filtered_by_a_select_field(): void
    {
        $this->task('Dringende Sache', 'Dringend');
        $this->task('Kleinigkeit', 'Niedrig');
        $this->task('Unklar');
        $dringend = $this->priority->options->firstWhere('name', 'Dringend');

        $this->list()
            ->assertSee('Dringende Sache')->assertSee('Kleinigkeit')->assertSee('Unklar')
            ->set("fieldFilters.{$this->priority->id}", (string) $dringend->id)
            ->assertSee('Dringende Sache')->assertDontSee('Kleinigkeit')->assertDontSee('Unklar')
            ->set("fieldFilters.{$this->priority->id}", '')
            ->assertSee('Kleinigkeit');
    }

    public function test_filters_ignore_options_of_other_fields_and_garbage(): void
    {
        $this->task('Sichtbar', 'Hoch');
        $foreign = Project::factory()->create()->customFields()->firstOrFail()->options->first();

        $this->list()
            ->set("fieldFilters.{$this->priority->id}", (string) $foreign->id)
            ->assertSee('Sichtbar')
            ->set("fieldFilters.{$this->priority->id}", 'x; drop table')
            ->assertSee('Sichtbar');

        $this->get(route('projects.show', $this->project).'?f['.$this->priority->id.'][]=x')->assertOk()->assertSee('Sichtbar');
    }

    public function test_tasks_can_be_filtered_by_whether_they_have_dates(): void
    {
        $this->task('Ohne Termin');
        $this->task('Mit Termin')->update(['due_date' => '2026-12-01']);

        $this->list()->set('dateFilter', 'none')->assertSee('Ohne Termin')->assertDontSee('Mit Termin')->assertSee('Ohne Datum')
            ->set('dateFilter', 'dated')->assertSee('Mit Termin')->assertDontSee('Ohne Termin')
            ->call('clearFilter', 'dates')->assertSet('dateFilter', '')->assertSee('Ohne Termin')->assertSee('Mit Termin');
    }

    public function test_only_select_fields_offer_a_filter(): void
    {
        CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);

        $this->assertSame(['Priorität'], $this->list()->instance()->filterableFields->pluck('name')->all());
    }

    public function test_list_sorts_by_the_option_order_with_empty_values_last(): void
    {
        $this->task('A niedrig', 'Niedrig', 0);
        $this->task('B ohne', null, 1);
        $this->task('C dringend', 'Dringend', 2);
        $this->task('D mittel', 'Mittel', 3);

        $this->list()
            ->call('sort', 'field:'.$this->priority->id)
            ->assertSeeInOrder(['A niedrig', 'D mittel', 'C dringend', 'B ohne'])
            ->call('sort', 'field:'.$this->priority->id)
            ->assertSet('sortDirection', 'desc')
            ->assertSeeInOrder(['C dringend', 'D mittel', 'A niedrig', 'B ohne'])
            ->call('sort', 'field:'.$this->priority->id)
            ->assertSet('sortBy', '');
    }

    public function test_list_sorts_numbers_numerically_and_dates_and_text(): void
    {
        $number = CustomField::factory()->for($this->project)->create(['name' => 'Aufwand', 'type' => CustomFieldType::Number]);
        foreach ([['Neun', '9'], ['Zehn', '10'], ['Zwei', '2']] as $position => [$title, $value]) {
            $this->task($title, null, $position)->fieldValues()->create(['custom_field_id' => $number->id, 'value' => $value]);
        }

        $this->list()->call('sort', 'field:'.$number->id)->assertSeeInOrder(['Zwei', 'Neun', 'Zehn']);

        $text = CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);
        Task::where('title', 'Zwei')->firstOrFail()->fieldValues()->create(['custom_field_id' => $text->id, 'value' => 'Zeta']);
        Task::where('title', 'Neun')->firstOrFail()->fieldValues()->create(['custom_field_id' => $text->id, 'value' => 'Alpha']);

        $this->list()->call('sort', 'field:'.$text->id)->assertSeeInOrder(['Neun', 'Zwei', 'Zehn']);
    }

    public function test_sorting_by_a_field_of_another_project_is_ignored(): void
    {
        $this->task('Eine');
        $foreign = Project::factory()->create()->customFields()->firstOrFail();

        $this->list()->call('sort', 'field:'.$foreign->id)->assertSet('sortBy', '');
    }

    public function test_board_cards_show_the_field_values(): void
    {
        $this->task('Eilig', 'Dringend');
        $text = CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);
        Task::where('title', 'Eilig')->firstOrFail()->fieldValues()->create(['custom_field_id' => $text->id, 'value' => 'Müller AG']);

        $this->get(route('projects.board', $this->project))->assertOk()
            ->assertSee('Dringend')->assertSee('Kunde: Müller AG');

        $this->priority->update(['show_in_list' => false]);
        $this->get(route('projects.board', $this->project))->assertOk()->assertDontSee('Dringend');
    }

    public function test_board_cards_without_values_stay_clean(): void
    {
        $this->task('Leer');

        $this->get(route('projects.board', $this->project))->assertOk()->assertSee('Leer')->assertDontSee('Priorität:');
    }

    public function test_active_filters_show_as_chips_and_can_be_removed_or_reset(): void
    {
        $option = $this->priority->options->firstWhere('name', 'Hoch');
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Kunde A']);

        $page = $this->list()
            ->assertSet('statusFilter', 'open')
            ->set('assigneeFilter', 'me')
            ->set("fieldFilters.{$this->priority->id}", (string) $option->id)
            ->set('tagFilter', (string) $tag->id);

        $this->assertSame(
            ['Nur meine', 'Priorität: Hoch', 'Kunde A'],
            $page->instance()->activeFilters->pluck('label')->all(),
        );

        $page->call('clearFilter', 'tag')->assertSet('tagFilter', '');
        $page->call('clearFilter', 'field:'.$this->priority->id)->assertSet("fieldFilters.{$this->priority->id}", '');
        $page->call('clearFilter', 'assignee')->assertSet('assigneeFilter', '');
        $this->assertSame(0, $page->instance()->activeFilters->count());

        $page->set('statusFilter', 'all')->set('assigneeFilter', 'me')->call('resetFilters')
            ->assertSet('statusFilter', 'open')->assertSet('assigneeFilter', '')->assertSet('fieldFilters', []);
    }

    public function test_the_status_chip_names_the_current_status_filter(): void
    {
        $status = $this->project->statuses()->where('name', 'In Arbeit')->firstOrFail();

        $page = $this->list()->assertSee('Offene');
        $page->set('statusFilter', 'all')->assertSee('Alle Status');
        $page->set('statusFilter', (string) $status->id)->assertSee('In Arbeit');
    }

    public function test_the_filter_count_only_counts_non_default_filters(): void
    {
        $this->assertSame(0, $this->list()->set('statusFilter', 'all')->instance()->activeFilters->count());
        $this->assertSame(1, $this->list()->set('assigneeFilter', 'me')->instance()->activeFilters->count());
    }
}
