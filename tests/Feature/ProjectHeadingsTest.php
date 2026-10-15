<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectHeadingsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        Task::factory()->for($this->project)->create(['title' => 'Überschrift Eins', 'is_section' => true, 'position' => 1]);
        Task::factory()->for($this->project)->create(['title' => 'Erste Aufgabe', 'position' => 2]);
    }

    public function test_the_plain_list_shows_headings_among_the_tasks_without_task_controls(): void
    {
        $component = Livewire::test('pages::projects.show', ['project' => $this->project])
            ->assertSee('Überschrift Eins')
            ->assertSee('Erste Aufgabe');

        $this->assertSame(1, substr_count($component->html(), 'data-opens-task'));
    }

    public function test_a_filter_or_sort_order_leaves_the_headings_out(): void
    {
        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->set('sortBy', 'title')
            ->assertDontSee('Überschrift Eins')
            ->set('sortBy', '')
            ->set('assigneeFilter', 'me')
            ->assertDontSee('Überschrift Eins');
    }

    public function test_the_board_and_its_counts_leave_the_headings_out(): void
    {
        $component = Livewire::test('pages::projects.board', ['project' => $this->project])
            ->assertDontSee('Überschrift Eins')
            ->assertSee('Erste Aufgabe');

        $this->assertSame(1, array_sum($component->get('columnTotals')));
    }
}
