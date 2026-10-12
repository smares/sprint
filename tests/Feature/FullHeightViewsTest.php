<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FullHeightViewsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        Task::factory()->for($this->project)->create(['due_date' => today()]);
    }

    public function test_the_project_views_fill_the_height_with_their_headers_kept_in_sight(): void
    {
        foreach (['projects.show', 'projects.board', 'projects.calendar', 'projects.timeline'] as $route) {
            $this->get(route($route, $this->project))->assertOk()->assertSee('data-fills-height', false);
        }

        $this->get(route('projects.show', $this->project))->assertSee('sticky top-0 z-20', false);
        $this->get(route('projects.timeline', $this->project))->assertSee('sticky top-0 z-20', false);
        $this->get(route('projects.calendar', $this->project))->assertSee('data-calendar-month', false);
    }

    public function test_more_tasks_load_from_the_end_of_the_table_itself(): void
    {
        Task::factory()->for($this->project)->count(60)->create();

        $html = Livewire::test('pages::projects.show', ['project' => $this->project])->html();

        // In the table, so it only shows (and loads) at the end of the list while the list scrolls within the window
        $this->assertMatchesRegularExpression('/<table[^>]*data-flux-table.*wire:intersect="loadMore".*<\/table>/s', $html);
    }
}
