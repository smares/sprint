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

    public function test_favourites_lead_in_their_own_order_followed_by_the_rest_alphabetically(): void
    {
        $alpha = $this->project('Alpha');
        $this->project('Beta');
        $gamma = $this->project('Gamma');
        $delta = $this->project('Delta');
        $this->user->favoriteProjects()->attach([$gamma->id => ['position' => 1], $delta->id => ['position' => 2]]);

        $palette = Livewire::test('command-palette');

        $this->assertSame(['Gamma', 'Delta', 'Alpha', 'Beta'], $palette->instance()->projects->pluck('name')->all());
        $palette->assertSeeInOrder(['Gamma', 'Delta', 'Alpha', 'Beta']);
        $this->assertNotContains($alpha->id, $palette->instance()->favoriteIds);
    }

    public function test_the_kept_palette_picks_up_favorites_and_renamed_projects_without_a_reload(): void
    {
        $alpha = $this->project('Alpha');
        $this->project('Beta');
        $palette = Livewire::test('command-palette');
        $this->assertSame(['Alpha', 'Beta'], $palette->instance()->projects->pluck('name')->all());

        // Starring on the projects page tells the palette, which stays on the page (@persist)
        Livewire::test('pages::projects.index')->call('toggleFavorite', $this->project('Gamma')->id)->assertDispatched('favorites-changed');
        $palette->dispatch('favorites-changed');
        $this->assertSame(['Gamma', 'Alpha', 'Beta'], $palette->instance()->projects->pluck('name')->all());

        $alpha->update(['name' => 'Alpha neu']);
        $palette->dispatch('project-updated')->assertSee('Alpha neu');
    }

    public function test_names_starting_with_the_query_come_first_and_favourites_lead_within_each(): void
    {
        $this->project('Web Shop');
        $this->project('Neue Website');
        $favouriteInside = $this->project('Alte Website');
        $favouriteStart = $this->project('Website Relaunch');
        $this->user->favoriteProjects()->attach([$favouriteInside->id => ['position' => 1], $favouriteStart->id => ['position' => 2]]);
        $this->project('Website Archiv');

        $names = Livewire::test('command-palette')->set('query', 'web')->instance()->projects->pluck('name')->all();

        $this->assertSame(['Website Relaunch', 'Web Shop', 'Website Archiv', 'Alte Website', 'Neue Website'], $names);
    }

    public function test_without_a_search_it_offers_the_favorites_and_the_ten_last_opened_projects(): void
    {
        $projects = collect(range(1, 14))->map(fn (int $number) => $this->project(sprintf('Projekt %02d', $number)));
        $this->user->favoriteProjects()->attach($projects[13]->id, ['position' => 1]);

        foreach ([5, 2, 9] as $index) {
            $this->travel(1)->minutes();
            $this->user->rememberProjectVisit($projects[$index]);
        }

        $names = Livewire::test('command-palette')->instance()->projects->pluck('name')->all();

        // The favorite, the opened ones (most recent first), then topped up alphabetically to ten
        $this->assertSame(['Projekt 14', 'Projekt 10', 'Projekt 03', 'Projekt 06', 'Projekt 01', 'Projekt 02', 'Projekt 04', 'Projekt 05', 'Projekt 07', 'Projekt 08', 'Projekt 09'], $names);
    }

    public function test_searching_still_finds_every_project_but_shows_at_most_twenty(): void
    {
        collect(range(1, 25))->each(fn (int $number) => $this->project(sprintf('Kunde %02d', $number)));
        $this->project('Intern');

        $palette = Livewire::test('command-palette')->set('query', 'kunde');
        $this->assertCount(20, $palette->instance()->projects);

        $palette->set('query', 'kunde 25')->assertSee('Kunde 25');
    }

    public function test_opening_a_project_moves_it_to_the_front_of_the_recent_ones_and_tells_the_palette(): void
    {
        $first = $this->project('Erstes');
        $second = $this->project('Zweites');

        Livewire::test('pages::projects.show', ['project' => $first])->assertDispatched('project-visited');
        $this->travel(1)->minutes();
        Livewire::test('pages::projects.board', ['project' => $second])->assertDispatched('project-visited');

        // Switching views within the same project does not change the order, so nothing is sent
        Livewire::test('pages::projects.calendar', ['project' => $second])->assertNotDispatched('project-visited');

        $this->assertSame([$second->id, $first->id], $this->user->visitedProjects()->pluck('projects.id')->all());
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

    public function test_the_palette_closes_when_an_entry_is_chosen_and_on_every_page_change(): void
    {
        // The layout keeps the component across page changes (@persist): left open, the dialog would still be there on the next page
        $palette = Livewire::test('command-palette');

        $palette->assertSeeHtml('x-on:livewire:navigate.window="$flux.modal(\'command-palette\').close()"')
            ->assertSeeHtml('$event.target.closest(\'[data-flux-command-item]\')');
    }

    public function test_the_flux_texts_are_german(): void
    {
        $this->assertSame('Nichts gefunden', __('No results found'));
        $this->assertSame('Datum wählen', __('Select a date'));
        $this->get(route('projects.index'))->assertOk()->assertSee('Schließen');
    }
}
