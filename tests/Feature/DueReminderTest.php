<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDueTomorrow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DueReminderTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $anna;

    private User $bernd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 08:00:00');
        $this->project = Project::factory()->create();
        $this->anna = User::factory()->create(['locale' => 'de']);
        $this->bernd = User::factory()->create();
        $this->project->setRole($this->anna, ProjectRole::Editor);
        $this->project->setRole($this->bernd, ProjectRole::Editor);
    }

    private function taskDue(string $date, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create(['due_date' => $date, 'assignee_id' => $this->anna->id, ...$attributes]);
    }

    public function test_assignees_and_collaborators_get_an_inbox_entry_for_tasks_due_tomorrow(): void
    {
        Notification::fake();
        $tomorrow = $this->taskDue('2026-10-09');
        $tomorrow->collaborators()->attach($this->bernd);
        $this->taskDue('2026-10-08');
        $this->taskDue('2026-10-10');

        $this->artisan('reminders:send')->expectsOutputToContain('2 reminder(s)')->assertSuccessful();

        Notification::assertSentTo([$this->anna, $this->bernd], TaskDueTomorrow::class, fn (TaskDueTomorrow $notification) => $notification->task->is($tomorrow));
        Notification::assertCount(2);
    }

    public function test_done_muted_archived_and_unwanted_ones_are_left_out(): void
    {
        Notification::fake();
        $this->taskDue('2026-10-09', ['status_id' => $this->project->doneStatus()->id]);
        $this->taskDue('2026-10-09', ['is_section' => true]);
        $muted = $this->taskDue('2026-10-09');
        $muted->notificationMutes()->attach($this->anna);
        $this->taskDue('2026-10-09', ['assignee_id' => $this->bernd->id]);
        $this->bernd->update(['reminders_enabled' => false]);
        $archived = Project::factory()->create(['archived_at' => now()]);
        $archived->setRole($this->anna, ProjectRole::Editor);
        Task::factory()->for($archived)->create(['due_date' => '2026-10-09', 'assignee_id' => $this->anna->id]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_second_run_on_the_same_day_sends_nothing_again(): void
    {
        Notification::fake();
        $this->taskDue('2026-10-09');

        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:send')->expectsOutputToContain('already been sent')->assertSuccessful();
        Notification::assertCount(1);

        $this->artisan('reminders:send', ['--force' => true])->assertSuccessful();
        Notification::assertCount(2);
    }

    public function test_the_entry_reads_in_the_language_of_the_reader(): void
    {
        $this->taskDue('2026-10-09', ['title' => 'Angebot schicken']);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertSame(1, $this->anna->notifications()->count());
        $this->actingAs($this->anna)->get(route('inbox'))->assertOk()
            ->assertSee('Angebot schicken')
            ->assertSee('Morgen fällig (2026-10-09)');
    }

    public function test_it_runs_every_morning(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'reminders:send'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * *', $event->expression);
    }

    public function test_people_can_turn_reminders_off_in_their_profile(): void
    {
        $this->actingAs($this->anna);

        Livewire::test('pages::profile')->assertSet('reminders', true)->set('reminders', false);

        $this->assertFalse($this->anna->fresh()->reminders_enabled);
    }

    public function test_assignees_are_loaded_with_the_tasks_and_not_one_by_one(): void
    {
        Notification::fake();
        foreach (range(1, 3) as $index) {
            $person = User::factory()->create();
            $this->project->setRole($person, ProjectRole::Editor);
            $this->taskDue('2026-10-09', ['assignee_id' => $person->id]);
        }

        DB::enableQueryLog();
        $this->artisan('reminders:send')->expectsOutputToContain('3 reminder(s)')->assertSuccessful();

        $singleUserLookups = collect(DB::getQueryLog())
            ->filter(fn (array $query) => preg_match('/from "users" where "users"\."id" = \? limit 1/', $query['query']) === 1);
        $this->assertCount(0, $singleUserLookups);
    }
}
