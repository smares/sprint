<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Mia']);
        $this->actingAs($this->me);
    }

    private function project(string $name, array $attributes = []): Project
    {
        $project = Project::factory()->create(['name' => $name, ...$attributes]);
        $project->setRole($this->me, ProjectRole::Editor);

        return $project;
    }

    public function test_favorites_come_first_then_the_recently_opened_projects(): void
    {
        $favorite = $this->project('Liebling');
        $visited = $this->project('Besucht');
        $this->project('Nie geöffnet');
        $this->me->favoriteProjects()->attach($favorite->id, ['position' => 0]);
        $this->me->rememberProjectVisit($visited);

        Livewire::test('sidebar-projects')
            ->assertSeeInOrder(['Favoriten', 'Liebling', 'Zuletzt geöffnet', 'Besucht'])
            ->assertDontSee('Nie geöffnet')
            ->assertSeeHtml('data-project-icon');
    }

    public function test_hidden_and_archived_projects_stay_out(): void
    {
        $archived = $this->project('Archiviert', ['archived_at' => now()]);
        $foreign = Project::factory()->create(['name' => 'Fremd']);
        $this->me->favoriteProjects()->attach([$archived->id => ['position' => 0], $foreign->id => ['position' => 1]]);

        Livewire::test('sidebar-projects')->assertDontSee('Archiviert')->assertDontSee('Fremd')->assertSee('Alle Projekte');
    }

    public function test_the_current_project_shows_its_views_with_the_open_one_marked(): void
    {
        $project = $this->project('Website');

        $html = $this->get(route('projects.board', $project))->assertOk()->getContent();
        $start = (int) strpos($html, 'data-sidebar-views');
        $views = substr($html, $start, (int) strpos($html, '</nav>', $start) - $start);

        foreach (['Liste', 'Board', 'Kalender', 'Zeitleiste', 'Statistik'] as $label) {
            $this->assertStringContainsString($label, $views);
        }
        $this->assertStringContainsString('href="'.route('projects.calendar', $project).'"', $views);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('projects.board', $project), '/').'"[^>]*data-current/', $views);

        // A task of the project opens it in the sidebar too
        $task = Task::factory()->for($project)->create();
        $this->get(route('tasks.show', $task))->assertOk()->assertSee('data-sidebar-views', false);
        $this->get(route('tasks.mine'))->assertOk()->assertDontSee('data-sidebar-views', false);
    }

    public function test_the_sidebar_offers_the_main_pages_and_counts_the_unread_inbox(): void
    {
        $project = $this->project('Website');
        $task = Task::factory()->for($project)->create(['assignee_id' => $this->me->id]);
        $this->actingAs(User::factory()->admin()->create());
        Comment::factory()->create(['task_id' => $task->id, 'body' => 'Neu']);
        $this->actingAs($this->me);

        $this->get(route('tasks.mine'))->assertOk()
            ->assertSee('Meine Aufgaben')->assertSee('Auslastung')->assertSee('Posteingang')
            ->assertDontSee(route('admin.users'));

        Livewire::test('notification-bell')->assertSee('Posteingang')->assertSeeHtml('>1<');
        // On phones a dot on the menu button says the same
        Livewire::test('notification-bell', ['variant' => 'dot', 'announces' => false])->assertSeeHtml('data-unread-dot');
        $this->me->unreadNotifications->markAsRead();
        Livewire::test('notification-bell', ['variant' => 'dot', 'announces' => false])->assertDontSeeHtml('data-unread-dot');
    }
}
