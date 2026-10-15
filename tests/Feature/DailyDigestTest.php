<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DailyDigest as DailyDigestNotification;
use App\Services\DailyDigestService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 07:30:00');
        $this->user = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);
        $this->project = Project::factory()->create(['name' => 'Website']);
        $this->project->setRole($this->user, ProjectRole::Editor);
    }

    private function task(string $title, ?string $due, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes + ['title' => $title, 'due_date' => $due, 'assignee_id' => $this->user->id]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function digest(?User $user = null): array
    {
        return collect(app(DailyDigestService::class)->tasksFor($user ?? $this->user))->map(fn ($tasks) => $tasks->pluck('title')->all())->all();
    }

    public function test_tasks_are_sorted_into_overdue_today_and_upcoming(): void
    {
        $this->task('Alt', '2026-10-02');
        $this->task('Gestern', '2026-10-06');
        $this->task('Heute', '2026-10-07');
        $this->task('Morgen', '2026-10-08');
        $this->task('In drei Tagen', '2026-10-10');
        $this->task('In vier Tagen', '2026-10-11');
        $this->task('Ohne Datum', null);

        $this->assertSame([
            'overdue' => ['Alt', 'Gestern'],
            'today' => ['Heute'],
            'upcoming' => ['Morgen', 'In drei Tagen'],
        ], $this->digest());
    }

    public function test_collaborators_are_included_and_strangers_are_not(): void
    {
        $this->task('Fremd', '2026-10-07', ['assignee_id' => User::factory()->create()->id]);
        $shared = $this->task('Gemeinsam', '2026-10-07', ['assignee_id' => null]);
        $shared->collaborators()->attach($this->user);

        $this->assertSame(['Gemeinsam'], $this->digest()['today']);
    }

    public function test_finished_tasks_headings_muted_tasks_and_archived_projects_are_left_out(): void
    {
        $this->task('Erledigt', '2026-10-07', ['status_id' => $this->project->doneStatus()->id]);
        $this->task('Überschrift', '2026-10-07', ['is_section' => true]);
        $this->task('Stumm', '2026-10-07')->setMutedBy($this->user, true);
        $archived = Project::factory()->create();
        $archived->setRole($this->user, ProjectRole::Editor);
        $archived->update(['archived_at' => now()]);
        Task::factory()->for($archived)->create(['title' => 'Im Archiv', 'due_date' => '2026-10-07', 'assignee_id' => $this->user->id]);
        $this->task('Sichtbar', '2026-10-07');

        $this->assertSame(['Sichtbar'], $this->digest()['today']);
    }

    public function test_tasks_from_projects_the_person_cannot_see_are_left_out(): void
    {
        $foreign = Project::factory()->create();
        Task::factory()->for($foreign)->create(['title' => 'Nicht mehr sichtbar', 'due_date' => '2026-10-07', 'assignee_id' => $this->user->id]);

        $this->assertSame([], $this->digest()['today']);
    }

    public function test_the_command_mails_only_people_who_have_something_to_hear(): void
    {
        Notification::fake();
        $this->task('Heute', '2026-10-07');
        $quiet = User::factory()->create();
        $this->project->setRole($quiet, ProjectRole::Editor);

        $this->artisan('digest:send')->expectsOutputToContain('1 summary mail(s) sent')->assertSuccessful();

        Notification::assertSentTo($this->user, DailyDigestNotification::class);
        Notification::assertNotSentTo($quiet, DailyDigestNotification::class);
    }

    public function test_people_who_switched_it_off_and_deactivated_people_get_nothing(): void
    {
        Notification::fake();
        $this->task('Heute', '2026-10-07');
        $off = User::factory()->create(['digest_enabled' => false]);
        $gone = User::factory()->deactivated()->create();
        foreach ([$off, $gone] as $person) {
            $this->project->setRole($person, ProjectRole::Editor);
            $this->task('Von '.$person->id, '2026-10-07', ['assignee_id' => $person->id]);
        }

        $this->artisan('digest:send')->assertSuccessful();

        Notification::assertSentTo($this->user, DailyDigestNotification::class);
        Notification::assertNotSentTo($off, DailyDigestNotification::class);
        Notification::assertNotSentTo($gone, DailyDigestNotification::class);
    }

    public function test_it_can_be_limited_to_one_person(): void
    {
        Notification::fake();
        $this->task('Heute', '2026-10-07');
        $other = User::factory()->create();
        $this->project->setRole($other, ProjectRole::Editor);
        $this->task('Auch heute', '2026-10-07', ['assignee_id' => $other->id]);

        $this->artisan('digest:send', ['--user' => $other->email])->assertSuccessful();

        Notification::assertSentTo($other, DailyDigestNotification::class);
        Notification::assertNotSentTo($this->user, DailyDigestNotification::class);
    }

    public function test_the_mail_lists_the_tasks_with_links_and_a_telling_subject(): void
    {
        $overdue = $this->task('Rechnung schreiben', '2026-10-05');
        $this->task('Angebot prüfen', '2026-10-07');
        $this->task('Planung', '2026-10-09');

        $mail = (new DailyDigestNotification(app(DailyDigestService::class)->tasksFor($this->user)))->toMail($this->user);
        $html = (string) $mail->render();

        $this->assertSame('Deine Aufgaben: 1 überfällig, 1 heute fällig, 1 demnächst', $mail->subject);
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('Überfällig', $text);
        $this->assertStringContainsString($overdue->title.' · Website · 05.10.2026', $text);
        $this->assertStringContainsString('Heute fällig', $text);
        $this->assertStringContainsString('In den nächsten Tagen', $text);
        $this->assertStringContainsString('href="'.route('tasks.show', $overdue).'"', $html);
        $this->assertStringContainsString('href="'.route('tasks.mine').'"', $html);
    }

    public function test_long_sections_are_cut_off_with_a_count(): void
    {
        foreach (range(1, 30) as $number) {
            $this->task(sprintf('Alt %02d', $number), '2026-09-'.sprintf('%02d', $number));
        }

        $text = preg_replace('/\s+/', ' ', strip_tags((string) (new DailyDigestNotification(app(DailyDigestService::class)->tasksFor($this->user)))->toMail($this->user)->render()));

        $this->assertStringContainsString('Alt 25', $text);
        $this->assertStringNotContainsString('Alt 26', $text);
        $this->assertStringContainsString('… und 5 weitere', $text);
    }

    public function test_sections_without_tasks_do_not_appear_in_the_mail(): void
    {
        $this->task('Nur heute', '2026-10-07');

        $text = strip_tags((string) (new DailyDigestNotification(app(DailyDigestService::class)->tasksFor($this->user)))->toMail($this->user)->render());

        $this->assertStringNotContainsString('Überfällig', $text);
        $this->assertStringNotContainsString('In den nächsten Tagen', $text);
    }

    public function test_it_is_scheduled_for_weekday_mornings(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'digest:send'));

        $this->assertNotNull($event);
        $this->assertSame('30 7 * * 1-5', $event->expression);
    }

    public function test_the_person_can_switch_it_off_in_the_profile(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::profile')->assertSet('digest', true)->set('digest', false);
        $this->assertFalse($this->user->fresh()->digest_enabled);

        Livewire::test('pages::profile')->assertSet('digest', false)->set('digest', true);
        $this->assertTrue($this->user->fresh()->digest_enabled);
    }
}
