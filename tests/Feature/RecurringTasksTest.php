<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Enums\RepeatMode;
use App\Enums\RepeatUnit;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RecurringTasksTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 10:00:00');
        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
    }

    private function recurring(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes + [
            'title' => 'Wochenbericht',
            'due_date' => '2026-10-09',
            'repeat_unit' => RepeatUnit::Week,
            'repeat_interval' => 1,
            'repeat_mode' => RepeatMode::Schedule,
        ]);
    }

    private function others(Task $task)
    {
        return Task::where('id', '!=', $task->id)->where('project_id', $this->project->id);
    }

    public function test_finishing_a_recurring_task_creates_the_next_one(): void
    {
        $task = $this->recurring();

        $task->toggleDone();

        $next = $this->others($task)->firstOrFail();
        $this->assertSame('Wochenbericht', $next->title);
        $this->assertSame('2026-10-16', $next->due_date->toDateString());
        $this->assertSame($this->project->defaultStatus()->id, $next->status_id);
        $this->assertSame(RepeatUnit::Week, $next->repeat_unit);
    }

    public function test_the_rule_moves_to_the_new_task_so_reopening_does_not_duplicate_it(): void
    {
        $task = $this->recurring();

        $task->toggleDone();
        $this->assertNull($task->fresh()->repeat_unit);

        $task->fresh()->toggleDone();
        $task->fresh()->toggleDone();

        $this->assertSame(1, $this->others($task)->count());
    }

    public function test_tasks_without_a_rule_or_due_date_create_nothing(): void
    {
        Task::factory()->for($this->project)->create(['due_date' => '2026-10-09'])->toggleDone();
        $this->recurring(['due_date' => null])->toggleDone();

        $this->assertSame(2, Task::count());
    }

    public function test_only_finishing_triggers_it_not_other_status_changes(): void
    {
        $task = $this->recurring();

        $task->update(['status_id' => $this->project->statuses[1]->id]);

        $this->assertSame(1, Task::count());
        $this->assertNotNull($task->fresh()->repeat_unit);
    }

    public function test_every_unit_and_interval_is_added_correctly(): void
    {
        $cases = [
            [RepeatUnit::Day, 3, '2026-10-12'],
            [RepeatUnit::Week, 2, '2026-10-23'],
            [RepeatUnit::Month, 1, '2026-11-09'],
            [RepeatUnit::Year, 1, '2027-10-09'],
        ];

        foreach ($cases as [$unit, $interval, $expected]) {
            $task = $this->recurring(['repeat_unit' => $unit, 'repeat_interval' => $interval, 'title' => "{$unit->value}-{$interval}"]);
            $task->toggleDone();

            $this->assertSame($expected, Task::where('title', "{$unit->value}-{$interval}")->where('id', '!=', $task->id)->firstOrFail()->due_date->toDateString(), $unit->value);
        }
    }

    public function test_month_ends_do_not_overflow(): void
    {
        $this->travelTo('2026-01-01 10:00:00');
        $task = $this->recurring(['due_date' => '2026-01-31', 'repeat_unit' => RepeatUnit::Month, 'repeat_interval' => 1]);

        $task->toggleDone();

        $this->assertSame('2026-02-28', $this->others($task)->firstOrFail()->due_date->toDateString());
    }

    public function test_schedule_mode_skips_dates_that_are_already_in_the_past(): void
    {
        $task = $this->recurring(['due_date' => '2026-09-01']);

        $task->toggleDone();

        $this->assertSame('2026-10-13', $this->others($task)->firstOrFail()->due_date->toDateString());
    }

    public function test_completion_mode_counts_from_the_day_it_was_finished(): void
    {
        $task = $this->recurring(['due_date' => '2026-09-01', 'repeat_mode' => RepeatMode::Completion, 'repeat_interval' => 2]);

        $task->toggleDone();

        $this->assertSame('2026-10-21', $this->others($task)->firstOrFail()->due_date->toDateString());
    }

    public function test_the_start_date_keeps_its_distance_to_the_due_date(): void
    {
        $task = $this->recurring(['start_date' => '2026-10-05']);

        $task->toggleDone();

        $next = $this->others($task)->firstOrFail();
        $this->assertSame('2026-10-12', $next->start_date->toDateString());
        $this->assertSame('2026-10-16', $next->due_date->toDateString());
    }

    public function test_the_series_ends_at_the_end_date(): void
    {
        $task = $this->recurring(['repeat_until' => '2026-10-15']);

        $task->toggleDone();

        $this->assertSame(0, $this->others($task)->count());
        $this->assertNull($task->fresh()->repeat_unit);
        $this->assertContains('hat die Wiederholung beendet (Enddatum erreicht)', $task->activities()->get()->map->sentence()->all());
    }

    public function test_the_last_allowed_date_still_counts(): void
    {
        $task = $this->recurring(['repeat_until' => '2026-10-16']);

        $task->toggleDone();

        $this->assertSame(1, $this->others($task)->count());
    }

    public function test_assignee_collaborators_tags_and_field_values_are_copied(): void
    {
        $person = User::factory()->create();
        $helper = User::factory()->create();
        $tag = Tag::factory()->for($this->project)->create();
        $priority = $this->project->customFields()->where('name', 'Priorität')->firstOrFail();
        $text = CustomField::factory()->for($this->project)->create(['name' => 'Kunde']);

        $task = $this->recurring(['assignee_id' => $person->id, 'description' => 'Bitte pünktlich']);
        $task->collaborators()->attach($helper);
        $task->tags()->attach($tag);
        $task->fieldValues()->create(['custom_field_id' => $priority->id, 'option_id' => $priority->options[2]->id]);
        $task->fieldValues()->create(['custom_field_id' => $text->id, 'value' => 'Müller AG']);

        $task->toggleDone();

        $next = $this->others($task)->firstOrFail();
        $this->assertSame($person->id, $next->assignee_id);
        $this->assertSame('Bitte pünktlich', $next->description);
        $this->assertSame([$helper->id], $next->collaborators()->pluck('users.id')->all());
        $this->assertSame([$tag->id], $next->tags()->pluck('tags.id')->all());
        $this->assertSame($priority->options[2]->id, $next->fieldValues()->where('custom_field_id', $priority->id)->firstOrFail()->option_id);
        $this->assertSame('Müller AG', $next->fieldValues()->where('custom_field_id', $text->id)->firstOrFail()->value);
    }

    public function test_comments_and_dependencies_are_not_copied(): void
    {
        $task = $this->recurring();
        $task->comments()->create(['user_id' => auth()->id(), 'body' => 'Alt']);
        $task->blockers()->attach(Task::factory()->for($this->project)->create());

        $task->toggleDone();

        $next = $this->others($task)->where('title', 'Wochenbericht')->firstOrFail();
        $this->assertSame(0, $next->comments()->count());
        $this->assertSame(0, $next->blockers()->count());
    }

    public function test_subtasks_and_headings_are_copied_open_with_moved_dates(): void
    {
        $task = $this->recurring();
        $heading = Task::factory()->for($this->project)->create(['parent_id' => $task->id, 'is_section' => true, 'title' => 'Vorbereitung', 'position' => 0]);
        $child = Task::factory()->for($this->project)->done()->create(['parent_id' => $task->id, 'title' => 'Zahlen holen', 'due_date' => '2026-10-08', 'position' => 1]);
        Task::factory()->for($this->project)->create(['parent_id' => $child->id, 'title' => 'Tief', 'position' => 0]);

        $task->toggleDone();

        $next = $this->others($task)->where('title', 'Wochenbericht')->firstOrFail();
        $copies = $next->children()->with('status')->get();

        $this->assertSame(['Vorbereitung', 'Zahlen holen'], $copies->pluck('title')->all());
        $this->assertTrue($copies[0]->is_section);
        $this->assertFalse($copies[1]->isDone());
        $this->assertSame('2026-10-15', $copies[1]->due_date->toDateString());
        $this->assertSame(['Tief'], $copies[1]->children()->pluck('title')->all());
        $this->assertNull($copies[1]->repeat_unit);
        $this->assertTrue($heading->fresh()->is_section);
    }

    public function test_it_works_from_the_list_the_board_and_the_task_page(): void
    {
        $viaList = $this->recurring(['title' => 'Liste']);
        Livewire::test('pages::projects.show', ['project' => $this->project])->call('toggleDone', $viaList->id);

        $viaBoard = $this->recurring(['title' => 'Board']);
        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('moveTask', $viaBoard->id, 0, (string) $this->project->doneStatus()->id);

        $viaPage = $this->recurring(['title' => 'Seite']);
        Livewire::test('pages::tasks.show', ['task' => $viaPage])
            ->set('statusId', (string) $this->project->doneStatus()->id)->call('save');

        foreach (['Liste', 'Board', 'Seite'] as $title) {
            $this->assertSame(2, Task::where('title', $title)->count(), $title);
        }
    }

    public function test_finishing_via_the_checkbox_notifies_with_the_right_statuses(): void
    {
        Notification::fake();
        $person = User::factory()->admin()->create();
        $task = $this->recurring(['assignee_id' => $person->id]);

        Livewire::test('pages::projects.show', ['project' => $this->project])->call('toggleDone', $task->id);

        Notification::assertSentTo($person, TaskStatusChanged::class, fn (TaskStatusChanged $notification) => $notification->oldStatus === 'Offen' && $notification->newStatus === 'Erledigt');
    }

    public function test_the_activity_log_tells_what_happened(): void
    {
        $task = $this->recurring();

        $task->toggleDone();

        $this->assertContains('hat die nächste Wiederholung für den 2026-10-16 angelegt', $task->activities()->get()->map->sentence()->all());
    }

    public function test_the_rule_can_be_set_and_removed_on_the_task_page(): void
    {
        $task = Task::factory()->for($this->project)->create(['due_date' => '2026-10-09']);

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('repeatUnit', 'month')->set('repeatInterval', '3')->set('repeatMode', 'completion')->set('repeatUntil', '2027-12-31')
            ->call('save')->assertHasNoErrors();

        $task->refresh();
        $this->assertSame(RepeatUnit::Month, $task->repeat_unit);
        $this->assertSame(3, $task->repeat_interval);
        $this->assertSame(RepeatMode::Completion, $task->repeat_mode);
        $this->assertSame('alle 3 Monate (nach Erledigung) bis 2027-12-31', $task->recurrenceLabel());

        Livewire::test('pages::tasks.show', ['task' => $task->fresh()])
            ->assertSet('repeatUnit', 'month')->assertSet('repeatInterval', '3')
            ->set('repeatUnit', '')->call('save')->assertHasNoErrors();

        $task->refresh();
        $this->assertNull($task->repeat_unit);
        $this->assertNull($task->repeat_until);

        $sentences = $task->activities()->get()->map->sentence()->all();
        $this->assertContains('hat die Wiederholung auf „alle 3 Monate (nach Erledigung) bis 2027-12-31“ gesetzt', $sentences);
        $this->assertContains('hat die Wiederholung entfernt', $sentences);
    }

    public function test_the_rule_needs_a_due_date_and_valid_values(): void
    {
        $task = Task::factory()->for($this->project)->create();

        Livewire::test('pages::tasks.show', ['task' => $task])
            ->set('repeatUnit', 'week')->call('save')->assertHasErrors('dueDate');

        $task->update(['due_date' => '2026-10-09']);

        foreach ([['repeatInterval', '0'], ['repeatInterval', '1000'], ['repeatInterval', 'oft'], ['repeatUnit', 'jahrzehnt'], ['repeatMode', 'egal']] as [$property, $value]) {
            Livewire::test('pages::tasks.show', ['task' => $task->fresh()])
                ->set('repeatUnit', 'week')->set($property, $value)->call('save')->assertHasErrors($property);
        }

        Livewire::test('pages::tasks.show', ['task' => $task->fresh()])
            ->set('repeatUnit', 'week')->set('repeatUntil', '2026-10-01')->call('save')->assertHasErrors('repeatUntil');
    }

    public function test_the_label_reads_naturally(): void
    {
        $label = fn (RepeatUnit $unit, int $interval) => (new Task(['repeat_unit' => $unit, 'repeat_interval' => $interval, 'repeat_mode' => RepeatMode::Schedule]))->recurrenceLabel();

        $this->assertSame('täglich', $label(RepeatUnit::Day, 1));
        $this->assertSame('wöchentlich', $label(RepeatUnit::Week, 1));
        $this->assertSame('monatlich', $label(RepeatUnit::Month, 1));
        $this->assertSame('jährlich', $label(RepeatUnit::Year, 1));
        $this->assertSame('alle 2 Wochen', $label(RepeatUnit::Week, 2));
        $this->assertNull((new Task)->recurrenceLabel());
    }

    public function test_recurring_tasks_are_marked_in_list_and_board(): void
    {
        $this->recurring();

        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('Wiederholt sich wöchentlich');
        $this->get(route('projects.board', $this->project))->assertOk()->assertSee('Wiederholt sich wöchentlich');
    }

    public function test_viewers_cannot_change_the_rule(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $task = Task::factory()->for($this->project)->create(['due_date' => '2026-10-09']);

        $this->actingAs($viewer);
        Livewire::test('pages::tasks.show', ['task' => $task])->set('repeatUnit', 'day')->call('save')->assertForbidden();

        $this->assertNull($task->fresh()->repeat_unit);
    }

    public function test_the_date_carbon_helpers_are_not_confused_by_the_clock(): void
    {
        $this->assertSame('2026-10-07', Carbon::now()->toDateString());
    }
}
