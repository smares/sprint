<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCommented;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TaskCreatedToastTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = User::factory()->create([]);
        $this->project = Project::factory()->create();
        $this->project->setRole($this->anna, ProjectRole::Editor);
    }

    private function toast(string $text): Closure
    {
        return fn (string $event, array $params) => ($params['slots']['text'] ?? null) === $text;
    }

    private function created(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes + ['title' => 'Neu']);
    }

    public function test_the_list_says_when_it_was_saved(): void
    {
        $task = $this->created();

        Livewire::actingAs($this->anna)->test('pages::projects.show', ['project' => $this->project])
            ->dispatch('task-created', taskId: $task->id)
            ->assertDispatched('toast-show', $this->toast('Aufgabe angelegt.'));
    }

    public function test_the_list_says_when_the_filters_hide_the_new_task(): void
    {
        $task = $this->created(['status_id' => $this->project->doneStatus()->id]);

        Livewire::actingAs($this->anna)->test('pages::projects.show', ['project' => $this->project])
            ->dispatch('task-created', taskId: $task->id)
            ->assertDispatched('toast-show', $this->toast('Aufgabe angelegt, aber die aktuellen Filter blenden sie aus.'));
    }

    public function test_the_calendar_and_the_timeline_say_when_the_new_task_has_no_date(): void
    {
        $undated = $this->created();
        $dated = $this->created(['due_date' => now()->toDateString()]);

        foreach (['pages::projects.calendar', 'pages::projects.timeline'] as $page) {
            Livewire::actingAs($this->anna)->test($page, ['project' => $this->project])
                ->dispatch('task-created', taskId: $undated->id)
                ->assertDispatched('toast-show', $this->toast('Aufgabe angelegt, erscheint hier aber nicht, weil sie kein Datum hat.'));

            Livewire::actingAs($this->anna)->test($page, ['project' => $this->project])
                ->dispatch('task-created', taskId: $dated->id)
                ->assertDispatched('toast-show', $this->toast('Aufgabe angelegt.'));
        }
    }

    public function test_the_board_says_when_it_was_saved(): void
    {
        $task = $this->created();

        Livewire::actingAs($this->anna)->test('pages::projects.board', ['project' => $this->project])
            ->dispatch('task-created', taskId: $task->id)
            ->assertDispatched('toast-show', $this->toast('Aufgabe angelegt.'));
    }

    public function test_the_task_in_the_flyout_panel_leaves_the_inbox_to_the_flyout(): void
    {
        $task = $this->created(['assignee_id' => $this->anna->id]);
        $this->anna->notifications()->create(['id' => (string) Str::uuid(), 'type' => TaskCommented::class, 'data' => ['task_id' => $task->id, 'kind' => 'commented', 'by' => 'Ben']]);

        Livewire::actingAs($this->anna)->test('pages::tasks.show', ['task' => $task, 'panel' => true])->assertNotDispatched('inbox-changed');
        $this->assertSame(1, $this->anna->unreadNotifications()->count());

        Livewire::actingAs($this->anna)->test('pages::tasks.show', ['task' => $task])->assertDispatched('inbox-changed');
        $this->assertSame(0, $this->anna->unreadNotifications()->count());
    }

    public function test_the_browser_buttons_back_and_forward_count_as_seeing_the_task(): void
    {
        $task = $this->created(['assignee_id' => $this->anna->id]);
        $this->anna->notifications()->create(['id' => (string) Str::uuid(), 'type' => TaskCommented::class, 'data' => ['task_id' => $task->id, 'kind' => 'commented', 'by' => 'Ben']]);

        Livewire::actingAs($this->anna)->test('task-flyout')->set('openTaskId', (string) $task->id)->assertDispatched('inbox-changed');

        $this->assertSame(0, $this->anna->unreadNotifications()->count());
    }
}
