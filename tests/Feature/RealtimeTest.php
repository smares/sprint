<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Events\InboxUpdated;
use App\Events\TaskChanged;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskStatusChanged;
use App\Services\RealtimeService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
        $this->project = Project::factory()->create();
    }

    private function switchOnReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'ws.example.com',
            'broadcasting.connections.reverb.options.port' => 443,
            'broadcasting.connections.reverb.options.scheme' => 'https',
        ]);

        // Nothing leaves the test process; the channels are registered again for the driver that is now the default.
        Event::fake([TaskChanged::class, InboxUpdated::class]);
        app(BroadcastManager::class)->purge();
        require base_path('routes/channels.php');
    }

    /**
     * @return array<string, string>
     */
    private function listenersOf(string $component, array $parameters): array
    {
        $instance = Livewire::test($component, $parameters)->instance();

        return (new ReflectionMethod($instance, 'getListeners'))->invoke($instance);
    }

    public function test_nothing_is_broadcast_while_live_updates_are_off(): void
    {
        Event::fake([TaskChanged::class]);

        $task = Task::factory()->for($this->project)->create();
        $task->update(['title' => 'Neu']);

        Event::assertNotDispatched(TaskChanged::class);
        $this->assertNull(app(RealtimeService::class)->clientConfig());
    }

    public function test_changes_to_tasks_comments_and_attachments_are_announced_to_the_project(): void
    {
        $this->switchOnReverb();
        Event::fake([TaskChanged::class]);

        $task = Task::factory()->for($this->project)->create();
        $task->update(['title' => 'Neu']);
        Comment::factory()->create(['task_id' => $task->id, 'user_id' => $this->admin->id]);
        Attachment::factory()->for($task)->create();
        $task->delete();

        Event::assertDispatched(TaskChanged::class, fn (TaskChanged $event) => $event->projectId === $this->project->id && $event->taskId === $task->id && $event->kind === 'task');
        Event::assertDispatched(TaskChanged::class, fn (TaskChanged $event) => $event->kind === 'comment' && $event->taskId === $task->id);
        Event::assertDispatched(TaskChanged::class, fn (TaskChanged $event) => $event->kind === 'attachment' && $event->taskId === $task->id);
    }

    public function test_only_ids_go_over_the_socket_and_only_to_the_project_channel(): void
    {
        $event = new TaskChanged($this->project->id, 7, 'task');

        $this->assertSame(['project_id' => $this->project->id, 'task_id' => 7, 'kind' => 'task'], $event->broadcastWith());
        $this->assertSame("private-project.{$this->project->id}", $event->broadcastOn()[0]->name);
        $this->assertSame('TaskChanged', $event->broadcastAs());
    }

    public function test_a_bulk_change_sends_one_message_per_project_not_one_per_task(): void
    {
        $this->switchOnReverb();
        $tasks = Task::factory()->for($this->project)->count(4)->create();
        Event::fake([TaskChanged::class]);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('startSelecting')
            ->set('selected', $tasks->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('bulkComplete');

        Event::assertDispatchedTimes(TaskChanged::class, 1);
        Event::assertDispatched(TaskChanged::class, fn (TaskChanged $event) => $event->taskId === null && $event->kind === 'bulk');
    }

    public function test_a_bulk_delete_sends_one_message(): void
    {
        $this->switchOnReverb();
        $tasks = Task::factory()->for($this->project)->count(3)->create();
        Event::fake([TaskChanged::class]);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('startSelecting')
            ->set('selected', $tasks->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('bulkDelete');

        Event::assertDispatchedTimes(TaskChanged::class, 1);
    }

    public function test_a_new_inbox_entry_is_announced_to_its_owner(): void
    {
        $this->switchOnReverb();
        $task = Task::factory()->for($this->project)->create();
        $person = User::factory()->create();

        $person->notifyNow(new TaskStatusChanged($task, 'Offen', 'Erledigt', 'Anna'));

        Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $event) => $event->userId === $person->id);
        $this->assertSame("private-user.{$person->id}", (new InboxUpdated($person->id))->broadcastOn()[0]->name);
    }

    public function test_the_browser_gets_the_connection_settings_but_never_the_secret(): void
    {
        $this->switchOnReverb();

        $config = app(RealtimeService::class)->clientConfig();

        $this->assertSame(['key' => 'test-key', 'host' => 'ws.example.com', 'port' => 443, 'scheme' => 'https'], $config);

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('window.sprintRealtime', false)
            ->assertSee('test-key', false)
            ->assertDontSee('test-secret', false);
    }

    public function test_the_pages_do_not_load_the_connection_while_live_updates_are_off(): void
    {
        $this->get(route('projects.index'))->assertOk()->assertDontSee('window.sprintRealtime', false);
    }

    public function test_the_project_pages_listen_to_their_channel_and_show_who_else_is_there_only_when_on(): void
    {
        foreach (['pages::projects.show', 'pages::projects.board', 'pages::projects.calendar', 'pages::projects.timeline'] as $page) {
            $this->assertSame([], $this->listenersOf($page, ['project' => $this->project]), $page);
        }

        $this->switchOnReverb();

        foreach (['pages::projects.show', 'pages::projects.board', 'pages::projects.calendar', 'pages::projects.timeline'] as $page) {
            $listeners = $this->listenersOf($page, ['project' => $this->project]);

            $this->assertSame('projectChangedElsewhere', $listeners["echo-private:project.{$this->project->id},.TaskChanged"], $page);
            $this->assertSame('presenceHere', $listeners["echo-presence:project.{$this->project->id}.presence,here"], $page);
        }
    }

    public function test_the_task_page_listens_to_the_project_and_its_own_presence_channel(): void
    {
        $task = Task::factory()->for($this->project)->create();
        $this->switchOnReverb();

        $listeners = $this->listenersOf('pages::tasks.show', ['task' => $task]);

        $this->assertArrayHasKey("echo-private:project.{$this->project->id},.TaskChanged", $listeners);
        $this->assertArrayHasKey("echo-presence:task.{$task->id}.presence,joining", $listeners);
    }

    public function test_presence_lists_the_others_but_not_oneself(): void
    {
        $this->switchOnReverb();
        $task = Task::factory()->for($this->project)->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->call('presenceHere', [
                ['id' => $this->admin->id, 'name' => 'Ich', 'initials' => 'IC'],
                ['id' => 41, 'name' => 'Anna Beispiel', 'initials' => 'AB'],
            ])
            ->assertSet('presentUsers', [41 => ['id' => 41, 'name' => 'Anna Beispiel', 'initials' => 'AB']])
            ->assertSee('Anna Beispiel sieht sich diese Aufgabe auch gerade an')
            ->call('presenceJoining', ['id' => 42, 'name' => 'Ben Beispiel', 'initials' => 'BB'])
            ->assertSee('2 Personen sehen sich diese Aufgabe auch gerade an')
            ->call('presenceLeaving', ['id' => 41])
            ->call('presenceLeaving', ['id' => 42])
            ->assertSet('presentUsers', [])
            ->assertDontSee('auch gerade an');
    }

    public function test_a_change_by_somebody_else_to_the_open_task_warns_and_keeps_what_was_typed(): void
    {
        $this->switchOnReverb();
        $task = Task::factory()->for($this->project)->create(['title' => 'Alt']);

        $page = Livewire::test('pages::tasks.show', ['task' => $task])->set('title', 'Meine Eingabe');

        $page->call('projectChangedElsewhere', ['project_id' => $this->project->id, 'task_id' => $task->id + 1000, 'kind' => 'task'])
            ->assertSet('changedElsewhere', false);

        $task->update(['title' => 'Von anderen']);

        $page->call('projectChangedElsewhere', ['project_id' => $this->project->id, 'task_id' => $task->id, 'kind' => 'task'])
            ->assertSet('changedElsewhere', true)
            ->assertSet('title', 'Meine Eingabe')
            ->assertSee('Von jemand anderem geändert')
            ->call('reloadFromServer')
            ->assertSet('changedElsewhere', false)
            ->assertSet('title', 'Von anderen');
    }

    public function test_a_new_comment_by_somebody_else_updates_the_page_without_a_warning(): void
    {
        $this->switchOnReverb();
        $task = Task::factory()->for($this->project)->create();
        $page = Livewire::test('pages::tasks.show', ['task' => $task]);

        Comment::factory()->create(['task_id' => $task->id, 'user_id' => User::factory()->create()->id, 'body' => 'Frisch eingetroffen']);

        $page->call('projectChangedElsewhere', ['project_id' => $this->project->id, 'task_id' => $task->id, 'kind' => 'comment'])
            ->assertSet('changedElsewhere', false)
            ->assertSee('Frisch eingetroffen');
    }

    public function test_the_bell_and_the_inbox_listen_for_new_entries_only_when_on(): void
    {
        $this->assertSame([], $this->listenersOf('notification-bell', []));
        $this->assertSame([], $this->listenersOf('pages::inbox', []));

        $this->switchOnReverb();

        $this->assertSame(['echo-private:user.'.$this->admin->id.',.InboxUpdated' => 'inboxUpdated'], $this->listenersOf('notification-bell', []));
        $this->assertSame(['echo-private:user.'.$this->admin->id.',.InboxUpdated' => '$refresh'], $this->listenersOf('pages::inbox', []));
    }

    public function test_the_bell_shows_a_new_count_when_an_entry_arrives(): void
    {
        $this->switchOnReverb();
        $person = User::factory()->create();
        $this->actingAs($person);
        $task = Task::factory()->for($this->project)->create();
        $bell = Livewire::test('notification-bell');

        $bell->assertSet('unread', 0);

        Notification::send($person, new TaskStatusChanged($task, 'Offen', 'Erledigt', 'Anna'));

        $bell->call('inboxUpdated')->assertSee('1');
    }

    public function test_only_people_with_access_may_listen_to_a_project(): void
    {
        $this->switchOnReverb();
        $member = User::factory()->create();
        $this->project->setRole($member, ProjectRole::Viewer);
        $outsider = User::factory()->create();

        $authorize = fn (User $user, string $channel) => $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

        $authorize($member, "private-project.{$this->project->id}")->assertOk();
        $authorize($outsider, "private-project.{$this->project->id}")->assertForbidden();
        $authorize($this->admin, "private-project.{$this->project->id}")->assertOk();
    }

    public function test_presence_channels_tell_who_is_there_but_only_to_people_with_access(): void
    {
        $this->switchOnReverb();
        $member = User::factory()->create(['name' => 'Berta Muster']);
        $this->project->setRole($member, ProjectRole::Editor);
        $task = Task::factory()->for($this->project)->create();
        $outsider = User::factory()->create();

        $authorize = fn (User $user, string $channel) => $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

        $response = $authorize($member, "presence-project.{$this->project->id}.presence")->assertOk();
        $this->assertSame('Berta Muster', json_decode($response->json('channel_data'), true)['user_info']['name']);

        $authorize($member, "presence-task.{$task->id}.presence")->assertOk();
        $authorize($outsider, "presence-project.{$this->project->id}.presence")->assertForbidden();
        $authorize($outsider, "presence-task.{$task->id}.presence")->assertForbidden();
    }

    public function test_everybody_may_listen_to_their_own_inbox_only(): void
    {
        $this->switchOnReverb();
        $other = User::factory()->create();

        $authorize = fn (string $channel) => $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

        $authorize("private-user.{$this->admin->id}")->assertOk();
        $authorize("private-user.{$other->id}")->assertForbidden();
    }

    public function test_guests_cannot_authorize_a_channel(): void
    {
        $this->switchOnReverb();
        auth()->logout();

        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-project.{$this->project->id}"])->assertForbidden();
    }
}
