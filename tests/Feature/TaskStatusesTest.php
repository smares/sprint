<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskStatusesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->project = Project::factory()->create();
    }

    public function test_new_projects_start_with_three_default_statuses(): void
    {
        $this->assertSame(['Offen', 'In Arbeit', 'Erledigt'], $this->project->statuses->pluck('name')->all());
        $this->assertSame('Offen', $this->project->defaultStatus()->name);
        $this->assertSame('Erledigt', $this->project->doneStatus()->name);
    }

    public function test_statuses_are_separate_per_project(): void
    {
        $other = Project::factory()->create();

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->set('newName', 'Im Test')
            ->call('add');

        $this->assertContains('Im Test', $this->project->statuses()->pluck('name')->all());
        $this->assertNotContains('Im Test', $other->statuses()->pluck('name')->all());
    }

    public function test_status_page_renders_and_requires_login(): void
    {
        $this->get(route('projects.statuses', $this->project))->assertOk()->assertSee('In Arbeit');

        auth()->logout();
        $this->get(route('projects.statuses', $this->project))->assertRedirect(route('login'));
    }

    public function test_status_can_be_added_with_a_name(): void
    {
        $component = Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->call('add')
            ->assertHasErrors('newName');

        $component->set('newName', 'Im Test')->call('add')->assertHasNoErrors();

        $status = $this->project->statuses()->where('name', 'Im Test')->firstOrFail();
        $this->assertFalse($status->is_done);
        $this->assertSame(3, $status->position);
    }

    public function test_status_can_be_renamed_and_recolored(): void
    {
        $status = $this->project->statuses[1];

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->set("names.{$status->id}", 'Aktiv')
            ->set("colors.{$status->id}", 'pink')
            ->set("colors.{$status->id}", 'nonsense')
            ->assertSet("colors.{$status->id}", 'pink');

        $status->refresh();
        $this->assertSame('Aktiv', $status->name);
        $this->assertSame('pink', $status->color);
    }

    public function test_status_cannot_be_renamed_to_empty(): void
    {
        $status = $this->project->statuses[0];

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->set("names.{$status->id}", '  ')
            ->assertSet("names.{$status->id}", 'Offen');

        $this->assertSame('Offen', $status->fresh()->name);
    }

    public function test_statuses_can_be_reordered_and_change_the_default(): void
    {
        [$open, $inProgress] = [$this->project->statuses[0], $this->project->statuses[1]];

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->call('move', $inProgress->id, 0);

        $this->assertSame($inProgress->id, $this->project->defaultStatus()->id);
        $this->assertSame([$inProgress->id, $open->id], $this->project->statuses()->limit(2)->pluck('id')->all());
    }

    public function test_status_of_another_project_cannot_be_changed(): void
    {
        $foreign = Project::factory()->create()->statuses[0];

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->call('move', $foreign->id, 0)
            ->assertStatus(404);
    }

    public function test_last_done_status_cannot_be_made_open(): void
    {
        $done = $this->project->doneStatus();

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->set("done.{$done->id}", false)
            ->assertSet("done.{$done->id}", true);

        $this->assertTrue($done->fresh()->is_done);
    }

    public function test_last_open_status_cannot_be_made_done(): void
    {
        [$open, $inProgress] = [$this->project->statuses[0], $this->project->statuses[1]];

        $component = Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->set("done.{$open->id}", true);

        $component->set("done.{$inProgress->id}", true)->assertSet("done.{$inProgress->id}", false);

        $this->assertFalse($inProgress->fresh()->is_done);
    }

    public function test_second_done_status_can_be_added(): void
    {
        $extra = TaskStatus::factory()->for($this->project)->create();

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->set("done.{$extra->id}", true);

        $this->assertTrue($extra->fresh()->is_done);
    }

    public function test_deleting_a_status_moves_its_tasks_to_the_replacement(): void
    {
        $inProgress = $this->project->statuses[1];
        $open = $this->project->statuses[0];
        $task = Task::factory()->for($this->project)->inProgress()->create();

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->call('confirmDelete', $inProgress->id)
            ->call('delete')
            ->assertHasErrors('replacementId')
            ->set('replacementId', (string) $open->id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('task_statuses', ['id' => $inProgress->id]);
        $this->assertSame($open->id, $task->fresh()->status_id);
    }

    public function test_status_without_tasks_can_be_deleted_without_replacement(): void
    {
        $inProgress = $this->project->statuses[1];

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->call('confirmDelete', $inProgress->id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('task_statuses', ['id' => $inProgress->id]);
    }

    public function test_replacement_must_belong_to_the_same_project(): void
    {
        $inProgress = $this->project->statuses[1];
        $foreign = Project::factory()->create()->statuses[0];
        Task::factory()->for($this->project)->inProgress()->create();

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->call('confirmDelete', $inProgress->id)
            ->set('replacementId', (string) $foreign->id)
            ->call('delete')
            ->assertHasErrors('replacementId');

        $this->assertDatabaseHas('task_statuses', ['id' => $inProgress->id]);
    }

    public function test_last_done_status_cannot_be_deleted(): void
    {
        $done = $this->project->doneStatus();

        Livewire::test('pages::projects.statuses', ['project' => $this->project])
            ->call('confirmDelete', $done->id)
            ->assertSet('deletingId', '');

        $this->assertDatabaseHas('task_statuses', ['id' => $done->id]);
    }

    public function test_board_and_task_page_use_the_custom_statuses(): void
    {
        $custom = TaskStatus::factory()->for($this->project)->create(['name' => 'Im Test', 'position' => 5]);
        $task = Task::factory()->for($this->project)->create(['status_id' => $custom->id]);

        $this->get(route('projects.board', $this->project))->assertOk()->assertSee('Im Test');
        $this->get(route('tasks.show', $task))->assertOk()->assertSee('Im Test');
    }

    public function test_task_can_be_set_to_a_custom_status_and_toggle_done_uses_done_statuses(): void
    {
        $custom = TaskStatus::factory()->for($this->project)->create(['name' => 'Im Test', 'position' => 5]);
        $task = Task::factory()->for($this->project)->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('statusId', (string) $custom->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($custom->id, $task->fresh()->status_id);

        $task->fresh()->toggleDone();
        $this->assertTrue($task->fresh()->isDone());

        $task->fresh()->toggleDone();
        $this->assertSame($this->project->defaultStatus()->id, $task->fresh()->status_id);
    }
}
