<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create(['name' => 'Website', 'description' => 'Alt']);
    }

    private function settings()
    {
        return Livewire::test('project-settings', ['project' => $this->project]);
    }

    public function test_name_and_description_can_be_changed(): void
    {
        $this->settings()->set('name', '  Relaunch ')->set('description', 'Neu')->call('save')->assertHasNoErrors()->assertDispatched('project-updated');

        $this->assertSame('Relaunch', $this->project->fresh()->name);
        $this->assertSame('Neu', $this->project->fresh()->description);

        $this->settings()->set('description', '')->call('save');
        $this->assertNull($this->project->fresh()->description);
    }

    public function test_the_name_is_required(): void
    {
        $this->settings()->set('name', '')->call('save')->assertHasErrors('name');
        $this->assertSame('Website', $this->project->fresh()->name);
    }

    public function test_archiving_hides_the_project_and_makes_it_read_only(): void
    {
        $task = Task::factory()->for($this->project)->create(['title' => 'Aufgabe']);

        $this->settings()->call('archive')->assertDispatched('project-updated');
        $this->assertNotNull($this->project->fresh()->archived_at);

        $this->get(route('projects.index'))->assertSee('Archivierte Projekte (1)');
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('archiviert und nur noch lesbar');
        $this->get(route('tasks.show', $task))->assertOk()->assertSee('archiviert und nur noch lesbar');

        $page = Livewire::test('pages::tasks.show', ['task' => $task->fresh()])->set('title', 'Geändert')->call('save');
        $page->assertForbidden();
        $this->assertSame('Aufgabe', $task->fresh()->title);

        Livewire::test('pages::projects.show', ['project' => $this->project->fresh()])->call('toggleDone', $task->id)->assertForbidden();
    }

    public function test_an_archived_project_can_be_restored(): void
    {
        $this->project->update(['archived_at' => now()]);

        $this->settings()->call('restore');

        $this->assertNull($this->project->fresh()->archived_at);
        $this->assertTrue(auth()->user()->can('edit', $this->project->fresh()));
    }

    public function test_deleting_needs_the_exact_name(): void
    {
        $this->settings()->set('confirmName', 'webseite')->call('delete')->assertHasErrors('confirmName');
        $this->settings()->call('delete')->assertHasErrors('confirmName');
        $this->assertModelExists($this->project);

        $this->settings()->set('confirmName', 'Website')->call('delete')->assertRedirect(route('projects.index'));
        $this->assertModelMissing($this->project);
    }

    public function test_deleting_removes_tasks_attachments_files_and_search_entries(): void
    {
        $task = Task::factory()->for($this->project)->create(['title' => 'Suchbegriff Zebra']);
        $path = 'attachments/'.$this->project->id.'/datei.pdf';
        Storage::disk()->put($path, 'x');
        Attachment::factory()->for($task)->create(['path' => $path]);
        $other = Task::factory()->for(Project::factory()->create())->create(['title' => 'Zebra bleibt']);

        $this->assertSame(2, app(TaskSearch::class)->search(auth()->user(), 'zebra')->count());

        $this->settings()->set('confirmName', 'Website')->call('delete');

        Storage::disk()->assertMissing($path);
        $this->assertSame(0, Attachment::count());
        $this->assertSame(0, Task::where('project_id', $this->project->id)->count());
        $this->assertSame(1, DB::table(TaskSearch::TABLE)->count());
        $this->assertModelExists($other);
    }

    public function test_only_project_admins_may_open_the_settings(): void
    {
        $editor = User::factory()->create();
        $this->project->setRole($editor, ProjectRole::Editor);
        $this->actingAs($editor);

        Livewire::test('project-settings', ['project' => $this->project])->assertForbidden();
        $this->get(route('projects.show', $this->project))->assertOk()->assertDontSee('project-settings', false);
    }

    public function test_the_settings_are_in_the_gear_menu_for_admins(): void
    {
        $this->get(route('projects.show', $this->project))->assertSee('Einstellungen')->assertSee('data-modal="project-settings"', false);
        $this->get(route('projects.board', $this->project))->assertSee('data-modal="project-settings"', false);
    }

    public function test_actions_stay_protected_after_losing_the_right(): void
    {
        $manager = User::factory()->create();
        $this->project->setRole($manager, ProjectRole::Admin);
        $component = Livewire::actingAs($manager)->test('project-settings', ['project' => $this->project]);

        $this->project->setRole($manager, ProjectRole::Viewer);

        $component->set('name', 'Gekapert')->assertForbidden();
        $this->assertSame('Website', $this->project->fresh()->name);
    }
}
