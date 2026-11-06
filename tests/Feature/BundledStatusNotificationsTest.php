<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCompletionChanged;
use App\Notifications\TasksCompletionChanged;
use App\Notifications\TasksStatusChanged;
use App\Notifications\TaskStatusChanged;
use App\Services\InboxTextService;
use App\Services\LocaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
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
        return Livewire::test('pages::projects.show', ['project' => $this->project])->set('doneFilter', 'all')->set('selecting', true)
            ->set('selected', array_map(fn (Task $task) => (string) $task->id, $tasks));
    }

    public function test_completing_several_tasks_sends_one_message_per_person(): void
    {
        $tasks = [$this->task('Eins'), $this->task('Zwei'), $this->task('Drei')];
        Notification::fake();

        $this->bulk($tasks)->call('bulkComplete');

        Notification::assertSentToTimes($this->anna, TasksCompletionChanged::class, 1);
        Notification::assertNotSentTo($this->anna, TaskCompletionChanged::class);
        Notification::assertNotSentTo($this->anna, TasksStatusChanged::class);
        Notification::assertNotSentTo($this->anna, TaskStatusChanged::class);
        Notification::assertNotSentTo($this->actor, TasksCompletionChanged::class);
        Notification::assertSentTo($this->anna, TasksCompletionChanged::class, fn (TasksCompletionChanged $notification) => count($notification->tasks) === 3
            && $notification->completed
            && $notification->changedBy === 'Otto'
            && array_column($notification->tasks, 'title') === ['Eins', 'Zwei', 'Drei']
            && $notification->tasks[0]['project'] === 'Website');
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

        Notification::assertSentTo($this->anna, TasksCompletionChanged::class, fn ($notification) => count($notification->tasks) === 2);
        Notification::assertSentTo($this->bert, TasksCompletionChanged::class, fn ($notification) => count($notification->tasks) === 3);
        Notification::assertSentToTimes($this->bert, TasksCompletionChanged::class, 1);
    }

    public function test_a_person_with_only_one_change_gets_the_normal_message(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei', ['assignee_id' => $this->bert->id]);
        Notification::fake();

        $this->bulk([$a, $b])->call('bulkComplete');

        Notification::assertSentTo($this->anna, TaskCompletionChanged::class, fn ($notification) => $notification->task->is($a) && $notification->completed && $notification->changedBy === 'Otto');
        Notification::assertSentTo($this->bert, TaskCompletionChanged::class);
        Notification::assertNotSentTo($this->anna, TasksCompletionChanged::class);
    }

    public function test_reopening_several_tasks_sends_one_message_per_person_and_one_task_the_single_message(): void
    {
        $now = now();
        $tasks = [$this->task('Eins', ['completed_at' => $now]), $this->task('Zwei', ['completed_at' => $now]), $this->task('Drei', ['completed_at' => $now])];
        $single = $this->task('Vier', ['assignee_id' => $this->bert->id, 'completed_at' => $now]);
        Notification::fake();

        $this->bulk([...$tasks, $single])->call('bulkReopen');

        Notification::assertSentToTimes($this->anna, TasksCompletionChanged::class, 1);
        Notification::assertSentTo($this->anna, TasksCompletionChanged::class, fn (TasksCompletionChanged $notification) => count($notification->tasks) === 3
            && $notification->completed === false
            && $notification->changedBy === 'Otto');
        Notification::assertNotSentTo($this->anna, TaskCompletionChanged::class);
        Notification::assertSentTo($this->bert, TaskCompletionChanged::class, fn (TaskCompletionChanged $notification) => $notification->task->is($single) && $notification->completed === false);
        Notification::assertNotSentTo($this->bert, TasksCompletionChanged::class);
        Notification::assertNotSentTo($this->anna, TasksStatusChanged::class);
    }

    public function test_finished_and_reopened_tasks_are_two_separate_messages_per_person(): void
    {
        $open = [$this->task('Eins'), $this->task('Zwei')];
        $done = [$this->task('Drei', ['completed_at' => now()]), $this->task('Vier', ['completed_at' => now()])];
        $lonelyDone = $this->task('Fuenf', ['completed_at' => now()]);
        Notification::fake();

        Task::bundlingStatusNotifications(function () use ($open, $done) {
            foreach ($open as $task) {
                $task->markDone();
            }

            foreach ($done as $task) {
                $task->reopen();
            }
        });

        Notification::assertSentToTimes($this->anna, TasksCompletionChanged::class, 2);
        Notification::assertSentTo($this->anna, TasksCompletionChanged::class, fn (TasksCompletionChanged $notification) => $notification->completed && array_column($notification->tasks, 'title') === ['Eins', 'Zwei']);
        Notification::assertSentTo($this->anna, TasksCompletionChanged::class, fn (TasksCompletionChanged $notification) => ! $notification->completed && array_column($notification->tasks, 'title') === ['Drei', 'Vier']);

        Notification::fake();

        Task::bundlingStatusNotifications(function () use ($open, $done, $lonelyDone) {
            $open[0]->reopen();
            $open[1]->reopen();
            $lonelyDone->reopen();
            $done[0]->markDone();
        });

        Notification::assertSentToTimes($this->anna, TasksCompletionChanged::class, 1);
        Notification::assertSentToTimes($this->anna, TaskCompletionChanged::class, 1);
        Notification::assertSentTo($this->anna, TaskCompletionChanged::class, fn (TaskCompletionChanged $notification) => $notification->task->is($done[0]) && $notification->completed);
        Notification::assertSentTo($this->anna, TasksCompletionChanged::class, fn (TasksCompletionChanged $notification) => ! $notification->completed && array_column($notification->tasks, 'title') === ['Eins', 'Zwei', 'Fuenf']);
    }

    public function test_a_change_of_status_and_done_in_one_save_gives_two_messages_and_bundles_them_apart(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei');
        $inWork = $this->project->statuses()->skip(1)->firstOrFail();
        Notification::fake();

        Task::bundlingStatusNotifications(function () use ($a, $b, $inWork) {
            $a->update(['status_id' => $inWork->id, 'completed_at' => now()]);
            $b->update(['status_id' => $inWork->id, 'completed_at' => now()]);
        });

        Notification::assertSentToTimes($this->anna, TasksStatusChanged::class, 1);
        Notification::assertSentToTimes($this->anna, TasksCompletionChanged::class, 1);
        Notification::assertSentTo($this->anna, TasksStatusChanged::class, fn (TasksStatusChanged $notification) => count($notification->changes) === 2 && $notification->changes[0]['to'] === $inWork->name);
    }

    public function test_the_change_window_bundles_too_and_other_changes_send_no_status_message(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei');
        Notification::fake();

        $this->bulk([$a, $b])->set('bulkAssignee', (string) $this->bert->id)->call('applyBulkChanges');
        Notification::assertNotSentTo($this->bert, TasksStatusChanged::class);
        Notification::assertNotSentTo($this->bert, TaskStatusChanged::class);

        $this->bulk([$a, $b])->set('bulkStatus', (string) $this->project->statuses()->skip(1)->firstOrFail()->id)->call('applyBulkChanges');
        Notification::assertSentToTimes($this->bert, TasksStatusChanged::class, 1);
    }

    public function test_changing_one_task_still_notifies_immediately(): void
    {
        $task = $this->task('Eins');
        Notification::fake();

        $task->toggleDone();

        Notification::assertSentTo($this->anna, TaskCompletionChanged::class, fn (TaskCompletionChanged $notification) => $notification->completed && $notification->task->is($task) && $notification->changedBy === 'Otto');
        Notification::assertNotSentTo($this->anna, TasksCompletionChanged::class);
        Notification::assertNotSentTo($this->anna, TaskStatusChanged::class);
    }

    public function test_reopening_a_task_tells_that_it_is_open_again(): void
    {
        $task = $this->task('Eins', ['completed_at' => now()]);
        Notification::fake();

        $task->toggleDone();

        Notification::assertSentTo($this->anna, TaskCompletionChanged::class, fn (TaskCompletionChanged $notification) => $notification->completed === false);
        Notification::assertNotSentTo($this->anna, TaskStatusChanged::class);
    }

    public function test_a_real_status_change_sends_its_own_message_and_finishing_does_not_change_the_status(): void
    {
        $task = $this->task('Eins');
        $inWork = $this->project->statuses()->skip(1)->firstOrFail();
        Notification::fake();

        $task->update(['status_id' => $inWork->id]);

        Notification::assertSentToTimes($this->anna, TaskStatusChanged::class, 1);
        Notification::assertSentTo($this->anna, TaskStatusChanged::class, fn (TaskStatusChanged $notification) => $notification->newStatus === $inWork->name);
        Notification::assertNotSentTo($this->anna, TaskCompletionChanged::class);

        $task->markDone();

        Notification::assertSentToTimes($this->anna, TaskStatusChanged::class, 1);
        Notification::assertSentToTimes($this->anna, TaskCompletionChanged::class, 1);
    }

    public function test_status_and_done_in_one_save_give_both_messages(): void
    {
        $task = $this->task('Eins');
        Notification::fake();

        $task->update(['status_id' => $this->project->statuses()->skip(1)->firstOrFail()->id, 'completed_at' => now()]);

        Notification::assertSentToTimes($this->anna, TaskStatusChanged::class, 1);
        Notification::assertSentToTimes($this->anna, TaskCompletionChanged::class, 1);
    }

    public function test_the_actor_is_not_told_about_finishing(): void
    {
        $task = $this->task('Eins', ['assignee_id' => $this->actor->id]);
        Notification::fake();

        $task->toggleDone();

        Notification::assertNothingSent();
    }

    public function test_a_failure_during_bundling_does_not_swallow_later_notifications(): void
    {
        $task = $this->task('Eins');
        Notification::fake();

        try {
            Task::bundlingStatusNotifications(function (): never {
                throw new RuntimeException('kaputt');
            });
        } catch (RuntimeException) {
        }

        $task->toggleDone();

        Notification::assertSentTo($this->anna, TaskCompletionChanged::class);
    }

    public function test_the_mail_lists_the_tasks_in_both_languages_and_cuts_long_lists(): void
    {
        $changes = array_map(fn (int $n) => ['id' => $n, 'title' => "Aufgabe {$n}", 'project' => 'Website', 'from' => 'Offen', 'to' => 'Erledigt'], range(1, TasksStatusChanged::LISTED + 5));
        $notification = new TasksStatusChanged($changes, 'Otto');

        LocaleService::apply('de');
        $mail = $notification->toMail($this->anna);
        $text = preg_replace('/\s+/', ' ', strip_tags((string) $mail->render()));
        $this->assertSame('Status geändert bei 30 Aufgaben', $mail->subject);
        $this->assertStringContainsString('Otto hat den Status von 30 Aufgaben geändert', $text);
        $this->assertStringContainsString('Aufgabe 1 · Website · Offen → Erledigt', $text);
        $this->assertStringContainsString('Aufgabe 25 ', $text);
        $this->assertStringNotContainsString('Aufgabe 26 ', $text);
        $this->assertStringContainsString('… und 5 weitere', $text);

        LocaleService::apply('en');
        $mail = $notification->toMail($this->anna);
        $this->assertSame('Status changed on 30 tasks', $mail->subject);
        $this->assertStringContainsString('… and 5 more', preg_replace('/\s+/', ' ', strip_tags((string) $mail->render())));
    }

    public function test_the_inbox_shows_one_entry_in_the_language_of_the_reader(): void
    {
        $this->anna->notifications()->create(['id' => 'x', 'type' => TasksStatusChanged::class, 'data' => (new TasksStatusChanged([['id' => 7, 'title' => 'A', 'project' => 'P', 'from' => 'a', 'to' => 'b'], ['id' => 8, 'title' => 'B', 'project' => 'P', 'from' => 'a', 'to' => 'b']], 'Otto'))->toArray($this->anna)]);
        $entry = $this->anna->notifications()->firstOrFail();

        LocaleService::apply('de');
        $this->assertSame('Otto hat den Status von 2 Aufgaben geändert', InboxTextService::sentence($entry));
        LocaleService::apply('en');
        $this->assertSame('Otto changed the status of 2 tasks', InboxTextService::sentence($entry));
        $this->assertSame(7, $entry->data['task_id']);
    }
}
