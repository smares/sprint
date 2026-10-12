<?php

namespace Tests\Feature;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
    }

    public function test_an_error_names_the_field_by_its_label_and_shows_once(): void
    {
        $component = Livewire::test('project-statuses', ['project' => $this->project])->call('add');

        $message = 'Das Feld Name ist erforderlich.';
        $this->assertSame($message, $component->errors()->first('newName'));
        $this->assertSame(1, substr_count($component->html(), $message));

        Livewire::test('project-tags', ['project' => $this->project])->call('add')
            ->assertHasErrors(['newName' => $message]);
    }

    public function test_the_task_form_names_its_fields_and_the_custom_ones(): void
    {
        $effort = CustomField::factory()->for($this->project)->create(['name' => 'Aufwand', 'type' => CustomFieldType::Number]);
        $task = Task::factory()->for($this->project)->create();

        $component = Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('title', '')
            ->set('dueDate', '2026-10-12')
            ->set('startDate', '2026-10-20')
            ->set("fieldValues.{$effort->id}", 'viel')
            ->call('save');

        $errors = $component->errors();
        $this->assertSame('Das Feld Titel ist erforderlich.', $errors->first('title'));
        $this->assertStringContainsString('Beginnt am', $errors->first('startDate'));
        $this->assertStringContainsString('Fällig am', $errors->first('startDate'));
        $this->assertStringContainsString('Aufwand', $errors->first("fieldValues.{$effort->id}"));

        $component->call('addComment')->assertHasErrors(['comment' => 'Das Feld Kommentar ist erforderlich.']);
    }

    /**
     * A control with a label shows its error itself (Flux), so another <flux:error> for the same property would repeat it.
     */
    public function test_labelled_controls_have_no_second_error_message(): void
    {
        $repeated = collect(File::allFiles(resource_path('views')))
            ->flatMap(function (SplFileInfo $file) {
                $view = $file->getContents();
                preg_match_all('/<flux:error name="([^"]+)"/', $view, $errors);

                return collect($errors[1])
                    ->filter(fn (string $name) => preg_match('/<flux:[a-z.-]+\s[^>]*wire:model(\.[a-z]+)*="'.preg_quote($name, '/').'"[^>]*\s:?label=/', $view) === 1)
                    ->map(fn (string $name) => $file->getRelativePathname().': '.$name);
            });

        $this->assertSame([], $repeated->values()->all());
    }
}
