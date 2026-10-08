<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCommented;
use App\Notifications\UserMentioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectRolesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $viewer;

    private User $editor;

    private User $manager;

    private User $outsider;

    private User $appAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['name' => 'Geheimprojekt']);
        $this->viewer = User::factory()->create();
        $this->editor = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->outsider = User::factory()->create();
        $this->appAdmin = User::factory()->admin()->create();

        $this->project->setRole($this->viewer, ProjectRole::Viewer);
        $this->project->setRole($this->editor, ProjectRole::Editor);
        $this->project->setRole($this->manager, ProjectRole::Admin);
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes);
    }

    public function test_roles_resolve_to_the_right_abilities(): void
    {
        $this->assertNull($this->project->roleFor($this->outsider));
        $this->assertSame(ProjectRole::Viewer, $this->project->roleFor($this->viewer));
        $this->assertSame(ProjectRole::Admin, $this->project->roleFor($this->appAdmin));
        $this->assertNull($this->project->roleFor(null));

        $this->assertTrue($this->viewer->can('view', $this->project));
        $this->assertFalse($this->viewer->can('edit', $this->project));
        $this->assertTrue($this->editor->can('edit', $this->project));
        $this->assertFalse($this->editor->can('manage', $this->project));
        $this->assertTrue($this->manager->can('manage', $this->project));
        $this->assertTrue($this->appAdmin->can('manage', $this->project));
        $this->assertFalse($this->outsider->can('view', $this->project));
    }

    public function test_outsiders_get_a_403_everywhere_in_the_project(): void
    {
        $task = $this->task();
        $this->actingAs($this->outsider);

        $this->get(route('projects.show', $this->project))->assertForbidden();
        $this->get(route('projects.board', $this->project))->assertForbidden();
        $this->get(route('projects.members', $this->project))->assertForbidden();
        $this->get(route('tasks.show', $task))->assertForbidden();
    }

    public function test_project_list_only_shows_projects_the_person_belongs_to(): void
    {
        Project::factory()->create(['name' => 'Fremdes Projekt']);

        $this->actingAs($this->viewer)->get(route('projects.index'))
            ->assertOk()->assertSee('Geheimprojekt')->assertDontSee('Fremdes Projekt');

        $this->actingAs($this->outsider)->get(route('projects.index'))
            ->assertOk()->assertDontSee('Geheimprojekt');

        $this->actingAs($this->appAdmin)->get(route('projects.index'))
            ->assertOk()->assertSee('Geheimprojekt')->assertSee('Fremdes Projekt');
    }

    public function test_my_tasks_hides_tasks_of_projects_the_person_cannot_see(): void
    {
        $this->task(['title' => 'Sichtbar', 'assignee_id' => $this->viewer->id]);
        Task::factory()->create(['title' => 'Unsichtbar', 'assignee_id' => $this->viewer->id]);

        $this->actingAs($this->viewer)->get(route('tasks.mine'))
            ->assertOk()->assertSee('Sichtbar')->assertDontSee('Unsichtbar');
    }

    public function test_viewers_can_read_but_the_page_hides_the_editing_controls(): void
    {
        $task = $this->task(['title' => 'Lesbar']);
        $this->actingAs($this->viewer);

        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('Lesbar')
            ->assertDontSee('Neue Aufgabe')->assertDontSee('Mitglieder');
        $this->get(route('projects.board', $this->project))->assertOk()->assertSee('Lesbar');
        $this->get(route('tasks.show', $task))->assertOk()
            ->assertSee('Nur ansehen')->assertDontSee('Kommentieren')->assertDontSee('Speichern');
    }

    public function test_viewers_cannot_change_anything(): void
    {
        $task = $this->task();
        $this->actingAs($this->viewer);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->set('title', 'Neu')->call('createTask')->assertForbidden();
        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('toggleDone', $task->id)->assertForbidden();
        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('moveTask', $task->id, 0)->assertForbidden();
        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('moveTask', $task->id, 0, (string) $this->project->statuses[1]->id)->assertForbidden();

        $open = fn () => Livewire::test('pages::tasks.show', ['task' => $task]);

        $open()->set('title', 'Anders')->call('save')->assertForbidden();
        $open()->set('comment', 'Hallo')->call('addComment')->assertForbidden();
        $open()->call('delete')->assertForbidden();
        $open()->set('newTag', 'x')->call('createTag')->assertForbidden();
        $open()->set("newSubtaskTitles.{$task->id}", 'x')->call('addSubtask', $task->id)->assertForbidden();

        $this->assertSame($task->title, $task->fresh()->title);
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_viewers_can_still_switch_their_own_notifications_off(): void
    {
        $task = $this->task();
        $this->actingAs($this->viewer);

        Livewire::test('pages::tasks.show', ['task' => $task])->set('notificationsOn', false);

        $this->assertTrue($task->isMutedBy($this->viewer));
    }

    public function test_editors_can_work_but_not_manage(): void
    {
        $task = $this->task();
        $this->actingAs($this->editor);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->set('title', 'Neue Aufgabe')->call('createTask')->assertHasNoErrors();
        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('comment', 'Hallo')->call('addComment')->assertHasNoErrors();

        $this->assertDatabaseHas('tasks', ['title' => 'Neue Aufgabe']);
        $this->assertDatabaseHas('comments', ['body' => 'Hallo']);

        Livewire::test('project-statuses', ['project' => $this->project])->assertForbidden();
        $this->get(route('projects.members', $this->project))->assertForbidden();
        $this->get(route('projects.show', $this->project))->assertDontSee('project-statuses', false);
    }

    public function test_managers_and_app_admins_can_open_the_management_pages(): void
    {
        foreach ([$this->manager, $this->appAdmin] as $person) {
            $this->actingAs($person);

            $this->get(route('projects.show', $this->project))->assertOk()->assertSee('project-statuses', false);
            $this->get(route('projects.board', $this->project))->assertOk()->assertSee('project-statuses', false);
            $this->get(route('projects.members', $this->project))->assertOk();
            $this->get(route('projects.show', $this->project))->assertSee('Mitglieder');
        }
    }

    public function test_status_page_actions_need_the_manage_right_even_after_loading(): void
    {
        $component = Livewire::actingAs($this->manager)->test('project-statuses', ['project' => $this->project]);

        $this->project->setRole($this->manager, ProjectRole::Viewer);
        $this->project->setRole($this->editor, ProjectRole::Admin);

        $component->set('newName', 'Neu')->assertForbidden();
        $this->assertNotContains('Neu', $this->project->statuses()->pluck('name')->all());
    }

    public function test_creating_a_project_makes_the_creator_its_manager(): void
    {
        $this->actingAs($this->outsider);

        Livewire::test('pages::projects.index')->set('name', 'Mein Projekt')->call('create');

        $project = Project::where('name', 'Mein Projekt')->firstOrFail();
        $this->assertSame(ProjectRole::Admin, $project->roleFor($this->outsider));
        $this->assertNull($project->roleFor($this->viewer));
    }

    public function test_assignee_and_collaborators_must_be_members_or_app_admins(): void
    {
        $task = $this->task();
        $this->actingAs($this->editor);

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('assigneeId', (string) $this->outsider->id)
            ->call('save')
            ->assertHasErrors('assigneeId');

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('collaboratorIds', [(string) $this->outsider->id])
            ->call('save')
            ->assertHasErrors('collaboratorIds.0');

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('assigneeId', (string) $this->manager->id)
            ->set('collaboratorIds', [(string) $this->appAdmin->id])
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_pickers_only_offer_people_of_the_project(): void
    {
        $task = $this->task();
        $this->actingAs($this->editor);

        $options = Livewire::test('pages::tasks.show', ['task' => $task])->instance()->mentionOptions;
        $names = array_column($options['users'], 'name');

        $this->assertContains($this->viewer->name, $names);
        $this->assertContains($this->appAdmin->name, $names);
        $this->assertNotContains($this->outsider->name, $names);
    }

    public function test_emails_only_go_to_people_who_may_see_the_project(): void
    {
        Notification::fake();
        $task = $this->task(['assignee_id' => $this->outsider->id]);
        $task->collaborators()->attach($this->viewer);
        $this->actingAs($this->editor);

        Comment::factory()->for($task)->create([
            'user_id' => $this->editor->id,
            'body' => "Pssst @[x](user:{$this->outsider->id}) @[y](user:{$this->manager->id})",
        ]);

        Notification::assertNotSentTo($this->outsider, TaskCommented::class);
        Notification::assertNotSentTo($this->outsider, UserMentioned::class);
        Notification::assertSentTo($this->manager, UserMentioned::class);
        Notification::assertSentTo($this->viewer, TaskCommented::class);
    }

    public function test_mentions_do_not_reveal_titles_of_tasks_in_hidden_projects(): void
    {
        $hidden = Task::factory()->create(['title' => 'Streng geheim']);
        $task = $this->task();
        Comment::factory()->for($task)->create(['user_id' => $this->editor->id, 'body' => "Siehe @[x](task:{$hidden->id})"]);

        $this->actingAs($this->viewer)->get(route('tasks.show', $task))->assertOk()->assertDontSee('Streng geheim');
        $this->actingAs($this->appAdmin)->get(route('tasks.show', $task))->assertOk()->assertSee('Streng geheim');
    }

    public function test_members_page_adds_changes_and_removes_people(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set('newUserId', (string) $this->outsider->id)
            ->set('newRole', 'viewer')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame(ProjectRole::Viewer, $this->project->roleFor($this->outsider));

        $component->set("roles.{$this->outsider->id}", 'editor');
        $this->assertSame(ProjectRole::Editor, $this->project->roleFor($this->outsider));

        $component->call('remove', $this->outsider->id);
        $this->assertNull($this->project->roleFor($this->outsider));
    }

    public function test_members_page_rejects_unknown_people_and_roles(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set('newUserId', (string) $this->viewer->id)
            ->call('add')
            ->assertHasErrors('newUserId');

        Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set('newUserId', (string) $this->outsider->id)
            ->set('newRole', 'boss')
            ->call('add')
            ->assertHasErrors('newRole');
    }

    public function test_the_last_manager_cannot_be_demoted_or_removed(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set("roles.{$this->manager->id}", 'viewer')
            ->assertSet("roles.{$this->manager->id}", 'admin')
            ->call('remove', $this->manager->id);

        $this->assertSame(ProjectRole::Admin, $this->project->roleFor($this->manager));
        $this->assertDatabaseHas('project_members', ['project_id' => $this->project->id, 'user_id' => $this->manager->id]);

        $this->project->setRole($this->editor, ProjectRole::Admin);
        $component->call('remove', $this->manager->id);
        $this->assertNull($this->project->roleFor($this->manager));
    }

    public function test_commands_create_and_revoke_admins(): void
    {
        $this->artisan('user:create', ['name' => 'Chefin', 'email' => 'chefin@example.com', '--admin' => true, '--password' => 'geheim1234'])
            ->assertSuccessful();
        $this->assertTrue(User::where('email', 'chefin@example.com')->firstOrFail()->is_admin);

        $this->artisan('user:admin', ['email' => $this->viewer->email])->assertSuccessful();
        $this->assertTrue($this->viewer->fresh()->is_admin);

        $this->artisan('user:admin', ['email' => $this->viewer->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($this->viewer->fresh()->is_admin);

        $this->artisan('user:admin', ['email' => 'niemand@example.com'])->assertFailed();
    }
}
