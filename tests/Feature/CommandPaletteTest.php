<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CommandPaletteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($this->user);
    }

    private function project(string $name, ?ProjectRole $role = ProjectRole::Editor, array $attributes = []): Project
    {
        $project = Project::factory()->create(['name' => $name] + $attributes);

        if ($role instanceof ProjectRole) {
            $project->setRole($this->user, $role);
        }

        return $project;
    }

    public function test_every_page_carries_the_palette_with_the_shortcut_for_logged_in_people_only(): void
    {
        $this->get(route('projects.index'))->assertOk()
            ->assertSee('data-modal="command-palette"', false)
            ->assertSee('keydown.cmd.k.document', false)
            ->assertSee('keydown.ctrl.k.document', false)
            ->assertSee('Springen oder Aufgaben suchen');

        auth()->logout();
        $this->get(route('login'))->assertOk()->assertDontSee('command-palette', false);
    }

    public function test_it_lists_the_destinations_and_visible_projects_only(): void
    {
        $this->project('Website');
        $this->project('Geheim', null);
        $this->project('Alt', ProjectRole::Editor, ['archived_at' => now()]);

        $this->get(route('projects.index'))->assertOk()
            ->assertSee('Gehe zu')->assertSee('Posteingang')->assertSee('Meine Aufgaben')
            ->assertSee('Darstellung')
            ->assertDontSee('Teams')->assertDontSee('Benutzer');

        Livewire::test('command-palette')
            ->assertSee('Website')->assertDontSee('Geheim')->assertDontSee('Alt');
    }

    public function test_typing_narrows_the_projects_on_the_server(): void
    {
        $this->project('Website Relaunch');
        $this->project('Buchhaltung');

        Livewire::test('command-palette')
            ->set('query', 'relaunch')
            ->assertSee('Website Relaunch')
            ->assertDontSee('Buchhaltung');
    }

    public function test_admins_also_get_the_admin_destinations_and_all_projects(): void
    {
        $this->user->forceFill(['is_admin' => true])->save();
        $this->project('Fremd', null);

        Livewire::test('command-palette')->assertSee('Teams')->assertSee('Benutzer')->assertSee('Fremd');
    }

    public function test_tasks_show_up_once_two_characters_were_typed_and_open_in_the_side_panel(): void
    {
        $project = $this->project('Website');
        $task = Task::factory()->for($project)->create(['title' => 'Angebot schreiben']);

        $palette = Livewire::test('command-palette')->assertDontSee('Angebot schreiben');

        $palette->set('query', 'a')->assertDontSee('Angebot schreiben');
        $palette->set('query', 'angeb')
            ->assertSee('Angebot schreiben')
            ->assertSeeHtml(e(route('projects.show', ['project' => $project->id, 'task' => $task->id])) ?: '')
            ->assertSee('Alle Ergebnisse für „angeb“ anzeigen');
    }

    public function test_only_tasks_from_visible_projects_are_offered(): void
    {
        Task::factory()->for($this->project('Meins'))->create(['title' => 'Offenes Thema']);
        Task::factory()->for($this->project('Fremd', null))->create(['title' => 'Offenes Fremdthema']);

        Livewire::test('command-palette')->set('query', 'offenes')
            ->assertSee('Offenes Thema')->assertDontSee('Offenes Fremdthema');
    }

    public function test_results_are_limited_and_headings_are_not_matched(): void
    {
        $project = $this->project('Website');
        Task::factory()->for($project)->count(12)->create(['title' => 'Massenaufgabe']);
        Task::factory()->for($project)->create(['title' => 'Überschrift', 'is_section' => true]);

        $palette = Livewire::test('command-palette')->set('query', 'massenaufgabe');
        $this->assertCount(8, $palette->instance()->tasks);

        $this->assertCount(0, $palette->set('query', 'überschrift')->instance()->tasks);
    }

    public function test_special_characters_in_the_query_are_harmless(): void
    {
        $this->project('Website');

        foreach (['"', "'; drop table tasks;--", '<script>alert(1)</script>', 'a*b', '(x)'] as $query) {
            Livewire::test('command-palette')->set('query', $query)->assertOk();
        }

        Livewire::test('command-palette')->set('query', '<b>fett</b>')->assertDontSeeHtml('<b>fett</b>');
    }

    public function test_the_flux_texts_are_german(): void
    {
        $this->assertSame('Nichts gefunden', __('No results found'));
        $this->assertSame('Datum wählen', __('Select a date'));
        $this->get(route('projects.index'))->assertOk()->assertSee('Schließen');
    }
}
