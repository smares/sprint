<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectFavoritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_starring_projects_lists_them_as_favorites_in_the_order_they_were_starred(): void
    {
        $this->actingAs($user = User::factory()->admin()->create());
        [$alpha, $beta, $gamma] = collect(['Alpha', 'Beta', 'Gamma'])->map(fn (string $name) => Project::factory()->create(['name' => $name]))->all();

        $page = Livewire::test('pages::projects.index')
            ->assertSee('Markiere ein Projekt mit dem Stern')
            ->call('toggleFavorite', $gamma->id)
            ->call('toggleFavorite', $alpha->id)
            ->assertSee('Favoriten')->assertSee('Alle Projekte')->assertDontSee('Markiere ein Projekt mit dem Stern');

        $this->assertSame(['Gamma', 'Alpha'], $page->instance()->favorites->pluck('name')->all());
        $this->assertSame([$gamma->id, $alpha->id], $user->favoriteProjects()->pluck('projects.id')->all());

        $page->call('toggleFavorite', $gamma->id);
        $this->assertSame(['Alpha'], $page->instance()->favorites->pluck('name')->all());
        $this->assertNotNull($beta);
    }

    public function test_favorites_are_sorted_per_person(): void
    {
        $this->actingAs($anna = User::factory()->admin()->create());
        $projects = collect(['Alpha', 'Beta', 'Gamma'])->map(fn (string $name) => Project::factory()->create(['name' => $name]));
        $page = Livewire::test('pages::projects.index');
        $projects->each(fn (Project $project) => $page->call('toggleFavorite', $project->id));

        $page->call('moveFavorite', $projects[2]->id, 0);
        $this->assertSame(['Gamma', 'Alpha', 'Beta'], $page->instance()->favorites->pluck('name')->all());

        $page->call('moveFavorite', (string) $projects[2]->id, 2);
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $page->instance()->favorites->pluck('name')->all());

        $this->actingAs($ben = User::factory()->admin()->create());
        $benPage = Livewire::test('pages::projects.index');
        $benPage->call('toggleFavorite', $projects[1]->id);
        $this->assertSame(['Beta'], $benPage->instance()->favorites->pluck('name')->all());
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $anna->favoriteProjects()->pluck('name')->all());
    }

    public function test_only_visible_projects_can_be_starred_and_archived_ones_are_not_listed(): void
    {
        $this->actingAs($user = User::factory()->create());
        $mine = Project::factory()->create(['name' => 'Meins']);
        $mine->setRole($user, ProjectRole::Viewer);
        $foreign = Project::factory()->create();

        Livewire::test('pages::projects.index')->call('toggleFavorite', $foreign->id)->assertNotFound();

        $page = Livewire::test('pages::projects.index')->call('toggleFavorite', $mine->id);
        $this->assertSame(['Meins'], $page->instance()->favorites->pluck('name')->all());

        $mine->update(['archived_at' => now()]);
        $this->assertSame([], Livewire::test('pages::projects.index')->instance()->favorites->all());
    }

    public function test_moving_something_that_is_no_favorite_changes_nothing(): void
    {
        $this->actingAs($user = User::factory()->admin()->create());
        $project = Project::factory()->create();

        Livewire::test('pages::projects.index')->call('moveFavorite', $project->id, 0)->assertOk();
        $this->assertSame([], $user->favoriteProjects()->pluck('projects.id')->all());
    }
}
