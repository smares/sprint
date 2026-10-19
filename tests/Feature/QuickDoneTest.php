<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuickDoneTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 10:00:00');
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
    }

    /**
     * @return list<string>
     */
    private function views(): array
    {
        return ['pages::projects.board', 'pages::projects.calendar', 'pages::projects.timeline'];
    }

    public function test_every_view_offers_the_check_before_a_task(): void
    {
        Task::factory()->for($this->project)->create(['title' => 'Abhaken', 'start_date' => '2026-10-10', 'due_date' => '2026-10-12']);

        foreach ($this->views() as $view) {
            Livewire::test($view, ['project' => $this->project])
                ->assertSee('Abhaken')
                ->assertSeeHtml('aria-label="Als erledigt markieren"');
        }
    }

    public function test_the_check_marks_a_task_done_and_opens_it_again_in_every_view(): void
    {
        $task = Task::factory()->for($this->project)->create(['start_date' => '2026-10-10', 'due_date' => '2026-10-12']);

        foreach ($this->views() as $view) {
            $page = Livewire::test($view, ['project' => $this->project]);

            $page->call('toggleDone', $task->id);
            $this->assertTrue($task->fresh()->isDone());

            $page->call('toggleDone', $task->id);
            $this->assertFalse($task->fresh()->isDone());
        }
    }

    public function test_people_who_may_not_edit_cannot_check_tasks(): void
    {
        $task = Task::factory()->for($this->project)->create(['start_date' => '2026-10-10', 'due_date' => '2026-10-12']);
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        foreach ($this->views() as $view) {
            Livewire::actingAs($viewer)->test($view, ['project' => $this->project])
                ->call('toggleDone', $task->id)->assertForbidden();
        }

        $this->assertFalse($task->fresh()->isDone());
    }

    public function test_a_task_of_another_project_cannot_be_checked(): void
    {
        $foreign = Task::factory()->for(Project::factory()->create())->create();

        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('toggleDone', $foreign->id)->assertNotFound();

        $this->assertFalse($foreign->fresh()->isDone());
    }
}
