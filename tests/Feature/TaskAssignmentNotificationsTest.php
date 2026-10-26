<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Notifications\TasksAssigned;
use App\Notifications\TaskStatusChanged;
use App\Notifications\UserMentioned;
use App\Services\InboxTextService;
use App\Services\LocaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TaskAssignmentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $anna;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['name' => 'Otto']);
        $this->anna = User::factory()->create(['name' => 'Anna']);
        $this->project = Project::factory()->create(['name' => 'Website']);

        foreach ([$this->actor, $this->anna] as $user) {
            $this->project->setRole($user, ProjectRole::Editor);
        }

        $this->actingAs($this->actor);
        Notification::fake();
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes + ['title' => 'Texte']);
    }

    public function test_a_task_created_for_somebody_tells_them(): void
    {
        $task = $this->task(['assignee_id' => $this->anna->id]);

        Notification::assertSentTo($this->anna, TaskAssigned::class, fn (TaskAssigned $notification) => $notification->task->is($task) && $notification->assignedBy === 'Otto');
    }

    public function test_the_new_task_dialog_tells_the_assignee(): void
    {
        Livewire::test('task-create', ['project' => $this->project])
            ->set('title', 'Neu')
            ->set('assigneeId', (string) $this->anna->id)
            ->call('create');

        Notification::assertSentToTimes($this->anna, TaskAssigned::class, 1);
    }

    public function test_handing_a_task_over_tells_the_new_assignee_only(): void
    {
        $task = $this->task();
        Notification::assertNothingSent();

        $task->assignTo($this->anna);

        Notification::assertSentToTimes($this->anna, TaskAssigned::class, 1);
        Notification::assertNotSentTo($this->actor, TaskAssigned::class);
    }

    public function test_assigning_yourself_or_nobody_or_changing_other_things_sends_nothing(): void
    {
        $task = $this->task(['assignee_id' => $this->anna->id]);
        Notification::fake();

        $task->assignTo($this->actor);
        $task->assignTo(null);
        $task->update(['title' => 'Anders']);

        Notification::assertNothingSent();
    }

    public function test_a_task_that_keeps_its_assignee_does_not_tell_again(): void
    {
        $task = $this->task(['assignee_id' => $this->anna->id]);
        Notification::fake();

        $task->update(['assignee_id' => $this->anna->id, 'title' => 'Anders']);

        Notification::assertNothingSent();
    }

    public function test_a_muted_task_and_people_without_access_are_not_told(): void
    {
        $muted = $this->task();
        $muted->setMutedBy($this->anna, true);
        $muted->assignTo($this->anna);

        $outsider = User::factory()->create();
        $this->task()->assignTo($outsider);

        Notification::assertNothingSent();
    }

    public function test_the_assignee_is_not_told_twice_for_being_mentioned_in_the_same_task(): void
    {
        $task = $this->task(['assignee_id' => $this->anna->id]);
        Notification::fake();
        $task->update(['assignee_id' => null]);

        $task->update(['assignee_id' => $this->anna->id, 'description' => "@[Anna](user:{$this->anna->id}) bitte"]);
        $task->notifyMentionedInDescription([$this->anna->id], $this->actor);

        Notification::assertSentToTimes($this->anna, TaskAssigned::class, 1);
        Notification::assertNotSentTo($this->anna, UserMentioned::class);
    }

    public function test_a_status_change_in_the_same_save_does_not_tell_the_new_assignee_twice(): void
    {
        $task = $this->task();

        $task->update(['assignee_id' => $this->anna->id, 'status_id' => $this->project->doneStatus()->id]);

        Notification::assertSentToTimes($this->anna, TaskAssigned::class, 1);
        Notification::assertNotSentTo($this->anna, TaskStatusChanged::class);
    }

    public function test_nobody_is_told_without_a_person_or_rule_behind_the_change(): void
    {
        auth()->logout();

        $this->task(['assignee_id' => $this->anna->id]);

        Notification::assertNothingSent();
    }

    public function test_sections_are_not_announced(): void
    {
        Task::factory()->for($this->project)->create(['is_section' => true, 'title' => 'Phase', 'assignee_id' => $this->anna->id]);

        Notification::assertNothingSent();
    }

    public function test_copies_and_imports_stay_silent(): void
    {
        $task = $this->task(['assignee_id' => $this->anna->id]);
        Notification::fake();

        $task->duplicate();
        Task::withoutAssignmentNotices(fn () => $this->task(['assignee_id' => $this->anna->id]));

        Notification::assertNothingSent();
    }

    public function test_a_change_in_bulk_sends_one_message_per_person(): void
    {
        $tasks = [$this->task(), $this->task(['title' => 'Zwei']), $this->task(['title' => 'Drei'])];

        Livewire::test('pages::projects.show', ['project' => $this->project])->set('selecting', true)
            ->set('selected', array_map(fn (Task $task) => (string) $task->id, $tasks))
            ->set('bulkAssignee', (string) $this->anna->id)->call('applyBulkChanges');

        Notification::assertSentToTimes($this->anna, TasksAssigned::class, 1);
        Notification::assertNotSentTo($this->anna, TaskAssigned::class);
        Notification::assertSentTo($this->anna, TasksAssigned::class, fn (TasksAssigned $notification) => count($notification->tasks) === 3 && $notification->assignedBy === 'Otto');
    }

    public function test_a_bulk_change_of_one_task_sends_the_normal_message(): void
    {
        $task = $this->task();

        Livewire::test('pages::projects.show', ['project' => $this->project])->set('selecting', true)
            ->set('selected', [(string) $task->id])
            ->set('bulkAssignee', (string) $this->anna->id)->call('applyBulkChanges');

        Notification::assertSentToTimes($this->anna, TaskAssigned::class, 1);
        Notification::assertNotSentTo($this->anna, TasksAssigned::class);
    }

    public function test_the_mail_and_the_inbox_follow_the_language_of_the_reader(): void
    {
        $task = $this->task(['assignee_id' => $this->anna->id, 'title' => 'Texte']);

        foreach (['de' => ['Dir zugewiesen: Texte', 'Otto hat dir diese Aufgabe zugewiesen'], 'en' => ['Assigned to you: Texte', 'Otto assigned this task to you']] as $locale => [$subject, $sentence]) {
            app()->setLocale($locale);
            $notification = new TaskAssigned($task, 'Otto');

            $this->assertSame($subject, $notification->toMail($this->anna)->subject);
            $this->assertSame($sentence, TaskAssigned::sentence($notification->toArray($this->anna)));
        }

        app()->setLocale('de');
        $many = new TasksAssigned([['id' => $task->id, 'title' => 'Texte', 'project' => 'Website']], 'Otto');
        $this->assertSame('1 Aufgaben dir zugewiesen', $many->toMail($this->anna)->subject);
        $this->assertSame('Otto hat dir 3 Aufgaben zugewiesen', TasksAssigned::sentence(['by' => 'Otto', 'count' => 3]));
    }

    public function test_the_inbox_shows_the_sentence(): void
    {
        $task = $this->task(['assignee_id' => $this->anna->id]);
        $notification = new TaskAssigned($task, 'Otto');
        $this->anna->notifications()->create(['id' => 'x', 'type' => TaskAssigned::class, 'data' => $notification->toArray($this->anna)]);

        app(LocaleService::class);
        app()->setLocale('en');

        $this->assertSame('Otto assigned this task to you', InboxTextService::sentence($this->anna->notifications()->first()));
    }
}
