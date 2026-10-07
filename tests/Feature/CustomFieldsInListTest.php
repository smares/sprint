<?php

namespace Tests\Feature;

use App\CustomFieldType;
use App\Models\CustomField;
use App\Models\Project;
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

    public function test_text_number_and_date_fields_are_formatted(): void
    {
        $task = $this->task('Mit Werten');
        $number = CustomField::factory()->for($this->project)->create(['name' => 'Aufwand', 'type' => CustomFieldType::Number]);
        $date = CustomField::factory()->for($this->project)->create(['name' => 'Review am', 'type' => CustomFieldType::Date]);
        $text = CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);
        $task->fieldValues()->create(['custom_field_id' => $number->id, 'value' => '13']);
        $task->fieldValues()->create(['custom_field_id' => $date->id, 'value' => '2026-12-24']);
        $task->fieldValues()->create(['custom_field_id' => $text->id, 'value' => 'Müller AG']);

        $this->list()->assertSee('Aufwand')->assertSee('13')->assertSee('24.12.2026')->assertSee('Müller AG');
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
    }

    public function test_only_select_fields_offer_a_filter(): void
    {
        CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);

        $this->list()->assertSee('Alle: Priorität')->assertDontSee('Alle: Kunde');
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
}
