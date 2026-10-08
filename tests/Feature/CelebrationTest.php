<?php

namespace Tests\Feature;

use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Enums\ProjectRole;
use App\Models\Automation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\CelebrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CelebrationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sprint.celebration_chance' => 1.0]);
        $this->project = Project::factory()->create();
        $this->editor = User::factory()->create();
        $this->project->setRole($this->editor, ProjectRole::Editor);
    }

    private function task(bool $done = false): Task
    {
        return Task::factory()->for($this->project)->create(['status_id' => $done ? $this->project->doneStatus()->id : $this->project->defaultStatus()->id]);
    }

    public function test_completing_a_task_on_the_project_page_sends_the_unicorn(): void
    {
        $task = $this->task();

        Livewire::actingAs($this->editor)->test('pages::projects.show', ['project' => $this->project])
            ->call('toggleDone', $task->id)
            ->assertDispatched('task-completed');

        $this->assertTrue($task->fresh()->isDone());
    }

    public function test_completing_a_task_on_its_own_page_sends_the_unicorn(): void
    {
        $task = $this->task();

        Livewire::actingAs($this->editor)->test('pages::tasks.show', ['task' => $task])
            ->call('toggleDoneByShortcut')
            ->assertDispatched('task-completed');
    }

    public function test_reopening_a_task_or_other_actions_send_nothing(): void
    {
        $done = $this->task(done: true);
        $open = $this->task();

        $page = Livewire::actingAs($this->editor)->test('pages::projects.show', ['project' => $this->project]);

        $page->call('toggleDone', $done->id)->assertNotDispatched('task-completed');
        $this->assertFalse($done->fresh()->isDone());

        $open->update(['title' => 'Neu']);
        $page->call('$refresh')->assertNotDispatched('task-completed');
    }

    public function test_the_chance_decides(): void
    {
        config(['sprint.celebration_chance' => 0]);
        $task = $this->task();

        Livewire::actingAs($this->editor)->test('pages::projects.show', ['project' => $this->project])
            ->call('toggleDone', $task->id)
            ->assertNotDispatched('task-completed');

        $this->assertTrue($task->fresh()->isDone());
    }

    public function test_the_default_is_about_one_in_sixteen(): void
    {
        $this->assertEqualsWithDelta(1 / 16, (float) (require base_path('config/sprint.php'))['celebration_chance'], 0.01);
    }

    public function test_people_can_turn_it_off_in_their_profile(): void
    {
        $page = Livewire::actingAs($this->editor)->test('pages::profile')->assertSet('celebrations', true);

        $page->set('celebrations', false);
        $this->assertFalse($this->editor->fresh()->celebrations_enabled);

        $task = $this->task();
        Livewire::actingAs($this->editor->fresh())->test('pages::projects.show', ['project' => $this->project])
            ->call('toggleDone', $task->id)
            ->assertNotDispatched('task-completed');

        $page->set('celebrations', true);
        $this->assertTrue($this->editor->fresh()->celebrations_enabled);
    }

    public function test_at_most_one_unicorn_per_action_even_when_several_tasks_are_completed(): void
    {
        $this->actingAs($this->editor);
        $first = $this->task();
        $second = $this->task();

        $first->toggleDone();
        $second->toggleDone();

        $service = app(CelebrationService::class);
        $this->assertTrue($service->consume());
        $this->assertFalse($service->consume());
    }

    public function test_nobody_is_celebrated_without_a_login_or_for_what_a_rule_does(): void
    {
        $service = app(CelebrationService::class);

        $this->task()->toggleDone();
        $this->assertFalse($service->consume());

        $creator = User::factory()->create();
        $this->project->setRole($creator, ProjectRole::Admin);
        Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $creator->id,
            'trigger' => AutomationTrigger::AssigneeChanged,
            'trigger_value' => null,
            'actions' => [['type' => AutomationAction::SetStatus->value, 'value' => $this->project->doneStatus()->id]],
        ]);
        $task = $this->task();

        $this->actingAs($this->editor);
        $task->update(['assignee_id' => $this->editor->id]);

        $this->assertTrue($task->fresh()->isDone());
        $this->assertFalse($service->consume());
    }

    public function test_every_page_carries_the_unicorn_and_the_drawing_exists(): void
    {
        $this->actingAs($this->editor)->get(route('projects.index'))->assertOk()
            ->assertSee('x-on:task-completed.window="fly()"', false)
            ->assertSee('unicorn.svg', false);

        $this->assertStringContainsString('<svg', (string) file_get_contents(public_path('unicorn.svg')));
        $this->assertStringContainsString('prefers-reduced-motion', (string) file_get_contents(resource_path('views/components/celebration.blade.php')));
    }
}
