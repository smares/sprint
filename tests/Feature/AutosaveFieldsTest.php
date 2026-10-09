<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fields without a save button save on their own and confirm it with a short toast (App\Concerns\ConfirmsAutosave).
 * Since Livewire 4.1, wire:model.blur, .change and .enter only update the value in the browser. Fields that save on
 * their own need .live as well, or nothing reaches the server until some other action sends a request.
 */
class AutosaveFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_field_waits_for_blur_change_or_enter_without_sending_it(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/wire:model((?:\.[a-z0-9]+)*)=/', $file->getContents(), $matches);

            foreach ($matches[1] as $modifiers) {
                $modifiers = explode('.', ltrim($modifiers, '.'));

                if (array_intersect($modifiers, ['blur', 'change', 'enter']) !== [] && ! in_array('live', $modifiers, true)) {
                    $offenders[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'wire:model with .blur, .change or .enter but without .live never sends the value');
    }

    public function test_renaming_a_team_is_sent_when_the_field_loses_the_focus(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $team = Team::create(['name' => 'Design']);

        Livewire::test('pages::admin.teams')
            ->assertSeeHtml('wire:model.live.blur="names.'.$team->id.'"')
            ->set("names.{$team->id}", 'Gestaltung');

        $this->assertSame('Gestaltung', $team->fresh()->name);
    }

    private function savedToast(): Closure
    {
        return fn (string $event, array $params) => $params['slots']['text'] === 'Gespeichert.' && $params['dataset']['variant'] === 'success' && $params['duration'] === 2000;
    }

    public function test_saving_on_its_own_confirms_it_with_a_short_toast(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $team = Team::create(['name' => 'Design']);
        $project = Project::factory()->create();
        $status = $project->statuses()->first();
        $member = User::factory()->create();
        $project->setRole($member, ProjectRole::Editor);

        Livewire::test('pages::admin.teams')->set("names.{$team->id}", 'Gestaltung')->assertDispatched('toast-show', $this->savedToast());
        Livewire::test('project-statuses', ['project' => $project])->set("names.{$status->id}", 'Neu')->assertDispatched('toast-show', $this->savedToast());
        Livewire::test('project-tags', ['project' => $project])->set('newName', 'Frontend')->call('add');
        $tag = $project->tags()->firstOrFail();
        Livewire::test('project-tags', ['project' => $project])->set("colors.{$tag->id}", '#ff0000')->assertDispatched('toast-show', $this->savedToast());
        Livewire::test('pages::projects.members', ['project' => $project])->set("roles.{$member->id}", ProjectRole::Viewer->value)->assertDispatched('toast-show', $this->savedToast());
        Livewire::test('pages::tasks.show', ['task' => Task::factory()->for($project)->create()])->set('notificationsOn', false)->assertDispatched('toast-show', $this->savedToast());

        $this->assertSame(ProjectRole::Viewer, $project->roleFor($member));
    }

    public function test_a_refused_change_shows_only_the_error(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $team = Team::create(['name' => 'Design']);

        Livewire::test('pages::admin.teams')->set("names.{$team->id}", '')
            ->assertDispatched('toast-show', fn (string $event, array $params) => $params['dataset']['variant'] === 'danger')
            ->assertNotDispatched('toast-show', $this->savedToast());

        $this->assertSame('Design', $team->fresh()->name);
    }
}
