<?php

namespace Tests\Feature;

use App\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->project = Project::factory()->create();
        $this->actingAs($this->admin);
    }

    private function priority(): CustomField
    {
        return $this->project->customFields()->where('name', 'Priorität')->firstOrFail();
    }

    private function task(): Task
    {
        return Task::factory()->for($this->project)->create();
    }

    public function test_new_projects_start_with_a_priority_field(): void
    {
        $field = $this->priority();

        $this->assertSame(CustomFieldType::Select, $field->type);
        $this->assertSame(['Niedrig', 'Mittel', 'Hoch', 'Dringend'], $field->options->pluck('name')->all());
        $this->assertTrue($field->show_in_list);
    }

    public function test_fields_are_separate_per_project(): void
    {
        $other = Project::factory()->create();

        Livewire::test('pages::projects.fields', ['project' => $this->project])
            ->set('newName', 'Aufwand')->set('newType', 'number')->call('add');

        $this->assertTrue($this->project->customFields()->where('name', 'Aufwand')->exists());
        $this->assertFalse($other->customFields()->where('name', 'Aufwand')->exists());
    }

    public function test_the_field_page_needs_the_manage_right(): void
    {
        $editor = User::factory()->create();
        $manager = User::factory()->create();
        $this->project->setRole($editor, ProjectRole::Editor);
        $this->project->setRole($manager, ProjectRole::Admin);

        $this->actingAs($editor)->get(route('projects.fields', $this->project))->assertForbidden();
        $this->actingAs($manager)->get(route('projects.fields', $this->project))->assertOk()->assertSee('Priorität');
        $this->actingAs($manager)->get(route('projects.show', $this->project))->assertSee('Felder');
        $this->actingAs($editor)->get(route('projects.show', $this->project))->assertDontSee('>Felder<', false);
    }

    public function test_a_field_can_be_added_renamed_toggled_reordered_and_deleted(): void
    {
        $component = Livewire::test('pages::projects.fields', ['project' => $this->project])
            ->call('add')->assertHasErrors('newName')
            ->set('newName', 'Aufwand')->set('newType', 'number')->call('add')->assertHasNoErrors();

        $field = $this->project->customFields()->where('name', 'Aufwand')->firstOrFail();
        $this->assertSame(CustomFieldType::Number, $field->type);

        $component->set("names.{$field->id}", 'Story Points');
        $component->set("inList.{$field->id}", false);
        $this->assertSame('Story Points', $field->fresh()->name);
        $this->assertFalse($field->fresh()->show_in_list);

        $component->call('moveField', $field->id, 0);
        $this->assertSame($field->id, $this->project->customFields()->firstOrFail()->id);

        $component->call('confirmDelete', $field->id)->call('delete');
        $this->assertDatabaseMissing('custom_fields', ['id' => $field->id]);
    }

    public function test_field_type_and_name_are_validated(): void
    {
        Livewire::test('pages::projects.fields', ['project' => $this->project])
            ->set('newName', 'X')->set('newType', 'video')->call('add')->assertHasErrors('newType');

        $field = $this->priority();
        Livewire::test('pages::projects.fields', ['project' => $this->project])
            ->set("names.{$field->id}", '  ')
            ->assertSet("names.{$field->id}", 'Priorität');
    }

    public function test_options_can_be_added_renamed_recolored_reordered_and_removed(): void
    {
        $field = $this->priority();
        $component = Livewire::test('pages::projects.fields', ['project' => $this->project])
            ->set("newOptions.{$field->id}", 'Egal')->call('addOption', $field->id)->assertHasNoErrors();

        $option = $field->options()->where('name', 'Egal')->firstOrFail();
        $component->set("optionNames.{$option->id}", 'Nebensache')->set("optionColors.{$option->id}", 'pink');
        $this->assertSame('Nebensache', $option->fresh()->name);
        $this->assertSame('pink', $option->fresh()->color);

        $component->set("optionColors.{$option->id}", 'neon')->assertSet("optionColors.{$option->id}", 'pink');

        $component->call('moveOption', $option->id, 0, $field->id);
        $this->assertSame($option->id, $field->options()->firstOrFail()->id);

        $component->call('removeOption', $option->id);
        $this->assertDatabaseMissing('custom_field_options', ['id' => $option->id]);
    }

    public function test_options_only_exist_for_select_fields_and_stay_inside_the_project(): void
    {
        $text = CustomField::factory()->for($this->project)->create();
        $foreignOption = CustomFieldOption::factory()->create();

        Livewire::test('pages::projects.fields', ['project' => $this->project])
            ->set("newOptions.{$text->id}", 'Nein')->call('addOption', $text->id)->assertStatus(404);
        Livewire::test('pages::projects.fields', ['project' => $this->project])
            ->call('removeOption', $foreignOption->id)->assertStatus(404);

        $this->assertDatabaseHas('custom_field_options', ['id' => $foreignOption->id]);
    }

    public function test_deleting_an_option_or_field_removes_the_values_on_tasks(): void
    {
        $task = $this->task();
        $field = $this->priority();
        $option = $field->options->first();
        $task->fieldValues()->create(['custom_field_id' => $field->id, 'option_id' => $option->id]);

        $option->delete();

        $this->assertSame(0, $task->fieldValues()->count());
    }

    public function test_task_page_shows_the_fields_and_saves_the_values(): void
    {
        $task = $this->task();
        $priority = $this->priority();
        $high = $priority->options->firstWhere('name', 'Hoch');
        $number = CustomField::factory()->for($this->project)->create(['name' => 'Aufwand', 'type' => CustomFieldType::Number]);
        $date = CustomField::factory()->for($this->project)->create(['name' => 'Review am', 'type' => CustomFieldType::Date]);
        $text = CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);

        $this->get(route('tasks.show', $task))->assertOk()->assertSee('Priorität')->assertSee('Aufwand')->assertSee('Review am')->assertSee('Kunde');

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set("fieldValues.{$priority->id}", (string) $high->id)
            ->set("fieldValues.{$number->id}", '8')
            ->set("fieldValues.{$date->id}", '2026-12-24')
            ->set("fieldValues.{$text->id}", 'Müller AG')
            ->call('save')
            ->assertHasNoErrors();

        $values = $task->fieldValues()->get()->keyBy('custom_field_id');
        $this->assertSame($high->id, $values[$priority->id]->option_id);
        $this->assertSame('8', $values[$number->id]->value);
        $this->assertSame('2026-12-24', $values[$date->id]->value);
        $this->assertSame('Müller AG', $values[$text->id]->value);

        $this->assertSame((string) $high->id, Livewire::test('pages::tasks.show', ['task' => $task->fresh()])->get("fieldValues.{$priority->id}"));
    }

    public function test_values_can_be_cleared_and_changed(): void
    {
        $task = $this->task();
        $priority = $this->priority();
        [$low, $mid] = [$priority->options[0], $priority->options[1]];
        $task->fieldValues()->create(['custom_field_id' => $priority->id, 'option_id' => $low->id]);

        Livewire::test('pages::tasks.show', ['task' => $task->fresh()])
            ->set("fieldValues.{$priority->id}", (string) $mid->id)->call('save');
        $this->assertSame($mid->id, $task->fieldValues()->firstOrFail()->option_id);
        $this->assertSame(1, $task->fieldValues()->count());

        Livewire::test('pages::tasks.show', ['task' => $task->fresh()])
            ->set("fieldValues.{$priority->id}", '')->call('save');
        $this->assertSame(0, $task->fieldValues()->count());
    }

    public function test_values_are_validated_by_type_and_project(): void
    {
        $task = $this->task();
        $priority = $this->priority();
        $foreignOption = CustomFieldOption::factory()->create();
        $number = CustomField::factory()->for($this->project)->create(['type' => CustomFieldType::Number]);
        $date = CustomField::factory()->for($this->project)->create(['type' => CustomFieldType::Date]);

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set("fieldValues.{$priority->id}", (string) $foreignOption->id)
            ->set("fieldValues.{$number->id}", 'viel')
            ->set("fieldValues.{$date->id}", 'gestern')
            ->call('save')
            ->assertHasErrors(["fieldValues.{$priority->id}", "fieldValues.{$number->id}", "fieldValues.{$date->id}"]);

        $this->assertSame(0, $task->fieldValues()->count());
    }

    public function test_changes_to_values_land_in_the_activity_log(): void
    {
        $task = $this->task();
        $priority = $this->priority();

        Livewire::test('pages::tasks.show', ['task' => $task->fresh()])
            ->set("fieldValues.{$priority->id}", (string) $priority->options[2]->id)->call('save');
        Livewire::test('pages::tasks.show', ['task' => $task->fresh()])
            ->set("fieldValues.{$priority->id}", (string) $priority->options[3]->id)->call('save');
        Livewire::test('pages::tasks.show', ['task' => $task->fresh()])->call('save');

        $sentences = $task->activities()->orderBy('id')->get()->map->sentence()->all();

        $this->assertContains('hat Priorität von „–“ auf „Hoch“ geändert', $sentences);
        $this->assertContains('hat Priorität von „Hoch“ auf „Dringend“ geändert', $sentences);
        $this->assertSame(2, collect($sentences)->filter(fn ($sentence) => str_contains($sentence, 'Priorität'))->count());
    }

    public function test_viewers_cannot_change_values(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $task = $this->task();
        $priority = $this->priority();

        $this->actingAs($viewer);
        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set("fieldValues.{$priority->id}", (string) $priority->options[0]->id)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, $task->fieldValues()->count());
    }

    public function test_deleting_a_field_removes_it_from_the_task_page(): void
    {
        $task = $this->task();
        CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);
        $this->get(route('tasks.show', $task))->assertSee('Kunde');

        $this->project->customFields()->where('name', 'Kunde')->delete();

        $this->get(route('tasks.show', $task))->assertDontSee('Kunde');
    }
}
