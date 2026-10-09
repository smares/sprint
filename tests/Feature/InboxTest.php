<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\InboxTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $other;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Mia']);
        $this->other = User::factory()->admin()->create(['name' => 'Otto']);
        $this->project = Project::factory()->create(['name' => 'Website']);
        $this->project->setRole($this->me, ProjectRole::Editor);
        $this->task = Task::factory()->for($this->project)->create(['title' => 'Angebot', 'assignee_id' => $this->me->id]);
    }

    public function test_comments_status_changes_and_mentions_land_in_the_inbox(): void
    {
        $this->actingAs($this->other);

        Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->other->id, 'body' => 'Bitte prüfen']);
        $this->task->update(['status_id' => $this->project->doneStatus()->id]);
        Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->other->id, 'body' => 'Hallo @['.$this->me->name.'](user:'.$this->me->id.')']);

        $summaries = $this->me->notifications()->get()->map(fn (DatabaseNotification $notification) => InboxTextService::sentence($notification))->all();

        $this->assertContains('Otto hat kommentiert', $summaries);
        $this->assertContains('Otto hat den Status von „Offen“ auf „Erledigt“ geändert', $summaries);
        $this->assertContains('Otto hat dich in einem Kommentar erwähnt', $summaries);
        $this->assertTrue($this->me->notifications()->where('data->task_id', $this->task->id)->exists());
    }

    public function test_the_inbox_lists_own_entries_with_task_and_project(): void
    {
        $this->actingAs($this->other);
        Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->other->id, 'body' => 'Streng geheim']);

        $this->actingAs($this->me);
        $this->get(route('inbox'))->assertOk()->assertSee('Angebot')->assertSee('Otto hat kommentiert')->assertSee('Website')->assertDontSee('Streng geheim');
    }

    public function test_the_comment_text_is_not_stored(): void
    {
        $this->actingAs($this->other);
        Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->other->id, 'body' => 'Streng geheim']);

        $this->assertStringNotContainsString('Streng geheim', json_encode($this->me->notifications()->first()->data));
    }

    public function test_opening_marks_as_read_and_goes_to_the_task(): void
    {
        $this->actingAs($this->other);
        Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->other->id]);
        $notification = $this->me->notifications()->firstOrFail();

        $this->actingAs($this->me);
        Livewire::test('pages::inbox')->call('open', $notification->id)->assertRedirect(route('tasks.show', $this->task));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_entries_can_be_toggled_marked_all_read_and_removed(): void
    {
        $this->actingAs($this->other);
        Comment::factory()->count(2)->create(['task_id' => $this->task->id, 'user_id' => $this->other->id]);
        [$first, $second] = $this->me->notifications()->get()->all();

        $this->actingAs($this->me);
        $page = Livewire::test('pages::inbox');

        $page->call('toggleRead', $first->id);
        $this->assertNotNull($first->fresh()->read_at);
        $page->call('toggleRead', $first->id);
        $this->assertNull($first->fresh()->read_at);

        $page->call('markAllRead');
        $this->assertSame(0, $this->me->unreadNotifications()->count());

        $page->call('remove', $second->id);
        $this->assertSame(1, $this->me->notifications()->count());
    }

    public function test_other_peoples_entries_cannot_be_touched(): void
    {
        $this->actingAs($this->me);
        $this->task->update(['assignee_id' => $this->other->id]);
        $this->actingAs($this->other);
        $this->task->collaborators()->attach($this->me);
        Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->me->id]);
        $foreign = $this->other->notifications()->firstOrFail();

        $this->actingAs($this->me);
        foreach (['open', 'toggleRead', 'remove'] as $action) {
            Livewire::test('pages::inbox')->call($action, $foreign->id)->assertNotFound();
        }

        $this->assertModelExists($foreign);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_entries_for_deleted_or_no_longer_visible_tasks_have_no_link(): void
    {
        $this->actingAs($this->other);
        Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->other->id]);
        $notification = $this->me->notifications()->firstOrFail();

        $this->project->members()->detach($this->me);
        $this->actingAs($this->me);

        Livewire::test('pages::inbox')
            ->assertSee('Aufgabe nicht mehr verfügbar')
            ->assertDontSee('Angebot')
            ->call('open', $notification->id)
            ->assertNoRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_the_bell_counts_unread_entries(): void
    {
        $this->actingAs($this->other);
        Comment::factory()->count(3)->create(['task_id' => $this->task->id, 'user_id' => $this->other->id]);

        $this->actingAs($this->me);
        Livewire::test('notification-bell')->assertSee('3')->assertSee('3 ungelesen');

        $this->me->unreadNotifications()->first()->markAsRead();
        Livewire::test('notification-bell')->assertSee('2 ungelesen');

        $this->me->unreadNotifications()->update(['read_at' => now()]);
        Livewire::test('notification-bell')->assertDontSee('ungelesen');
    }

    public function test_the_inbox_tells_the_bell_whenever_entries_are_read_unread_or_removed(): void
    {
        $this->actingAs($this->other);
        Comment::factory()->count(3)->create(['task_id' => $this->task->id, 'user_id' => $this->other->id]);
        [$first, $second, $third] = $this->me->notifications()->get()->all();

        $this->actingAs($this->me);
        $page = Livewire::test('pages::inbox');

        $page->call('toggleRead', $first->id)->assertDispatched('inbox-changed');
        $page->call('open', $second->id)->assertDispatched('inbox-changed');
        $page->call('remove', $third->id)->assertDispatched('inbox-changed');
        $page->call('markAllRead')->assertDispatched('inbox-changed');
    }

    public function test_the_bell_recounts_when_told_by_the_inbox(): void
    {
        $this->actingAs($this->other);
        Comment::factory()->count(2)->create(['task_id' => $this->task->id, 'user_id' => $this->other->id]);

        $this->actingAs($this->me);
        $bell = Livewire::test('notification-bell')->assertSee('2 ungelesen');

        $this->me->unreadNotifications()->update(['read_at' => now()]);
        $bell->dispatch('inbox-changed')->assertDontSee('ungelesen');
    }

    public function test_the_header_links_to_the_inbox(): void
    {
        $this->actingAs($this->me)->get(route('projects.index'))->assertOk()->assertSee(route('inbox'), false);
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('inbox'))->assertRedirect(route('login'));
    }

    public function test_muted_tasks_stay_out_of_the_inbox_too(): void
    {
        $this->task->setMutedBy($this->me, true);
        $this->actingAs($this->other);

        Comment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->other->id]);

        $this->assertSame(0, $this->me->notifications()->count());
    }
}
