<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectsAndTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    public function test_projects_page_lists_projects(): void
    {
        Project::factory()->create(['name' => 'Website Relaunch']);
        Project::factory()->create(['name' => 'Altes Projekt', 'archived_at' => now()]);

        $this->get('/projects')
            ->assertOk()
            ->assertSee('Website Relaunch')
            ->assertSee('Archivierte Projekte (1)')
            ->assertSee('Altes Projekt');

        $component = Livewire::test('pages::projects.index');
        $this->assertSame(['Website Relaunch'], $component->instance()->projects->pluck('name')->all());
        $this->assertSame(['Altes Projekt'], $component->instance()->archivedProjects->pluck('name')->all());
    }

    public function test_project_can_be_created(): void
    {
        Livewire::test('pages::projects.index')
            ->set('name', 'Neues Projekt')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', ['name' => 'Neues Projekt']);
    }

    public function test_project_requires_a_name(): void
    {
        Livewire::test('pages::projects.index')
            ->call('create')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_task_can_be_created_in_a_project(): void
    {
        $project = Project::factory()->create();
        $assignee = User::factory()->admin()->create();

        Livewire::test('task-create', ['project' => $project])
            ->set('title', 'Angebot schreiben')
            ->set('assigneeId', (string) $assignee->id)
            ->set('dueDate', '2026-12-24')
            ->call('create')
            ->assertHasNoErrors();

        $task = $project->tasks()->firstOrFail();
        $this->assertSame('Angebot schreiben', $task->title);
        $this->assertSame($assignee->id, $task->assignee_id);
        $this->assertSame($this->user->id, $task->creator_id);
        $this->assertSame('2026-12-24', $task->due_date->toDateString());
        $this->assertSame($project->defaultStatus()->id, $task->status_id);
    }

    public function test_every_project_view_has_the_same_header_with_new_task(): void
    {
        $project = Project::factory()->create(['description' => 'Worum es geht']);

        foreach (['projects.show', 'projects.board', 'projects.calendar', 'projects.timeline'] as $route) {
            $this->get(route($route, $project))->assertOk()
                ->assertSee('Worum es geht')
                ->assertSee('Neue Aufgabe')
                ->assertSee('wire:name="task-create"', false)
                ->assertSee(__('Set up project'));
        }
    }

    public function test_both_create_dialogs_offer_cancel_before_create(): void
    {
        $project = Project::factory()->create();

        // The close button labelled "Abbrechen" right before the submit button (the date picker has a cancel of its own)
        $footer = '/<ui-close[^>]*>\s*<button[^>]*>\s*Abbrechen\s*<\/button>\s*<\/ui-close>\s*<button type="submit"/';

        $this->assertMatchesRegularExpression($footer, Livewire::test('task-create', ['project' => $project])->html());
        $this->assertMatchesRegularExpression($footer, Livewire::test('pages::projects.index')->html());
    }

    public function test_the_new_task_dialog_can_start_with_a_status_and_due_date(): void
    {
        $project = Project::factory()->create();
        $done = $project->doneStatus();

        Livewire::test('task-create', ['project' => $project])
            ->dispatch('new-task', statusId: $done->id, dueDate: '2026-11-03')
            ->assertSet('statusId', (string) $done->id)
            ->assertSet('dueDate', '2026-11-03')
            ->set('title', 'Schon fertig')
            ->call('create')
            ->assertHasNoErrors()
            ->assertDispatched('task-created');

        $task = $project->tasks()->firstOrFail();
        $this->assertSame($done->id, $task->status_id);
        $this->assertSame('2026-11-03', $task->due_date->toDateString());
    }

    public function test_task_without_optional_fields_stores_nulls(): void
    {
        $project = Project::factory()->create();

        Livewire::test('task-create', ['project' => $project])
            ->set('title', 'Nur Titel')
            ->call('create')
            ->assertHasNoErrors();

        $task = $project->tasks()->firstOrFail();
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);
        $this->assertNull($task->description);
    }

    public function test_toggle_done_flips_status(): void
    {
        $task = Task::factory()->create();

        $component = Livewire::test('pages::projects.show', ['project' => $task->project])
            ->call('toggleDone', $task->id);
        $this->assertSame($task->project->doneStatus()->id, $task->fresh()->status_id);

        $component->call('toggleDone', $task->id);
        $this->assertSame($task->project->defaultStatus()->id, $task->fresh()->status_id);
    }

    public function test_toggle_done_cannot_touch_tasks_of_other_projects(): void
    {
        $other = Task::factory()->create();
        $project = Project::factory()->create();

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('toggleDone', $other->id)
            ->assertNotFound();

        $this->assertSame($other->project->defaultStatus()->id, $other->fresh()->status_id);
    }

    public function test_task_list_filters_by_status_and_assignee(): void
    {
        $project = Project::factory()->create();
        Task::factory()->for($project)->create(['title' => 'Offene Aufgabe']);
        Task::factory()->for($project)->done()->create(['title' => 'Fertige Aufgabe']);
        Task::factory()->for($project)->create(['title' => 'Meine Aufgabe', 'assignee_id' => $this->user->id]);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->assertSee('Offene Aufgabe')
            ->assertDontSee('Fertige Aufgabe')
            ->set('statusFilter', 'all')
            ->assertSee('Fertige Aufgabe')
            ->set('statusFilter', (string) $project->doneStatus()->id)
            ->assertSee('Fertige Aufgabe')
            ->assertDontSee('Offene Aufgabe')
            ->set('statusFilter', 'all')
            ->set('assigneeFilter', 'me')
            ->assertSee('Meine Aufgabe')
            ->assertDontSee('Offene Aufgabe');
    }

    public function test_task_can_be_edited(): void
    {
        $task = Task::factory()->create();
        $assignee = User::factory()->admin()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('title', 'Neuer Titel')
            ->set('statusId', (string) $task->project->statuses[1]->id)
            ->set('assigneeId', (string) $assignee->id)
            ->set('dueDate', '2026-11-01')
            ->call('save')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertSame('Neuer Titel', $task->title);
        $this->assertSame($task->project->statuses[1]->id, $task->status_id);
        $this->assertSame($assignee->id, $task->assignee_id);
        $this->assertSame('2026-11-01', $task->due_date->toDateString());
    }

    public function test_task_rejects_invalid_status(): void
    {
        $task = Task::factory()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('statusId', (string) Task::factory()->create()->status_id)
            ->call('save')
            ->assertHasErrors('statusId');
    }

    public function test_comment_can_be_added(): void
    {
        $task = Task::factory()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('comment', 'Sieht gut aus.')
            ->call('addComment')
            ->assertHasNoErrors()
            ->assertSet('comment', '')
            ->assertSee('Sieht gut aus.');

        $this->assertDatabaseHas('comments', ['task_id' => $task->id, 'user_id' => $this->user->id]);
    }

    public function test_empty_comment_is_rejected(): void
    {
        $task = Task::factory()->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('addComment')
            ->assertHasErrors(['comment' => 'required']);
    }

    public function test_task_delete_removes_comments_and_redirects(): void
    {
        $task = Task::factory()->create();
        Comment::factory()->for($task)->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('delete')
            ->assertRedirect(route('projects.show', $task->project_id));

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_my_tasks_shows_only_open_tasks_assigned_to_me(): void
    {
        Task::factory()->create(['title' => 'Für mich', 'assignee_id' => $this->user->id]);
        Task::factory()->done()->create(['title' => 'Schon erledigt', 'assignee_id' => $this->user->id]);
        Task::factory()->create(['title' => 'Für andere', 'assignee_id' => User::factory()->admin()]);

        $this->get('/tasks/mine')
            ->assertOk()
            ->assertSee('Für mich')
            ->assertDontSee('Schon erledigt')
            ->assertDontSee('Für andere');
    }

    public function test_overdue_detection(): void
    {
        $this->assertTrue(Task::factory()->make(['due_date' => now()->subDay()])->isOverdue());
        $this->assertFalse(Task::factory()->make(['due_date' => now()])->isOverdue());
        $this->assertFalse(Task::factory()->done()->make(['due_date' => now()->subDay()])->isOverdue());
        $this->assertFalse(Task::factory()->make(['due_date' => null])->isOverdue());
    }

    public function test_the_list_numbers_its_rows_in_the_order_shown(): void
    {
        $project = Project::factory()->create();
        foreach (['Banane', 'Apfel', 'Kirsche'] as $position => $title) {
            Task::factory()->for($project)->create(['title' => $title, 'position' => $position]);
        }

        $page = Livewire::test('pages::projects.show', ['project' => $project]);

        $page->assertSeeHtml('data-row-number="1"')->assertSeeHtml('data-row-number="3"')->assertDontSeeHtml('data-row-number="4"');
        $page->assertSeeInOrder(['1', 'Banane', '2', 'Apfel', '3', 'Kirsche']);

        $page->call('sort', 'title')->assertSeeInOrder(['1', 'Apfel', '2', 'Banane', '3', 'Kirsche']);
    }
}
