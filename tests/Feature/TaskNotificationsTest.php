<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCommented;
use App\Notifications\TaskStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class TaskNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $assignee;

    private User $collaborator;

    private User $outsider;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->admin()->create();
        $this->assignee = User::factory()->admin()->create();
        $this->collaborator = User::factory()->admin()->create();
        $this->outsider = User::factory()->admin()->create();

        $this->task = Task::factory()->create(['assignee_id' => $this->assignee->id, 'title' => 'Release planen']);
        $this->task->collaborators()->attach($this->collaborator);

        $this->actingAs($this->actor);
        Notification::fake();
    }

    public function test_comment_notifies_assignee_and_collaborators_but_not_others(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('comment', 'Bitte prüfen')
            ->call('addComment');

        Notification::assertSentTo([$this->assignee, $this->collaborator], TaskCommented::class);
        Notification::assertNotSentTo([$this->outsider, $this->actor], TaskCommented::class);
        Notification::assertCount(2);
    }

    public function test_commenter_is_not_notified_about_their_own_comment(): void
    {
        $this->actingAs($this->collaborator);

        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('comment', 'Von mir')
            ->call('addComment');

        Notification::assertSentTo($this->assignee, TaskCommented::class);
        Notification::assertNotSentTo($this->collaborator, TaskCommented::class);
    }

    public function test_status_change_notifies_assignee_and_collaborators(): void
    {
        $inProgress = $this->task->project->statuses[1];

        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('statusId', (string) $inProgress->id)
            ->call('save');

        Notification::assertSentTo(
            [$this->assignee, $this->collaborator],
            TaskStatusChanged::class,
            fn (TaskStatusChanged $notification) => $notification->oldStatus === 'Offen'
                && $notification->newStatus === 'In Arbeit'
                && $notification->changedBy === $this->actor->name,
        );
        Notification::assertNotSentTo([$this->outsider, $this->actor], TaskStatusChanged::class);
    }

    public function test_saving_without_a_status_change_sends_nothing(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('title', 'Neuer Titel')
            ->call('save');

        Notification::assertNothingSent();
    }

    public function test_status_changes_from_list_and_board_notify_too(): void
    {
        $project = $this->task->project;

        Livewire::test('pages::projects.show', ['project' => $project])->call('toggleDone', $this->task->id);
        Notification::assertSentToTimes($this->assignee, TaskStatusChanged::class, 1);

        Livewire::test('pages::projects.board', ['project' => $project])
            ->call('moveTask', $this->task->id, 0, (string) $project->statuses[1]->id);
        Notification::assertSentToTimes($this->assignee, TaskStatusChanged::class, 2);
    }

    public function test_section_headings_never_notify(): void
    {
        $section = Task::factory()->for($this->task->project)->create(['assignee_id' => $this->assignee->id, 'is_section' => true]);

        $section->update(['status_id' => $this->task->project->doneStatus()->id]);

        Notification::assertNothingSent();
    }

    public function test_muted_people_are_skipped(): void
    {
        $this->task->setMutedBy($this->assignee, true);

        Comment::factory()->for($this->task)->create(['user_id' => $this->actor->id]);

        Notification::assertNotSentTo($this->assignee, TaskCommented::class);
        Notification::assertSentTo($this->collaborator, TaskCommented::class);
    }

    public function test_muting_one_task_does_not_mute_another(): void
    {
        $other = Task::factory()->create(['assignee_id' => $this->assignee->id]);
        $this->task->setMutedBy($this->assignee, true);

        Comment::factory()->for($other)->create(['user_id' => $this->actor->id]);

        Notification::assertSentTo($this->assignee, TaskCommented::class);
    }

    public function test_task_without_people_to_notify_is_fine(): void
    {
        $task = Task::factory()->create();

        Comment::factory()->for($task)->create(['user_id' => $this->actor->id]);

        Notification::assertNothingSent();
    }

    public function test_mail_contains_task_details_and_a_signed_unsubscribe_link(): void
    {
        $comment = Comment::factory()->for($this->task)->create([
            'user_id' => $this->actor->id,
            'body' => "Hallo @[Alt]({$this->mentionToken($this->collaborator)})",
        ]);

        $mail = (new TaskCommented($comment))->toMail($this->assignee);
        $html = (string) $mail->render();

        $this->assertSame('Neuer Kommentar: Release planen', $mail->subject);
        $this->assertStringContainsString($this->actor->name, $html);
        $this->assertStringContainsString('@Alt', $html);
        $this->assertStringNotContainsString('user:'.$this->collaborator->id, $html);
        $this->assertStringContainsString(route('tasks.show', $this->task), $html);

        $unsubscribe = TaskCommented::unsubscribeUrl($this->task, $this->assignee);
        $this->assertTrue(URL::hasValidSignature(request()->create($unsubscribe)));
        $this->assertStringContainsString(htmlspecialchars($unsubscribe), $html);
    }

    public function test_status_mail_names_old_and_new_status(): void
    {
        $mail = (new TaskStatusChanged($this->task, 'Offen', 'Erledigt', 'Anna'))->toMail($this->assignee);
        $html = (string) $mail->render();

        $this->assertSame('Status geändert: Release planen', $mail->subject);
        $this->assertStringContainsString('Offen', $html);
        $this->assertStringContainsString('Erledigt', $html);
        $this->assertStringContainsString('Anna', $html);
    }

    public function test_unsubscribe_page_needs_a_valid_signature(): void
    {
        auth()->logout();

        $this->get(route('tasks.notifications', ['task' => $this->task, 'user' => $this->assignee]))->assertForbidden();

        $url = TaskCommented::unsubscribeUrl($this->task, $this->assignee).'x';
        $this->get($url)->assertForbidden();
    }

    public function test_the_unsubscribe_link_expires_after_a_year(): void
    {
        auth()->logout();
        $url = TaskCommented::unsubscribeUrl($this->task, $this->assignee);

        $this->travelTo(now()->addMonths(11));
        $this->get($url)->assertOk();

        $this->travelTo(now()->addMonths(2));
        $this->get($url)->assertForbidden();
    }

    public function test_guests_can_unsubscribe_and_resubscribe_with_the_signed_link(): void
    {
        auth()->logout();
        $url = TaskCommented::unsubscribeUrl($this->task, $this->assignee);

        $this->get($url)->assertOk()->assertSee('Release planen')->assertSee('Für diese Aufgabe abbestellen');
        $this->assertFalse($this->task->isMutedBy($this->assignee));

        Livewire::test('pages::tasks.notifications', ['task' => $this->task, 'user' => $this->assignee])
            ->call('mute')
            ->assertSet('muted', true)
            ->assertSee('Abbestellt');

        $this->assertTrue($this->task->isMutedBy($this->assignee));

        Livewire::test('pages::tasks.notifications', ['task' => $this->task, 'user' => $this->assignee])
            ->assertSet('muted', true)
            ->call('unmute')
            ->assertSet('muted', false);

        $this->assertFalse($this->task->isMutedBy($this->assignee));
    }

    public function test_opening_the_link_alone_does_not_unsubscribe(): void
    {
        auth()->logout();

        $this->get(TaskCommented::unsubscribeUrl($this->task, $this->assignee))->assertOk();

        $this->assertFalse($this->task->isMutedBy($this->assignee));
    }

    public function test_switch_on_the_task_page_mutes_and_unmutes_for_me(): void
    {
        $component = Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->assertSet('notificationsOn', true)
            ->set('notificationsOn', false);

        $this->assertTrue($this->task->isMutedBy($this->actor));
        $this->assertFalse($this->task->isMutedBy($this->assignee));

        $component->set('notificationsOn', true);
        $this->assertFalse($this->task->isMutedBy($this->actor));

        $this->task->setMutedBy($this->actor, true);
        Livewire::test('pages::tasks.show', ['task' => $this->task])->assertSet('notificationsOn', false);
    }

    private function mentionToken(User $user): string
    {
        return "user:{$user->id}";
    }
}
