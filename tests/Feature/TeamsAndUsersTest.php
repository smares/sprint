<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TeamsAndUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $appAdmin;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appAdmin = User::factory()->admin()->create();
        $this->member = User::factory()->create();
        $this->project = Project::factory()->create(['name' => 'Teamprojekt']);
    }

    public function test_a_team_gives_all_its_people_access_with_the_team_role(): void
    {
        $team = Team::factory()->create();
        $team->users()->attach($this->member);

        $this->assertNull($this->project->roleFor($this->member));

        $this->project->setTeamRole($team, ProjectRole::Viewer);

        $this->assertSame(ProjectRole::Viewer, $this->project->roleFor($this->member));
        $this->assertTrue($this->member->can('view', $this->project));
        $this->assertFalse($this->member->can('edit', $this->project));
    }

    public function test_the_highest_role_of_direct_membership_and_all_teams_wins(): void
    {
        $viewers = Team::factory()->create();
        $editors = Team::factory()->create();
        $viewers->users()->attach($this->member);
        $editors->users()->attach($this->member);
        $this->project->setTeamRole($viewers, ProjectRole::Viewer);
        $this->project->setTeamRole($editors, ProjectRole::Editor);

        $this->assertSame(ProjectRole::Editor, $this->project->roleFor($this->member));

        $this->project->setRole($this->member, ProjectRole::Admin);

        $this->assertSame(ProjectRole::Admin, $this->project->roleFor($this->member));
    }

    public function test_people_of_other_teams_get_nothing(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $other->users()->attach($this->member);
        $this->project->setTeamRole($team, ProjectRole::Editor);

        $this->assertNull($this->project->roleFor($this->member));
    }

    public function test_team_projects_show_up_in_the_project_list_and_my_tasks(): void
    {
        $team = Team::factory()->create();
        $team->users()->attach($this->member);
        $this->project->setTeamRole($team, ProjectRole::Viewer);
        Project::factory()->create(['name' => 'Fremdes Projekt']);
        Task::factory()->for($this->project)->create(['title' => 'Aus dem Team', 'assignee_id' => $this->member->id]);

        $this->actingAs($this->member);

        $this->get(route('projects.index'))->assertOk()->assertSee('Teamprojekt')->assertDontSee('Fremdes Projekt');
        $this->get(route('tasks.mine'))->assertOk()->assertSee('Aus dem Team');
        $this->get(route('projects.show', $this->project))->assertOk();
    }

    public function test_team_people_can_be_assigned_and_mentioned(): void
    {
        $team = Team::factory()->create();
        $team->users()->attach($this->member);
        $this->project->setTeamRole($team, ProjectRole::Viewer);
        $task = Task::factory()->for($this->project)->create();

        $this->actingAs($this->appAdmin);
        $component = Livewire::test('pages::tasks.show', ['task' => $task]);

        $this->assertContains($this->member->name, array_column($component->instance()->mentionOptions['users'], 'name'));

        $component->set('assigneeId', (string) $this->member->id)->call('save')->assertHasNoErrors();
    }

    public function test_deleting_a_team_removes_its_access_but_not_its_people(): void
    {
        $team = Team::factory()->create();
        $team->users()->attach($this->member);
        $this->project->setTeamRole($team, ProjectRole::Editor);

        $team->delete();

        $this->assertNull($this->project->roleFor($this->member));
        $this->assertDatabaseHas('users', ['id' => $this->member->id]);
    }

    public function test_admin_pages_are_only_for_application_admins(): void
    {
        $this->actingAs($this->member);
        $this->get(route('admin.users'))->assertForbidden();
        $this->get(route('admin.teams'))->assertForbidden();
        $this->get(route('projects.index'))->assertOk()->assertDontSee('Benutzer');

        $this->actingAs($this->appAdmin);
        $this->get(route('admin.users'))->assertOk();
        $this->get(route('admin.teams'))->assertOk();
        $this->get(route('projects.index'))->assertOk()->assertSee('Benutzer')->assertSee('Teams');
    }

    public function test_admin_pages_need_a_login(): void
    {
        $this->get(route('admin.users'))->assertRedirect(route('login'));
        $this->get(route('admin.teams'))->assertRedirect(route('login'));
    }

    public function test_admin_pages_recheck_the_right_on_every_request(): void
    {
        $component = Livewire::actingAs($this->appAdmin)->test('pages::admin.users');

        $this->appAdmin->update(['is_admin' => false]);

        $component->set('name', 'Jemand')->assertForbidden();
    }

    public function test_an_admin_creates_a_user_and_sees_the_generated_password_once(): void
    {
        $this->actingAs($this->appAdmin);

        $component = Livewire::test('pages::admin.users')
            ->set('name', 'Neue Person')
            ->set('email', 'neu@example.com')
            ->call('create')
            ->assertHasNoErrors();

        $user = User::where('email', 'neu@example.com')->firstOrFail();
        $password = $component->get('shownPassword');

        $this->assertNotEmpty($password);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse($user->is_admin);

        $component->call('dismissPassword')->assertSet('shownPassword', null);
    }

    public function test_an_admin_can_choose_the_password_and_the_admin_flag(): void
    {
        $this->actingAs($this->appAdmin);

        Livewire::test('pages::admin.users')
            ->set('name', 'Chefin')
            ->set('email', 'chefin@example.com')
            ->set('password', 'mein-passwort')
            ->set('makeAdmin', true)
            ->call('create')
            ->assertHasNoErrors();

        $user = User::where('email', 'chefin@example.com')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('mein-passwort', $user->password));
    }

    public function test_user_creation_validates_name_email_and_password(): void
    {
        $this->actingAs($this->appAdmin);

        Livewire::test('pages::admin.users')->call('create')->assertHasErrors(['name', 'email']);

        Livewire::test('pages::admin.users')
            ->set('name', 'X')
            ->set('email', $this->member->email)
            ->call('create')
            ->assertHasErrors(['email' => 'unique']);

        Livewire::test('pages::admin.users')
            ->set('name', 'X')
            ->set('email', 'x@example.com')
            ->set('password', 'kurz')
            ->call('create')
            ->assertHasErrors(['password' => 'min']);
    }

    public function test_admin_flag_can_be_toggled_but_not_for_yourself(): void
    {
        $this->actingAs($this->appAdmin);

        $component = Livewire::test('pages::admin.users')
            ->set("admins.{$this->member->id}", true);
        $this->assertTrue($this->member->fresh()->is_admin);

        $component->set("admins.{$this->member->id}", false);
        $this->assertFalse($this->member->fresh()->is_admin);

        $component->set("admins.{$this->appAdmin->id}", false)->assertSet("admins.{$this->appAdmin->id}", true);
        $this->assertTrue($this->appAdmin->fresh()->is_admin);
    }

    public function test_an_admin_can_reset_a_password(): void
    {
        $this->actingAs($this->appAdmin);
        $old = $this->member->password;

        $component = Livewire::test('pages::admin.users')->call('resetPassword', $this->member->id);
        $password = $component->get('shownPassword');

        $this->assertNotSame($old, $this->member->fresh()->password);
        $this->assertTrue(Hash::check($password, $this->member->fresh()->password));
    }

    public function test_resetting_a_password_signs_the_person_out_everywhere(): void
    {
        $this->actingAs($this->appAdmin);
        $this->member->forceFill(['remember_token' => 'alt'])->save();
        $this->member->createToken('Laptop');

        Livewire::test('pages::admin.users')->call('resetPassword', $this->member->id);

        $this->assertNotSame('alt', $this->member->fresh()->remember_token);
        $this->assertSame(0, $this->member->tokens()->count());
    }

    public function test_an_admin_manages_teams_and_their_people(): void
    {
        $this->actingAs($this->appAdmin);

        $component = Livewire::test('pages::admin.teams')
            ->set('newName', 'Entwicklung')
            ->call('create')
            ->assertHasNoErrors();

        $team = Team::where('name', 'Entwicklung')->firstOrFail();

        $component->set("newMembers.{$team->id}", (string) $this->member->id)->call('addMember', $team->id);
        $this->assertTrue($team->users()->whereKey($this->member->id)->exists());

        $component->set("names.{$team->id}", 'Backend');
        $this->assertSame('Backend', $team->fresh()->name);

        $component->call('removeMember', $team->id, $this->member->id);
        $this->assertFalse($team->users()->whereKey($this->member->id)->exists());

        $component->call('confirmDelete', $team->id)->call('delete');
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_team_names_must_be_unique_and_not_empty(): void
    {
        $this->actingAs($this->appAdmin);
        $team = Team::factory()->create(['name' => 'Design']);
        $other = Team::factory()->create(['name' => 'Vertrieb']);

        Livewire::test('pages::admin.teams')->call('create')->assertHasErrors('newName');
        Livewire::test('pages::admin.teams')->set('newName', 'Design')->call('create')->assertHasErrors(['newName' => 'unique']);

        Livewire::test('pages::admin.teams')
            ->set("names.{$other->id}", 'Design')
            ->assertSet("names.{$other->id}", 'Vertrieb');

        $this->assertSame('Vertrieb', $other->fresh()->name);
        $this->assertSame('Design', $team->fresh()->name);
    }

    public function test_project_managers_add_change_and_remove_teams(): void
    {
        $this->actingAs($this->appAdmin);
        $team = Team::factory()->create();
        $team->users()->attach($this->member);

        $component = Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set('newTeamId', (string) $team->id)
            ->set('newTeamRole', 'viewer')
            ->call('addTeam')
            ->assertHasNoErrors();

        $this->assertSame(ProjectRole::Viewer, $this->project->fresh()->roleFor($this->member));

        $component->set("teamRoles.{$team->id}", 'editor');
        $this->assertSame(ProjectRole::Editor, $this->project->fresh()->roleFor($this->member));

        $component->call('removeTeam', $team->id);
        $this->assertNull($this->project->fresh()->roleFor($this->member));
    }

    public function test_adding_an_unknown_team_or_role_is_rejected(): void
    {
        $this->actingAs($this->appAdmin);
        $team = Team::factory()->create();

        Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set('newTeamId', '999999')
            ->call('addTeam')
            ->assertHasErrors('newTeamId');

        Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set('newTeamId', (string) $team->id)
            ->set('newTeamRole', 'boss')
            ->call('addTeam')
            ->assertHasErrors('newTeamRole');
    }

    public function test_a_team_can_be_the_projects_manager(): void
    {
        $this->actingAs($this->appAdmin);
        $team = Team::factory()->create();
        $team->users()->attach($this->member);
        $this->project->setRole($this->member, ProjectRole::Editor);
        $this->project->setTeamRole($team, ProjectRole::Admin);

        $this->assertTrue($this->member->can('manage', $this->project));
        $this->actingAs($this->member)->get(route('projects.members', $this->project))->assertOk()->assertSee($team->name);
    }

    public function test_the_last_managing_team_or_member_cannot_be_demoted_or_removed(): void
    {
        $this->actingAs($this->appAdmin);
        $team = Team::factory()->create();
        $team->users()->attach($this->member);
        $this->project->setTeamRole($team, ProjectRole::Admin);

        $component = Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set("teamRoles.{$team->id}", 'viewer')
            ->assertSet("teamRoles.{$team->id}", 'admin')
            ->call('removeTeam', $team->id);

        $this->assertSame(ProjectRole::Admin, $this->project->roleFor($this->member));

        $this->project->setRole($this->appAdmin, ProjectRole::Admin);
        $component->call('removeTeam', $team->id);
        $this->assertNull($this->project->roleFor($this->member));
    }

    public function test_people_who_are_not_managers_can_always_be_changed_or_removed(): void
    {
        $this->actingAs($this->appAdmin);
        $team = Team::factory()->create();
        $this->project->setRole($this->member, ProjectRole::Viewer);
        $this->project->setTeamRole($team, ProjectRole::Viewer);

        $component = Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set("roles.{$this->member->id}", 'editor')
            ->set("teamRoles.{$team->id}", 'editor');

        $this->assertSame(ProjectRole::Editor, $this->project->roleFor($this->member));
        $this->assertSame(ProjectRole::Editor->value, $this->project->teams()->firstOrFail()->pivot->role);

        $component->call('removeTeam', $team->id)->call('remove', $this->member->id);

        $this->assertDatabaseMissing('project_members', ['user_id' => $this->member->id]);
        $this->assertDatabaseMissing('project_team', ['team_id' => $team->id]);
    }

    public function test_an_empty_admin_team_does_not_count_as_a_manager(): void
    {
        $this->actingAs($this->appAdmin);
        $emptyTeam = Team::factory()->create();
        $this->project->setTeamRole($emptyTeam, ProjectRole::Admin);
        $this->project->setRole($this->member, ProjectRole::Admin);

        Livewire::test('pages::projects.members', ['project' => $this->project])
            ->set("roles.{$this->member->id}", 'viewer')
            ->assertSet("roles.{$this->member->id}", 'admin');
    }

    public function test_seeder_creates_an_admin_with_access_to_demo_projects(): void
    {
        $this->seed();

        $anna = User::where('email', 'anna@example.com')->firstOrFail();
        $ben = User::where('email', 'ben@example.com')->firstOrFail();
        $project = Project::latest('id')->firstOrFail();

        $this->assertTrue($anna->is_admin);
        $this->assertSame(ProjectRole::Editor, $project->roleFor($ben));
    }
}
