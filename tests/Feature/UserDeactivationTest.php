<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCommented;
use App\Notifications\UserMentioned;
use App\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class UserDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Chefin']);
        $this->actingAs($this->admin);
        $this->project = Project::factory()->create();
    }

    private function member(string $name = 'Mitarbeiter'): User
    {
        $user = User::factory()->create(['name' => $name, 'password' => 'geheim1234']);
        $this->project->setRole($user, ProjectRole::Editor);

        return $user;
    }

    public function test_an_admin_can_deactivate_and_reactivate_a_person(): void
    {
        $user = $this->member();

        $page = Livewire::test('pages::admin.users')->call('deactivate', $user->id);
        $this->assertFalse($user->fresh()->isActive());
        $page->assertSee('Deaktiviert');

        $page->call('reactivate', $user->id);
        $this->assertTrue($user->fresh()->isActive());
    }

    public function test_you_cannot_deactivate_yourself_or_the_last_active_admin(): void
    {
        Livewire::test('pages::admin.users')->call('deactivate', $this->admin->id);
        $this->assertTrue($this->admin->fresh()->isActive());

        $second = User::factory()->admin()->create();
        $this->actingAs($second);
        Livewire::test('pages::admin.users')->call('deactivate', $this->admin->id);
        $this->assertFalse($this->admin->fresh()->isActive());

        $third = User::factory()->admin()->deactivated()->create();
        $this->assertFalse($third->isLastActiveAdmin());
        $this->assertTrue($second->fresh()->isLastActiveAdmin());

        Livewire::test('pages::admin.users')->call('deactivate', $third->id);
        $this->assertTrue($second->fresh()->isActive());
    }

    public function test_only_admins_can_manage_activation(): void
    {
        $user = $this->member();
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.users'))->assertForbidden();
        $this->assertTrue($user->fresh()->isActive());
    }

    public function test_a_deactivated_person_cannot_sign_in_and_is_told_so_only_with_the_right_password(): void
    {
        $user = $this->member();
        $user->deactivate();
        auth()->logout();

        Livewire::test('pages::login')->set('email', $user->email)->set('password', 'geheim1234')->call('login')
            ->assertHasErrors('email')->assertSee('deaktiviert');
        $this->assertGuest();

        Livewire::test('pages::login')->set('email', $user->email)->set('password', 'falsch')->call('login')
            ->assertHasErrors('email')->assertDontSee('deaktiviert')->assertSee('E-Mail oder Passwort ist falsch.');
        $this->assertGuest();

        $user->reactivate();
        Livewire::test('pages::login')->set('email', $user->email)->set('password', 'geheim1234')->call('login')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_deactivating_ends_running_sessions_and_signs_the_person_out_on_the_next_request(): void
    {
        $user = $this->member();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'xyz', 'user_id' => $this->admin->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->get(route('projects.index'))->assertOk();
        config(['session.driver' => 'database']);
        $user->deactivate();

        $this->assertDatabaseMissing('sessions', ['id' => 'abc']);
        $this->assertDatabaseHas('sessions', ['id' => 'xyz']);

        $this->actingAs($user->fresh())->get(route('projects.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivated_people_are_not_offered_for_new_assignments_mentions_or_membership(): void
    {
        $active = $this->member('Aktiv');
        $gone = $this->member('Weg');
        $gone->deactivate();
        $task = Task::factory()->for($this->project)->create();

        $page = Livewire::test('pages::tasks.show', ['task' => $task]);
        $this->assertSame(['Aktiv', 'Chefin'], $page->instance()->users->pluck('name')->sort()->values()->all());
        $this->assertSame(['Aktiv', 'Chefin'], collect($page->instance()->mentionOptions['users'])->pluck('name')->sort()->values()->all());

        $candidates = Livewire::test('pages::projects.members', ['project' => $this->project])->instance()->candidates;
        $this->assertFalse($candidates->contains('id', $gone->id));
        $this->assertFalse($this->project->eligibleUsers()->whereKey($gone->id)->exists());
        $this->assertTrue($this->project->eligibleUsers()->whereKey($active->id)->exists());

        Livewire::test('pages::tasks.show', ['task' => $task])->set('assigneeId', (string) $gone->id)->call('save')->assertHasErrors('assigneeId');
    }

    public function test_existing_assignments_stay_valid_and_are_labelled(): void
    {
        $gone = $this->member('Weg');
        $helper = $this->member('Hilfe');
        $task = Task::factory()->for($this->project)->create(['assignee_id' => $gone->id]);
        $task->collaborators()->attach($helper);
        $gone->deactivate();
        $helper->deactivate();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->assertSee('Weg (deaktiviert)')->assertSee('Hilfe (deaktiviert)')
            ->set('title', 'Neuer Titel')->call('save')->assertHasNoErrors();

        $this->assertSame($gone->id, $task->fresh()->assignee_id);
        $this->assertSame([$helper->id], $task->collaborators()->pluck('users.id')->all());
    }

    public function test_history_and_names_stay_visible(): void
    {
        $gone = $this->member('Weg');
        $task = Task::factory()->for($this->project)->create();
        Comment::factory()->create(['task_id' => $task->id, 'user_id' => $gone->id, 'body' => 'Alter Kommentar']);
        $gone->deactivate();

        Livewire::test('pages::tasks.show', ['task' => $task])->assertSee('Alter Kommentar')->assertSee('Weg');
        $this->assertSame(1, $task->comments()->count());
    }

    public function test_deactivated_people_get_no_mails_or_inbox_entries(): void
    {
        Notification::fake();
        $gone = $this->member('Weg');
        $active = $this->member('Aktiv');
        $task = Task::factory()->for($this->project)->create(['assignee_id' => $gone->id]);
        $task->collaborators()->attach($active);
        $gone->deactivate();

        Comment::factory()->create(['task_id' => $task->id, 'user_id' => $this->admin->id, 'body' => 'Hallo @['.$gone->name.'](user:'.$gone->id.')']);

        Notification::assertNotSentTo($gone, TaskCommented::class);
        Notification::assertNotSentTo($gone, UserMentioned::class);
        Notification::assertSentTo($active, TaskCommented::class);
    }

    public function test_the_console_command_deactivates_and_reactivates(): void
    {
        $user = $this->member();

        $this->artisan('user:deactivate', ['email' => $user->email])->assertSuccessful();
        $this->assertFalse($user->fresh()->isActive());

        $this->artisan('user:deactivate', ['email' => $user->email, '--reactivate' => true])->assertSuccessful();
        $this->assertTrue($user->fresh()->isActive());

        $this->artisan('user:deactivate', ['email' => $this->admin->email])->assertFailed();
        $this->artisan('user:deactivate', ['email' => 'niemand@example.com'])->assertFailed();
    }

    public function test_deactivated_admins_lose_nothing_but_the_login(): void
    {
        $other = User::factory()->admin()->create();
        $other->deactivate();

        $this->assertTrue($other->fresh()->is_admin);
        $this->assertFalse($this->project->eligibleUsers()->whereKey($other->id)->exists());
    }
}
