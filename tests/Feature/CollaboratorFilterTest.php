<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CollaboratorFilterTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $editor;

    private User $anna;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->editor = User::factory()->create(['name' => 'Editorin']);
        $this->anna = User::factory()->create(['name' => 'Anna Beteiligt']);
        $this->ben = User::factory()->create(['name' => 'Ben Beteiligt']);

        foreach ([$this->editor, $this->anna, $this->ben] as $user) {
            $this->project->setRole($user, ProjectRole::Editor);
        }

        $this->actingAs($this->editor);
    }

    private function list()
    {
        return Livewire::test('pages::projects.show', ['project' => $this->project]);
    }

    private function task(string $title, array $collaborators = [], array $attributes = []): Task
    {
        $task = Task::factory()->for($this->project)->create(['title' => $title] + $attributes);
        $task->collaborators()->attach(array_map(fn (User $user) => $user->id, $collaborators));

        return $task;
    }

    public function test_the_filter_shows_tasks_where_at_least_one_of_the_chosen_people_collaborates(): void
    {
        $this->task('Mit Anna', [$this->anna]);
        $this->task('Mit Ben', [$this->ben]);
        $this->task('Mit beiden', [$this->anna, $this->ben]);
        $this->task('Ohne Beteiligte');

        $this->list()
            ->set('collaboratorFilter', [(string) $this->anna->id])
            ->assertSee('Mit Anna')->assertSee('Mit beiden')
            ->assertDontSee('Mit Ben')->assertDontSee('Ohne Beteiligte')
            ->set('collaboratorFilter', [(string) $this->anna->id, (string) $this->ben->id])
            ->assertSee('Mit Anna')->assertSee('Mit Ben')->assertSee('Mit beiden')
            ->assertDontSee('Ohne Beteiligte');
    }

    public function test_a_task_one_is_only_assigned_to_does_not_count_as_collaborating(): void
    {
        $this->task('Zugewiesen', [], ['assignee_id' => $this->anna->id]);
        $this->task('Beteiligt', [$this->anna]);

        $this->list()
            ->set('collaboratorFilter', [(string) $this->anna->id])
            ->assertSee('Beteiligt')
            ->assertDontSee('Zugewiesen');
    }

    public function test_it_combines_with_the_person_filter_and_me_stands_for_the_person_looking(): void
    {
        $this->task('Anna zuständig, Ben beteiligt', [$this->ben], ['assignee_id' => $this->anna->id]);
        $this->task('Ben zuständig, Ben beteiligt', [$this->ben], ['assignee_id' => $this->ben->id]);
        $this->task('Ich bin beteiligt', [$this->editor]);

        $this->list()
            ->set('collaboratorFilter', [(string) $this->ben->id])
            ->set('assigneeFilter', (string) $this->anna->id)
            ->assertSee('Anna zuständig, Ben beteiligt')
            ->assertDontSee('Ben zuständig, Ben beteiligt')
            ->set('assigneeFilter', '')
            ->set('collaboratorFilter', ['me'])
            ->assertSee('Ich bin beteiligt')
            ->assertDontSee('Ben zuständig, Ben beteiligt');
    }

    public function test_the_active_filter_names_the_people_and_can_be_cleared_or_reset(): void
    {
        $this->task('Mit Anna', [$this->anna]);
        $this->task('Ohne');

        $list = $this->list()->set('collaboratorFilter', [(string) $this->anna->id, (string) $this->ben->id]);

        $this->assertSame(
            [['key' => 'collaborators', 'label' => 'Beteiligte: Anna Beteiligt, Ben Beteiligt']],
            $list->instance()->activeFilters->all(),
        );

        $list->call('clearFilter', 'collaborators')->assertSet('collaboratorFilter', [])->assertSee('Ohne');
        $list->set('collaboratorFilter', ['me'])->call('resetFilters')->assertSet('collaboratorFilter', []);
    }

    public function test_people_who_cannot_be_chosen_in_the_project_are_ignored(): void
    {
        $this->task('Mit Anna', [$this->anna]);
        $outsider = User::factory()->create();

        $list = $this->list()->set('collaboratorFilter', [(string) $outsider->id, 'x', '9999999']);

        $list->assertSee('Mit Anna');
        $this->assertSame([], $list->instance()->activeFilters->all());
    }

    public function test_it_is_part_of_a_saved_view_and_old_views_without_it_still_apply(): void
    {
        $this->task('Mit Anna', [$this->anna]);
        $this->task('Ohne');

        $this->list()
            ->set('collaboratorFilter', [(string) $this->anna->id, 'me'])
            ->set('saveName', 'Mit Anna')
            ->call('saveCurrentFilter');

        $saved = $this->project->savedFilters()->firstOrFail();
        $this->assertSame([(string) $this->anna->id, 'me'], $saved->filters['collaborators']);

        $this->list()
            ->call('applyFilter', $saved->id)
            ->assertSet('collaboratorFilter', [(string) $this->anna->id, 'me']);

        $old = $this->project->savedFilters()->create(['user_id' => $this->editor->id, 'name' => 'Alt', 'filters' => ['status' => 'open']]);

        $this->list()
            ->set('collaboratorFilter', [(string) $this->anna->id])
            ->call('applyFilter', $old->id)
            ->assertSet('collaboratorFilter', []);
    }

    public function test_the_chip_of_a_long_filter_wraps_instead_of_widening_the_page(): void
    {
        $this->list()
            ->set('collaboratorFilter', [(string) $this->anna->id, (string) $this->ben->id])
            ->assertSeeHtml('class="min-w-0 whitespace-normal [overflow-wrap:anywhere]"');
    }

    public function test_the_filter_has_a_field_in_the_filter_dialog(): void
    {
        $this->list()->assertSeeHtml('wire:model.live="collaboratorFilter"');
    }
}
