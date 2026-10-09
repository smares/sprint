<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Notifications\TaskCommented;
use App\Notifications\TaskStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PlannedAbsenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_absence_counts_from_its_first_to_its_last_day(): void
    {
        $this->travelTo('2026-10-12 08:00');
        $user = User::factory()->create(['absent_from' => '2026-10-12', 'absent_until' => '2026-10-16']);

        $this->assertTrue($user->isAbsent());
        $this->assertSame('abwesend bis 2026-10-16', $user->absenceNote());
        $this->assertSame($user->name.' (abwesend bis 2026-10-16)', $user->labelledName());

        $this->travelTo('2026-10-16 23:00');
        $this->assertTrue($user->fresh()->isAbsent());

        $this->travelTo('2026-10-17 00:01');
        $this->assertFalse($user->fresh()->isAbsent());
        $this->assertNull($user->fresh()->absenceNote());
        $this->assertSame($user->name, $user->fresh()->labelledName());

        $this->travelTo('2026-10-11 12:00');
        $this->assertFalse($user->fresh()->isAbsent());
        $this->assertTrue($user->fresh()->hasPlannedAbsence());
    }

    public function test_the_profile_saves_and_removes_the_one_absence(): void
    {
        $this->travelTo('2026-10-09');
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::profile')
            ->set('absentFrom', '2026-10-12')->set('absentUntil', '2026-10-16')->call('saveAbsence')
            ->assertHasNoErrors()->assertDispatched('toast-show');

        $this->assertSame(['2026-10-12', '2026-10-16'], [$user->fresh()->absent_from->toDateString(), $user->fresh()->absent_until->toDateString()]);

        Livewire::test('pages::profile')
            ->assertSet('absentFrom', '2026-10-12')->assertSet('absentUntil', '2026-10-16')->assertSee('Abwesenheit entfernen')
            ->call('clearAbsence')->assertSet('absentFrom', '')->assertSet('absentUntil', '');

        $this->assertNull($user->fresh()->absent_from);
        $this->assertNull($user->fresh()->absent_until);
    }

    public function test_the_end_must_not_lie_before_the_start_or_in_the_past(): void
    {
        $this->travelTo('2026-10-09');
        $this->actingAs($user = User::factory()->create());

        Livewire::test('pages::profile')->set('absentFrom', '2026-10-12')->set('absentUntil', '2026-10-11')->call('saveAbsence')->assertHasErrors('absentUntil');
        Livewire::test('pages::profile')->set('absentFrom', '2026-10-01')->set('absentUntil', '2026-10-08')->call('saveAbsence')->assertHasErrors('absentUntil');
        Livewire::test('pages::profile')->set('absentFrom', '')->set('absentUntil', '2026-10-12')->call('saveAbsence')->assertHasErrors('absentFrom');

        $this->assertNull($user->fresh()->absent_until);
    }

    public function test_a_past_absence_is_not_shown_in_the_profile_again(): void
    {
        $this->travelTo('2026-10-20');
        $this->actingAs(User::factory()->create(['absent_from' => '2026-10-12', 'absent_until' => '2026-10-16']));

        Livewire::test('pages::profile')->assertSet('absentFrom', '')->assertSet('absentUntil', '')->assertDontSee('Abwesenheit entfernen');
    }

    public function test_while_away_task_notifications_reach_only_the_inbox(): void
    {
        Notification::fake();
        $away = User::factory()->admin()->absent()->create();
        $present = User::factory()->admin()->create();
        $task = Task::factory()->create(['assignee_id' => $away->id]);
        $task->collaborators()->attach($present);
        $this->actingAs(User::factory()->admin()->create());

        Comment::factory()->for($task)->create(['user_id' => auth()->id(), 'body' => 'Bitte ansehen']);
        $task->update(['status_id' => $task->project->doneStatus()->id]);

        foreach ([TaskCommented::class, TaskStatusChanged::class] as $notification) {
            Notification::assertSentTo($away, $notification, fn ($sent, array $channels) => $channels === ['database']);
            Notification::assertSentTo($present, $notification, fn ($sent, array $channels) => $channels === ['mail', 'database']);
        }
    }

    public function test_no_daily_digest_while_away(): void
    {
        Notification::fake();
        $away = User::factory()->admin()->absent()->create();
        $present = User::factory()->admin()->create();
        foreach ([$away, $present] as $user) {
            Task::factory()->create(['assignee_id' => $user->id, 'due_date' => today()->subDay()]);
        }

        $this->artisan('digest:send')->expectsOutputToContain('1 summary mail(s) sent')->assertSuccessful();

        Notification::assertNotSentTo($away, DailyDigest::class);
        Notification::assertSentTo($present, DailyDigest::class);
    }

    public function test_others_see_the_absence_next_to_the_name_and_a_faded_avatar(): void
    {
        $this->travelTo('2026-10-12');
        $away = User::factory()->create(['name' => 'Anna Abwesend', 'absent_from' => '2026-10-12', 'absent_until' => '2026-10-16']);
        $project = Project::factory()->create();
        $project->setRole($away, ProjectRole::Editor);
        $task = Task::factory()->for($project)->create(['assignee_id' => $away->id]);
        $this->actingAs(User::factory()->admin()->create());

        $label = 'Anna Abwesend (abwesend bis 2026-10-16)';

        $this->get(route('projects.show', $project))->assertSee($label);
        $this->get(route('projects.board', $project))->assertSee($label)->assertSee('opacity-50', false)->assertSee('title="abwesend bis 2026-10-16"', false);
        $this->get(route('tasks.show', $task))->assertSee($label);
        $this->get(route('projects.members', $project))->assertSee('Abwesend bis 2026-10-16');
        $this->get(route('admin.users'))->assertSee('Abwesend bis 2026-10-16');

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->assertSet('mentionOptions.users', fn (array $users) => collect($users)->firstWhere('name', 'Anna Abwesend')['note'] === 'abwesend bis 2026-10-16');
    }
}
