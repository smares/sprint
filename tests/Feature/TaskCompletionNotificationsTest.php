<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCompletionChanged;
use App\Notifications\TasksCompletionChanged;
use App\Notifications\TaskStatusChanged;
use App\Services\InboxTextService;
use App\Services\LocaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskCompletionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $reader;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['name' => 'Otto', 'locale' => 'de']);
        $this->reader = User::factory()->create(['name' => 'Anna', 'locale' => 'en']);
        $this->project = Project::factory()->create(['name' => 'Website']);
        $this->project->setRole($this->actor, ProjectRole::Editor);
        $this->project->setRole($this->reader, ProjectRole::Editor);
        $this->actingAs($this->actor);
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes + ['title' => 'Angebot', 'assignee_id' => $this->reader->id]);
    }

    public function test_finishing_a_task_notifies_the_assignee_once_and_not_the_actor(): void
    {
        $task = $this->task();
        $task->collaborators()->attach($this->actor);
        Notification::fake();

        $task->markDone();

        Notification::assertSentToTimes($this->reader, TaskCompletionChanged::class, 1);
        Notification::assertSentTo($this->reader, TaskCompletionChanged::class, fn (TaskCompletionChanged $notification) => $notification->completed === true && $notification->changedBy === 'Otto' && $notification->task->is($task));
        Notification::assertNotSentTo($this->actor, TaskCompletionChanged::class);
        Notification::assertNotSentTo($this->reader, TaskStatusChanged::class);
        Notification::assertCount(1);
    }

    public function test_reopening_a_task_sends_the_reopened_kind(): void
    {
        $task = $this->task(['completed_at' => now()]);
        Notification::fake();

        $task->reopen();

        Notification::assertSentToTimes($this->reader, TaskCompletionChanged::class, 1);
        Notification::assertSentTo($this->reader, TaskCompletionChanged::class, fn (TaskCompletionChanged $notification) => $notification->completed === false);
    }

    public function test_reopening_a_task_that_is_open_sends_nothing(): void
    {
        $task = $this->task();
        Notification::fake();

        $task->reopen();

        Notification::assertNothingSent();
    }

    public function test_what_is_stored_is_the_kind_and_not_the_words(): void
    {
        $task = $this->task();

        $task->markDone();
        $task->reopen();

        $stored = $this->reader->notifications()->where('type', TaskCompletionChanged::class)->get()->map(fn ($notification) => $notification->data)->all();
        $this->assertCount(2, $stored);
        $this->assertEqualsCanonicalizing(['completed', 'reopened'], array_column($stored, 'kind'));
        $this->assertSame([$task->id, $task->id], array_column($stored, 'task_id'));
        $this->assertSame(['Otto', 'Otto'], array_column($stored, 'by'));
        $this->assertStringNotContainsString('erledigt', json_encode($stored));
    }

    public function test_the_inbox_sentence_follows_the_language_of_the_reader_not_of_the_actor(): void
    {
        LocaleService::apply('de');
        $task = $this->task();
        $task->markDone();
        $task->reopen();
        $sentences = fn () => $this->reader->notifications()->where('type', TaskCompletionChanged::class)->get()->map(fn ($notification) => InboxTextService::sentence($notification))->sort()->values()->all();

        $this->assertSame(['Otto hat die Aufgabe erledigt', 'Otto hat die Aufgabe wieder geöffnet'], $sentences());

        LocaleService::apply('en');
        $this->assertSame(['Otto completed the task', 'Otto reopened the task'], $sentences());
    }

    public function test_the_bundle_is_stored_with_a_count_and_read_in_the_language_of_the_reader(): void
    {
        $tasks = [['id' => 7, 'title' => 'A', 'project' => 'Website'], ['id' => 8, 'title' => 'B', 'project' => 'Website']];
        foreach ([true, false] as $completed) {
            $data = (new TasksCompletionChanged($tasks, $completed, 'Otto'))->toArray($this->reader);
            $this->reader->notifications()->create(['id' => $completed ? 'a' : 'b', 'type' => TasksCompletionChanged::class, 'data' => $data]);
        }

        $this->assertSame(['task_id' => 7, 'kind' => 'completed_many', 'by' => 'Otto', 'count' => 2], $this->reader->notifications()->findOrFail('a')->data);
        $this->assertSame('reopened_many', $this->reader->notifications()->findOrFail('b')->data['kind']);

        $sentences = fn () => $this->reader->notifications()->orderBy('id')->get()->map(fn ($notification) => InboxTextService::sentence($notification))->all();

        LocaleService::apply('de');
        $this->assertSame(['Otto hat 2 Aufgaben erledigt', 'Otto hat 2 Aufgaben wieder geöffnet'], $sentences());

        LocaleService::apply('en');
        $this->assertSame(['Otto completed 2 tasks', 'Otto reopened 2 tasks'], $sentences());
    }

    public function test_the_mail_is_written_in_the_language_of_the_recipient(): void
    {
        $task = $this->task(['title' => 'Angebot']);
        $mail = fn () => (new TaskCompletionChanged($task, true, 'Otto'))->toMail($this->reader);
        $text = fn () => preg_replace('/\s+/', ' ', strip_tags((string) $mail()->render()));
        $reopened = fn () => (new TaskCompletionChanged($task, false, 'Otto'))->toMail($this->reader);

        LocaleService::apply('de');
        $this->assertSame('Erledigt: Angebot', $mail()->subject);
        $this->assertSame('Wieder geöffnet: Angebot', $reopened()->subject);
        $this->assertStringContainsString('Otto hat die Aufgabe „Angebot“ im Projekt „Website“ erledigt', $text());
        $this->assertStringContainsString('wieder geöffnet', preg_replace('/\s+/', ' ', strip_tags((string) $reopened()->render())));

        LocaleService::apply('en');
        $this->assertSame('Completed: Angebot', $mail()->subject);
        $this->assertSame('Reopened: Angebot', $reopened()->subject);
        $this->assertStringContainsString('Otto completed the task “Angebot” in the project “Website”', $text());
        $this->assertStringContainsString('Otto reopened the task', preg_replace('/\s+/', ' ', strip_tags((string) $reopened()->render())));
    }

    public function test_the_bundle_mail_lists_the_tasks_in_the_language_of_the_recipient(): void
    {
        $tasks = array_map(fn (int $n) => ['id' => $n, 'title' => "Aufgabe {$n}", 'project' => 'Website'], range(1, TasksCompletionChanged::LISTED + 5));
        $mail = fn (bool $completed) => (new TasksCompletionChanged($tasks, $completed, 'Otto'))->toMail($this->reader);
        $text = fn (bool $completed) => preg_replace('/\s+/', ' ', strip_tags((string) $mail($completed)->render()));

        LocaleService::apply('de');
        $this->assertSame('Erledigt: 30 Aufgaben', $mail(true)->subject);
        $this->assertSame('Wieder geöffnet: 30 Aufgaben', $mail(false)->subject);
        $this->assertStringContainsString('Otto hat 30 Aufgaben erledigt', $text(true));
        $this->assertStringContainsString('Otto hat 30 Aufgaben wieder geöffnet', $text(false));
        $this->assertStringContainsString('Aufgabe 25 ', $text(true));
        $this->assertStringNotContainsString('Aufgabe 26 ', $text(true));
        $this->assertStringContainsString('… und 5 weitere', $text(true));

        LocaleService::apply('en');
        $this->assertSame('Completed: 30 tasks', $mail(true)->subject);
        $this->assertSame('Reopened: 30 tasks', $mail(false)->subject);
        $this->assertStringContainsString('Otto completed 30 tasks', $text(true));
        $this->assertStringContainsString('… and 5 more', $text(true));
    }

    public function test_status_and_done_in_one_save_tell_both(): void
    {
        $task = $this->task();
        $inWork = $this->project->statuses()->skip(1)->firstOrFail();
        Notification::fake();

        $task->update(['status_id' => $inWork->id, 'completed_at' => now()]);

        Notification::assertSentToTimes($this->reader, TaskStatusChanged::class, 1);
        Notification::assertSentTo($this->reader, TaskStatusChanged::class, fn (TaskStatusChanged $notification) => $notification->newStatus === $inWork->name);
        Notification::assertSentToTimes($this->reader, TaskCompletionChanged::class, 1);
        Notification::assertCount(2);
    }
}
