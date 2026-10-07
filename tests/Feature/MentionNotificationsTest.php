<?php

namespace Tests\Feature;

use App\Markdown;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCommented;
use App\Notifications\UserMentioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class MentionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $assignee;

    private User $outsider;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->admin()->create(['name' => 'Anna Autorin']);
        $this->assignee = User::factory()->admin()->create();
        $this->outsider = User::factory()->admin()->create(['name' => 'Olaf Außenstehend']);

        $this->task = Task::factory()->create(['assignee_id' => $this->assignee->id, 'title' => 'Release planen']);

        $this->actingAs($this->actor);
        Notification::fake();
    }

    private function mention(User $user): string
    {
        return "@[{$user->name}](user:{$user->id})";
    }

    public function test_mentioned_person_who_is_not_involved_gets_an_email(): void
    {
        Comment::factory()->for($this->task)->create([
            'user_id' => $this->actor->id,
            'body' => "Kannst du das prüfen? {$this->mention($this->outsider)}",
        ]);

        Notification::assertSentTo($this->outsider, UserMentioned::class, fn (UserMentioned $notification) => $notification->where === 'comment'
            && $notification->mentionedBy === 'Anna Autorin');
        Notification::assertSentTo($this->assignee, TaskCommented::class);
        Notification::assertNotSentTo($this->outsider, TaskCommented::class);
    }

    public function test_a_mentioned_assignee_gets_the_mention_mail_instead_of_two_mails(): void
    {
        Comment::factory()->for($this->task)->create([
            'user_id' => $this->actor->id,
            'body' => "Bitte {$this->mention($this->assignee)}",
        ]);

        Notification::assertSentToTimes($this->assignee, UserMentioned::class, 1);
        Notification::assertNotSentTo($this->assignee, TaskCommented::class);
    }

    public function test_mentioning_yourself_sends_nothing(): void
    {
        Comment::factory()->for($this->task)->create([
            'user_id' => $this->actor->id,
            'body' => "Notiz an mich {$this->mention($this->actor)}",
        ]);

        Notification::assertNotSentTo($this->actor, UserMentioned::class);
        Notification::assertNotSentTo($this->actor, TaskCommented::class);
    }

    public function test_muted_task_sends_no_mention_mail(): void
    {
        $this->task->setMutedBy($this->outsider, true);

        Comment::factory()->for($this->task)->create([
            'user_id' => $this->actor->id,
            'body' => $this->mention($this->outsider),
        ]);

        Notification::assertNotSentTo($this->outsider, UserMentioned::class);
    }

    public function test_the_same_person_mentioned_twice_gets_one_mail(): void
    {
        Comment::factory()->for($this->task)->create([
            'user_id' => $this->actor->id,
            'body' => "{$this->mention($this->outsider)} und nochmal {$this->mention($this->outsider)}",
        ]);

        Notification::assertSentToTimes($this->outsider, UserMentioned::class, 1);
    }

    public function test_mentions_of_deleted_people_and_of_tasks_are_ignored(): void
    {
        Comment::factory()->for($this->task)->create([
            'user_id' => $this->actor->id,
            'body' => '@[Weg](user:99999) @[Aufgabe](task:'.$this->task->id.')',
        ]);

        Notification::assertNotSentTo($this->outsider, UserMentioned::class);
        Notification::assertSentTo($this->assignee, TaskCommented::class);
    }

    public function test_new_mentions_in_the_description_notify_only_the_added_people(): void
    {
        $this->task->update(['description' => "Hallo {$this->mention($this->outsider)}"]);
        Notification::assertSentToTimes($this->outsider, UserMentioned::class, 1);

        $this->task->update(['description' => "Hallo {$this->mention($this->outsider)}, bitte beeilen"]);
        Notification::assertSentToTimes($this->outsider, UserMentioned::class, 1);

        $this->task->update(['description' => "{$this->mention($this->outsider)} {$this->mention($this->assignee)}"]);
        Notification::assertSentToTimes($this->outsider, UserMentioned::class, 1);
        Notification::assertSentToTimes($this->assignee, UserMentioned::class, 1);
    }

    public function test_saving_the_task_page_notifies_people_mentioned_in_the_description(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('description', "Siehe {$this->mention($this->outsider)}")
            ->call('save');

        Notification::assertSentTo($this->outsider, UserMentioned::class, fn (UserMentioned $notification) => $notification->where === 'description');
    }

    public function test_changing_other_fields_does_not_resend_description_mentions(): void
    {
        $this->task->update(['description' => $this->mention($this->outsider)]);
        Notification::assertSentToTimes($this->outsider, UserMentioned::class, 1);

        $this->task->update(['title' => 'Neuer Titel']);

        Notification::assertSentToTimes($this->outsider, UserMentioned::class, 1);
    }

    public function test_commenting_through_the_task_page_sends_the_mention_mail(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('comment', "Ping {$this->mention($this->outsider)}")
            ->call('addComment');

        Notification::assertSentTo($this->outsider, UserMentioned::class);
    }

    public function test_mail_names_the_author_the_place_and_hides_the_tokens(): void
    {
        $text = "Schau mal {$this->mention($this->outsider)}";

        $comment = (new UserMentioned($this->task, 'comment', $text, 'Anna Autorin'))->toMail($this->outsider);
        $description = (new UserMentioned($this->task, 'description', $text, 'Anna Autorin'))->toMail($this->outsider);
        $html = (string) $comment->render();

        $this->assertSame('Du wurdest erwähnt: Release planen', $comment->subject);
        $this->assertStringContainsString('in einem Kommentar', $html);
        $this->assertStringContainsString('in der Beschreibung', (string) $description->render());
        $this->assertStringContainsString('Anna Autorin', $html);
        $this->assertStringContainsString('@Olaf Außenstehend', $html);
        $this->assertStringNotContainsString('user:'.$this->outsider->id, $html);
        $this->assertStringContainsString(route('tasks.show', $this->task), $html);
        $this->assertStringContainsString('abbestellen', $html);
    }

    public function test_helper_lists_unique_user_ids_only(): void
    {
        $text = "{$this->mention($this->actor)} {$this->mention($this->actor)} {$this->mention($this->outsider)} @[Aufgabe](task:5)";

        $this->assertSame([$this->actor->id, $this->outsider->id], Markdown::mentionedUserIds($text));
        $this->assertSame([], Markdown::mentionedUserIds(null));
        $this->assertSame('@Anna Autorin und @Olaf Außenstehend', Markdown::plainText("{$this->mention($this->actor)} und {$this->mention($this->outsider)}"));
    }
}
