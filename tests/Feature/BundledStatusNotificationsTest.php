<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TasksStatusChanged;
use App\Notifications\TaskStatusChanged;
use App\ProjectRole;
use App\Services\InboxText;
use App\Services\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class BundledStatusNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $anna;

    private User $bert;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['name' => 'Otto']);
        $this->anna = User::factory()->create(['name' => 'Anna']);
        $this->bert = User::factory()->create(['name' => 'Bert']);
        $this->project = Project::factory()->create(['name' => 'Website']);

        foreach ([$this->actor, $this->anna, $this->bert] as $user) {
            $this->project->setRole($user, ProjectRole::Editor);
        }

        $this->actingAs($this->actor);
    }

    private function task(string $title, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes + ['title' => $title, 'assignee_id' => $this->anna->id]);
    }

    private function bulk(array $tasks)
    {
        return Livewire::test('pages::projects.show', ['project' => $this->project])->call('startSelecting')
            ->set('selected', array_map(fn (Task $task) => (string) $task->id, $tasks));
    }

    public function test_completing_several_tasks_sends_one_message_per_person(): void
    {
        $tasks = [$this->task('Eins'), $this->task('Zwei'), $this->task('Drei')];
        Notification::fake();

        $this->bulk($tasks)->call('bulkComplete');

        Notification::assertSentToTimes($this->anna, TasksStatusChanged::class, 1);
        Notification::assertNotSentTo($this->anna, TaskStatusChanged::class);
        Notification::assertNotSentTo($this->actor, TasksStatusChanged::class);
        Notification::assertSentTo($this->anna, TasksStatusChanged::class, function (TasksStatusChanged $notification) {
            return count($notification->changes) === 3
                && $notification->changedBy === 'Otto'
                && $notification->changes[0]['from'] === 'Offen' && $notification->changes[0]['to'] === 'Erledigt';
        });
    }

    public function test_everybody_gets_exactly_the_changes_that_concern_them(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei');
        $c = $this->task('Drei');
        $a->collaborators()->attach($this->bert);
        $b->collaborators()->attach($this->bert);
        $c->collaborators()->attach($this->bert);
        $other = $this->task('Vier', ['assignee_id' => $this->bert->id]);
        Notification::fake();

        $this->bulk([$a, $b, $other])->call('bulkComplete');

        Notification::assertSentTo($this->anna, TasksStatusChanged::class, fn ($notification) => count($notification->changes) === 2);
        Notification::assertSentTo($this->bert, TasksStatusChanged::class, fn ($notification) => count($notification->changes) === 3);
        Notification::assertSentToTimes($this->bert, TasksStatusChanged::class, 1);
    }

    public function test_a_person_with_only_one_change_gets_the_normal_message(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei', ['assignee_id' => $this->bert->id]);
        Notification::fake();

        $this->bulk([$a, $b])->call('bulkComplete');

        Notification::assertSentTo($this->anna, TaskStatusChanged::class, fn ($notification) => $notification->task->is($a) && $notification->changedBy === 'Otto');
        Notification::assertSentTo($this->bert, TaskStatusChanged::class);
        Notification::assertNotSentTo($this->anna, TasksStatusChanged::class);
    }

    public function test_the_change_window_bundles_too_and_other_changes_send_nothing(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei');
        Notification::fake();

        $this->bulk([$a, $b])->set('bulkAssignee', (string) $this->bert->id)->call('applyBulkChanges');
        Notification::assertNothingSent();

        $this->bulk([$a, $b])->set('bulkStatus', (string) $this->project->doneStatus()->id)->call('applyBulkChanges');
        Notification::assertSentToTimes($this->bert, TasksStatusChanged::class, 1);
    }

    public function test_changing_one_task_still_notifies_immediately(): void
    {
        $task = $this->task('Eins');
        Notification::fake();

        $task->update(['status_id' => $this->project->doneStatus()->id]);

        Notification::assertSentTo($this->anna, TaskStatusChanged::class);
        Notification::assertNotSentTo($this->anna, TasksStatusChanged::class);
    }

    public function test_a_failure_during_bundling_does_not_swallow_later_notifications(): void
    {
        $task = $this->task('Eins');
        Notification::fake();

        try {
            Task::bundlingStatusNotifications(function () {
                throw new \RuntimeException('kaputt');
            });
        } catch (\RuntimeException) {
        }

        $task->update(['status_id' => $this->project->doneStatus()->id]);

        Notification::assertSentTo($this->anna, TaskStatusChanged::class);
    }

    public function test_the_mail_lists_the_tasks_in_both_languages_and_cuts_long_lists(): void
    {
        $changes = array_map(fn ($n) => ['id' => $n, 'title' => "Aufgabe {$n}", 'project' => 'Website', 'from' => 'Offen', 'to' => 'Erledigt'], range(1, TasksStatusChanged::LISTED + 5));
        $notification = new TasksStatusChanged($changes, 'Otto');

        Locale::apply('de');
        $mail = $notification->toMail($this->anna);
        $text = preg_replace('/\s+/', ' ', strip_tags((string) $mail->render()));
        $this->assertSame('Status geändert bei 30 Aufgaben', $mail->subject);
        $this->assertStringContainsString('Otto hat den Status von 30 Aufgaben geändert', $text);
        $this->assertStringContainsString('Aufgabe 1 · Website · Offen → Erledigt', $text);
        $this->assertStringContainsString('Aufgabe 25 ', $text);
        $this->assertStringNotContainsString('Aufgabe 26 ', $text);
        $this->assertStringContainsString('… und 5 weitere', $text);

        Locale::apply('en');
        $mail = $notification->toMail($this->anna);
        $this->assertSame('Status changed on 30 tasks', $mail->subject);
        $this->assertStringContainsString('… and 5 more', preg_replace('/\s+/', ' ', strip_tags((string) $mail->render())));
    }

    public function test_the_inbox_shows_one_entry_in_the_language_of_the_reader(): void
    {
        $this->anna->notifications()->create(['id' => 'x', 'type' => TasksStatusChanged::class, 'data' => (new TasksStatusChanged([['id' => 7, 'title' => 'A', 'project' => 'P', 'from' => 'a', 'to' => 'b'], ['id' => 8, 'title' => 'B', 'project' => 'P', 'from' => 'a', 'to' => 'b']], 'Otto'))->toArray($this->anna)]);
        $entry = $this->anna->notifications()->firstOrFail();

        Locale::apply('de');
        $this->assertSame('Otto hat den Status von 2 Aufgaben geändert', InboxText::sentence($entry));
        Locale::apply('en');
        $this->assertSame('Otto changed the status of 2 tasks', InboxText::sentence($entry));
        $this->assertSame(7, $entry->data['task_id']);
    }
}
