<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Mcp\Servers\SprintServer;
use App\Mcp\Tools\CreateTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create(['name' => 'Acme, Inc']);
    }

    public function test_the_last_active_administrator_cannot_be_revoked_by_command(): void
    {
        $only = User::where('is_admin', true)->firstOrFail();

        $this->artisan('user:admin', ['email' => $only->email, '--revoke' => true])->assertFailed();
        $this->assertTrue($only->fresh()->is_admin);

        $second = User::factory()->admin()->create();

        $this->artisan('user:admin', ['email' => $second->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($second->fresh()->is_admin);
    }

    public function test_the_command_rejects_a_password_that_is_too_short(): void
    {
        $this->artisan('user:create', ['name' => 'Kurz', 'email' => 'kurz@example.com', '--password' => 'abc'])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'kurz@example.com']);
    }

    public function test_a_new_task_can_only_be_assigned_to_a_person_who_belongs_to_the_project(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $this->project->setRole($member, ProjectRole::Editor);

        Livewire::test('task-create', ['project' => $this->project])
            ->set('title', 'Neu')->set('assigneeId', (string) $outsider->id)
            ->call('create')
            ->assertHasErrors('assigneeId');

        Livewire::test('task-create', ['project' => $this->project])
            ->set('title', 'Neu')->set('assigneeId', (string) $member->id)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame($member->id, Task::where('title', 'Neu')->firstOrFail()->assignee_id);
    }

    public function test_a_project_with_a_comma_in_its_name_needs_the_whole_name_to_be_deleted(): void
    {
        Livewire::test('project-settings', ['project' => $this->project])
            ->set('confirmName', 'Acme')
            ->call('delete')
            ->assertHasErrors('confirmName');

        $this->assertModelExists($this->project);

        Livewire::test('project-settings', ['project' => $this->project])
            ->set('confirmName', 'Acme, Inc')
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertModelMissing($this->project);
    }

    public function test_an_invalid_sort_direction_in_the_address_does_not_break_the_list(): void
    {
        Task::factory()->for($this->project)->count(2)->create();

        foreach (['due', 'title', 'status'] as $column) {
            Livewire::withQueryParams(['sort' => $column, 'dir' => 'sideways'])
                ->test('pages::projects.show', ['project' => $this->project])
                ->assertOk();
        }
    }

    public function test_an_impossible_date_in_the_timeline_address_does_not_break_the_page(): void
    {
        Livewire::withQueryParams(['from' => '2026-13-45'])
            ->test('pages::projects.timeline', ['project' => $this->project])
            ->assertOk();
    }

    public function test_the_current_password_cannot_be_guessed_endlessly_when_changing_it(): void
    {
        $user = User::factory()->create(['password' => 'richtig-geheim']);
        $this->actingAs($user);
        RateLimiter::clear('change-password|'.$user->id.'|127.0.0.1');

        $page = Livewire::test('pages::profile');

        foreach (range(1, 5) as $attempt) {
            $page->set('currentPassword', 'falsch')->set('newPassword', 'ganz-neu-123')->set('newPasswordConfirmation', 'ganz-neu-123')
                ->call('changePassword')->assertHasErrors('currentPassword');
        }

        $page->set('currentPassword', 'richtig-geheim')->set('newPassword', 'ganz-neu-123')->set('newPasswordConfirmation', 'ganz-neu-123')
            ->call('changePassword')->assertHasErrors('currentPassword');

        $this->assertTrue(password_verify('richtig-geheim', $user->fresh()->password));
    }

    public function test_agents_cannot_create_a_task_below_a_heading_or_with_a_loose_date(): void
    {
        $heading = Task::factory()->for($this->project)->create(['is_section' => true]);

        SprintServer::actingAs(User::where('is_admin', true)->firstOrFail())
            ->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'X', 'parent_id' => $heading->id])
            ->assertHasErrors(["Parent task {$heading->id} is not in this project."]);

        SprintServer::actingAs(User::where('is_admin', true)->firstOrFail())
            ->tool(CreateTask::class, ['project_id' => $this->project->id, 'title' => 'X', 'due_date' => 'next monday'])
            ->assertHasErrors();

        $this->assertSame(0, Task::where('title', 'X')->count());
    }

    public function test_viewers_cannot_change_subtasks_or_headings_on_the_task_page(): void
    {
        $task = Task::factory()->for($this->project)->create();
        $child = Task::factory()->for($this->project)->create(['parent_id' => $task->id]);
        $heading = Task::factory()->for($this->project)->create(['parent_id' => $task->id, 'is_section' => true]);
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer);

        $page = fn () => Livewire::test('pages::tasks.show', ['task' => $task]);

        $page()->call('toggleSubtask', $child->id)->assertForbidden();
        $page()->call('addSection', $task->id)->assertForbidden();
        $page()->call('deleteSection', $heading->id)->assertForbidden();
        $page()->call('moveSubtask', $child->id, 0, $task->id)->assertForbidden();

        $this->assertFalse($child->fresh()->isDone());
        $this->assertModelExists($heading);
        $this->assertSame(2, Task::where('parent_id', $task->id)->count());
    }
}
