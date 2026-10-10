<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Enums\CustomFieldType;
use App\Enums\ProjectRole;
use App\Models\Automation;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Notifications\TaskCommented;
use App\Notifications\TaskStatusChanged;
use App\Services\InboxTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private User $bernd;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 10:00:00');
        $this->project = Project::factory()->create();
        $this->owner = User::factory()->create(['name' => 'Olaf']);
        $this->bernd = User::factory()->create(['name' => 'Bernd']);
        $this->anna = User::factory()->create(['name' => 'Anna']);
        $this->project->setRole($this->owner, ProjectRole::Admin);
        $this->project->setRole($this->bernd, ProjectRole::Editor);
        $this->project->setRole($this->anna, ProjectRole::Editor);
    }

    /**
     * @param  list<array{type: AutomationAction, value: mixed}>  $actions
     * @param  array<string, mixed>  $attributes
     */
    private function rule(AutomationTrigger $trigger, ?int $value, array $actions, array $attributes = []): Automation
    {
        return Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'name' => 'Regel',
            'trigger' => $trigger,
            'trigger_value' => $value,
            'actions' => array_map(fn (array $action) => ['type' => $action['type']->value, 'value' => $action['value']], $actions),
            ...$attributes,
        ]);
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create($attributes);
    }

    private function done(): int
    {
        return $this->project->doneStatus()->id;
    }

    public function test_a_status_trigger_sets_the_assignee_and_records_it_under_the_rule_name(): void
    {
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id]], ['name' => 'Fertig an Anna']);
        $task = $this->task(['assignee_id' => null]);

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertSame($this->anna->id, $task->fresh()->assignee_id);

        $entry = $task->activities()->where('type', ActivityType::AssigneeChanged)->sole();
        $this->assertNull($entry->user_id);
        $this->assertNotNull($entry->automation_id);
        $this->assertSame('Automatisierung „Fertig an Anna“', $entry->actorName());
        $this->assertSame('Bernd', $entry->triggeredBy());

        $this->assertSame($this->bernd->id, $task->activities()->where('type', ActivityType::StatusChanged)->sole()->user_id);
    }

    public function test_the_task_that_triggered_the_rule_is_brought_up_to_date(): void
    {
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id], ['type' => AutomationAction::ShiftDueDate, 'value' => 3]]);
        $task = $this->task(['assignee_id' => null, 'due_date' => null]);

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertSame($this->anna->id, $task->assignee_id);
        $this->assertSame('2026-10-11', $task->due_date->toDateString());
        $this->assertSame([], $task->getDirty());
    }

    public function test_an_assignee_trigger_without_a_person_reacts_to_every_assignment(): void
    {
        $this->rule(AutomationTrigger::AssigneeChanged, null, [['type' => AutomationAction::AddTag, 'value' => $tag = Tag::factory()->for($this->project)->create(['name' => 'zugewiesen'])->id]]);
        $task = $this->task(['assignee_id' => null]);

        $this->actingAs($this->bernd);
        $task->update(['assignee_id' => $this->anna->id]);

        $this->assertSame([$tag], $task->tags()->pluck('tags.id')->all());
        $this->assertSame(['zugewiesen'], $task->activities()->where('type', ActivityType::TagsAdded)->sole()->data['names']);
    }

    public function test_a_tag_trigger_reacts_when_the_tag_is_added_and_to_no_other_tag(): void
    {
        $bug = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        $other = Tag::factory()->for($this->project)->create(['name' => 'Idee']);
        $this->rule(AutomationTrigger::TagAdded, $bug->id, [['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id]]);
        $first = $this->task();
        $second = $this->task();

        $this->actingAs($this->bernd);
        $first->logSyncChanges(ActivityType::TagsAdded, ActivityType::TagsRemoved, $first->tags()->sync([$other->id]), fn () => ['Idee']);
        $second->logSyncChanges(ActivityType::TagsAdded, ActivityType::TagsRemoved, $second->tags()->sync([$bug->id]), fn () => ['Bug']);

        $this->assertNotSame($this->anna->id, $first->fresh()->assignee_id);
        $this->assertSame($this->anna->id, $second->fresh()->assignee_id);
    }

    public function test_a_field_trigger_reacts_when_the_select_field_gets_the_value(): void
    {
        $priority = $this->project->customFields()->where('name', 'Priorität')->firstOrFail();
        $urgent = $priority->options->firstWhere('name', 'Dringend');
        $high = $priority->options->firstWhere('name', 'Hoch');
        $this->rule(AutomationTrigger::FieldSet, $priority->id, [['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id]], ['name' => 'Eilt', 'trigger_field_value' => (string) $urgent->id]);
        $other = $this->task();
        $task = $this->task();

        $this->actingAs($this->bernd);
        $other->setFieldValue($priority, ['option_id' => $high->id, 'value' => null]);
        $task->setFieldValue($priority, ['option_id' => $urgent->id, 'value' => null]);

        $this->assertNotSame($this->anna->id, $other->fresh()->assignee_id);
        $this->assertSame($this->anna->id, $task->assignee_id);
        $entry = $task->activities()->where('type', ActivityType::AssigneeChanged)->sole();
        $this->assertNull($entry->user_id);
        $this->assertSame('Eilt', $entry->data['automation']);

        // Setting it again, to another value or clearing it does not fire once more
        $task->update(['assignee_id' => $this->bernd->id]);
        $task->setFieldValue($priority, ['option_id' => $urgent->id, 'value' => null]);
        $task->setFieldValue($priority, ['option_id' => $high->id, 'value' => null]);
        $task->setFieldValue($priority, null);
        $this->assertSame($this->bernd->id, $task->fresh()->assignee_id);
    }

    public function test_a_field_trigger_compares_text_number_and_date_by_their_kind(): void
    {
        $customer = CustomField::factory()->for($this->project)->create(['name' => 'Kundenname', 'type' => CustomFieldType::Text]);
        $effort = CustomField::factory()->for($this->project)->create(['name' => 'Aufwand', 'type' => CustomFieldType::Number]);
        $review = CustomField::factory()->for($this->project)->create(['name' => 'Review am', 'type' => CustomFieldType::Date]);
        $comment = fn (string $text) => [['type' => AutomationAction::Comment, 'value' => $text]];
        $this->rule(AutomationTrigger::FieldSet, $customer->id, $comment('Kunde'), ['trigger_field_value' => 'Müller AG']);
        $this->rule(AutomationTrigger::FieldSet, $effort->id, $comment('Aufwand'), ['trigger_field_value' => '8']);
        $this->rule(AutomationTrigger::FieldSet, $review->id, $comment('Review'), ['trigger_field_value' => '2026-12-24']);
        $this->rule(AutomationTrigger::FieldSet, $customer->id, $comment('Irgendein Kunde'));
        $task = $this->task();
        $set = fn (CustomField $field, string $value) => $task->setFieldValue($field, ['option_id' => null, 'value' => $value]);

        $this->actingAs($this->bernd);
        $set($customer, 'Meier GmbH');
        $set($effort, '8.5');
        $set($review, '2026-12-23');
        $this->assertSame(['Irgendein Kunde'], $task->comments()->pluck('body')->all());

        $set($customer, '  müller ag ');
        $set($effort, '8.0');
        $set($review, '2026-12-24');
        $task->setFieldValue($customer, null);

        $this->assertSame(['Irgendein Kunde', 'Kunde', 'Irgendein Kunde', 'Aufwand', 'Review'], $task->comments()->orderBy('id')->pluck('body')->all());
    }

    public function test_a_field_trigger_on_the_task_page_shows_what_the_rule_changed_and_a_second_save_keeps_it(): void
    {
        $priority = $this->project->customFields()->where('name', 'Priorität')->firstOrFail();
        $urgent = $priority->options->firstWhere('name', 'Dringend');
        $review = $this->project->statuses()->create(['name' => 'Review', 'color' => 'amber', 'position' => 5]);
        $this->rule(AutomationTrigger::FieldSet, $priority->id, [['type' => AutomationAction::SetStatus, 'value' => $review->id], ['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id]], ['trigger_field_value' => (string) $urgent->id]);
        $task = $this->task(['assignee_id' => null]);

        $page = Livewire::actingAs($this->bernd)->test('pages::tasks.show', ['task' => $task])
            ->set("fieldValues.{$priority->id}", (string) $urgent->id)
            ->call('save')->assertHasNoErrors()
            ->assertSet('statusId', (string) $review->id)
            ->assertSet('assigneeId', (string) $this->anna->id);

        $page->set('title', 'Neuer Titel')->call('save')->assertHasNoErrors();

        $task->refresh();
        $this->assertSame($review->id, $task->status_id);
        $this->assertSame($this->anna->id, $task->assignee_id);
    }

    public function test_conditions_must_hold_after_the_change(): void
    {
        $bug = Tag::factory()->for($this->project)->create();
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id]], ['conditions' => ['tag_id' => $bug->id]]);
        $plain = $this->task();
        $tagged = $this->task();
        $tagged->tags()->attach($bug);

        $this->actingAs($this->bernd);
        $plain->update(['status_id' => $this->done()]);
        $tagged->update(['status_id' => $this->done()]);

        $this->assertNull($plain->fresh()->assignee_id);
        $this->assertSame($this->anna->id, $tagged->fresh()->assignee_id);
    }

    public function test_rules_do_not_trigger_each_other(): void
    {
        $tag = Tag::factory()->for($this->project)->create();
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::AddTag, 'value' => $tag->id]]);
        $this->rule(AutomationTrigger::TagAdded, $tag->id, [['type' => AutomationAction::Comment, 'value' => 'Kette']]);
        $task = $this->task();

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertSame(1, $task->tags()->count());
        $this->assertSame(0, $task->comments()->count());
    }

    public function test_the_other_actions_set_status_tag_due_date_and_write_a_comment_in_the_rules_name(): void
    {
        Notification::fake();
        $review = $this->project->statuses()->where('is_done', false)->get()->last();
        $tag = Tag::factory()->for($this->project)->create(['name' => 'geprüft']);
        $this->rule(AutomationTrigger::AssigneeChanged, $this->anna->id, [
            ['type' => AutomationAction::SetStatus, 'value' => $review->id],
            ['type' => AutomationAction::AddTag, 'value' => $tag->id],
            ['type' => AutomationAction::ShiftDueDate, 'value' => 7],
            ['type' => AutomationAction::Comment, 'value' => 'Bitte prüfen'],
        ], ['name' => 'Übergabe']);
        $task = $this->task(['assignee_id' => $this->bernd->id, 'due_date' => '2026-10-10']);
        $task->collaborators()->attach($this->bernd);

        $this->actingAs($this->owner);
        $task->update(['assignee_id' => $this->anna->id]);

        $task->refresh();
        $this->assertSame($review->id, $task->status_id);
        $this->assertSame('2026-10-17', $task->due_date->toDateString());
        $this->assertTrue($task->tags->contains($tag));

        $comment = $task->comments()->sole();
        $this->assertNull($comment->user_id);
        $this->assertSame('Automatisierung „Übergabe“', $comment->authorName());
        Notification::assertSentTo($this->bernd, TaskCommented::class, fn (TaskCommented $notification) => $notification->comment->is($comment));
    }

    public function test_status_notifications_name_the_rule_and_skip_the_person_who_triggered_it(): void
    {
        Notification::fake();
        $review = $this->project->statuses()->where('is_done', false)->get()->last();
        $this->rule(AutomationTrigger::AssigneeChanged, $this->anna->id, [['type' => AutomationAction::SetStatus, 'value' => $review->id]], ['name' => 'Übergabe']);
        $task = $this->task(['assignee_id' => $this->bernd->id]);
        $task->collaborators()->attach($this->bernd);

        $this->actingAs($this->anna);
        $task->update(['assignee_id' => $this->anna->id]);

        Notification::assertSentTo($this->bernd, TaskStatusChanged::class, fn (TaskStatusChanged $notification) => $notification->changedBy === 'Automatisierung „Übergabe“' && $notification->newStatus === $review->name);
        Notification::assertNotSentTo($this->anna, TaskStatusChanged::class);
    }

    public function test_notify_puts_an_entry_in_the_inbox_of_another_person_only(): void
    {
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [
            ['type' => AutomationAction::Notify, 'value' => $this->anna->id],
            ['type' => AutomationAction::Notify, 'value' => $this->bernd->id],
        ], ['name' => 'Bescheid']);
        $task = $this->task();

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertSame(0, $this->bernd->notifications()->where('type', AutomationNotice::class)->count());
        $notice = $this->anna->notifications()->where('type', AutomationNotice::class)->sole();
        $this->assertSame($task->id, $notice->data['task_id']);
        $this->assertSame('Automatisierung „Bescheid“ hat diese Aufgabe für dich markiert', InboxTextService::sentence($notice));
    }

    public function test_people_without_access_to_the_project_are_not_assigned_or_notified(): void
    {
        $outsider = User::factory()->create();
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [
            ['type' => AutomationAction::SetAssignee, 'value' => $outsider->id],
            ['type' => AutomationAction::Notify, 'value' => $outsider->id],
        ]);
        $task = $this->task();

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertNull($task->fresh()->assignee_id);
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_values_from_another_project_are_ignored(): void
    {
        $foreign = Project::factory()->create();
        $foreignTag = Tag::factory()->for($foreign)->create();
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [
            ['type' => AutomationAction::AddTag, 'value' => $foreignTag->id],
            ['type' => AutomationAction::SetStatus, 'value' => $foreign->defaultStatus()->id],
        ]);
        $task = $this->task();

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertSame(0, $task->tags()->count());
        $this->assertSame($this->done(), $task->fresh()->status_id);
    }

    public function test_a_rule_switches_itself_off_when_its_creator_may_no_longer_edit(): void
    {
        $rule = $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id]]);
        $this->project->setRole($this->owner, ProjectRole::Viewer);
        $task = $this->task();

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertNull($task->fresh()->assignee_id);
        $this->assertFalse($rule->fresh()->enabled);
    }

    public function test_disabled_rules_and_rules_of_other_projects_do_not_run(): void
    {
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id]], ['enabled' => false]);
        $other = Project::factory()->create();
        $other->setRole($this->owner, ProjectRole::Admin);
        Automation::factory()->create([
            'project_id' => $other->id,
            'created_by' => $this->owner->id,
            'trigger' => AutomationTrigger::StatusChanged,
            'trigger_value' => $this->done(),
            'actions' => [['type' => AutomationAction::SetAssignee->value, 'value' => $this->anna->id]],
        ]);
        $task = $this->task();

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_a_broken_action_does_not_stop_the_save_or_the_next_actions(): void
    {
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [
            ['type' => AutomationAction::ShiftDueDate, 'value' => 'abc'],
            ['type' => AutomationAction::Comment, 'value' => 'Weiter'],
        ]);
        $task = $this->task();

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->assertSame($this->done(), $task->fresh()->status_id);
        $this->assertSame(1, $task->comments()->count());
    }

    public function test_the_task_page_shows_the_rule_as_author_with_the_person_who_triggered_it(): void
    {
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [
            ['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id],
            ['type' => AutomationAction::Comment, 'value' => 'Danke fürs Erledigen'],
        ], ['name' => 'Fertig an Anna']);
        $task = $this->task(['assignee_id' => null]);

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);

        $this->get(route('tasks.show', $task))->assertOk()
            ->assertSee('Automatisierung „Fertig an Anna“', false)
            ->assertSee('Danke fürs Erledigen')
            ->assertSee('ausgelöst durch Bernd');

        Livewire::test('pages::tasks.show', ['task' => $task])->assertOk();
    }

    public function test_the_entry_keeps_the_name_when_the_rule_is_deleted(): void
    {
        $rule = $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::SetAssignee, 'value' => $this->anna->id]], ['name' => 'Alte Regel']);
        $task = $this->task();

        $this->actingAs($this->bernd);
        $task->update(['status_id' => $this->done()]);
        $rule->delete();

        $entry = $task->activities()->where('type', ActivityType::AssigneeChanged)->sole();
        $this->assertNull($entry->automation_id);
        $this->assertSame('Automatisierung „Alte Regel“', $entry->actorName());
    }

    public function test_moving_the_due_date_back_keeps_the_start_before_it(): void
    {
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::ShiftDueDate, 'value' => -5]]);
        $task = $this->task(['start_date' => '2026-10-12', 'due_date' => '2026-10-15']);

        $this->actingAs($this->owner);
        $task->update(['status_id' => $this->done()]);

        $task->refresh();
        $this->assertSame('2026-10-10', $task->due_date->toDateString());
        $this->assertSame('2026-10-10', $task->start_date->toDateString());
    }

    public function test_a_task_without_due_date_is_due_the_given_days_from_today(): void
    {
        $this->rule(AutomationTrigger::StatusChanged, $this->done(), [['type' => AutomationAction::ShiftDueDate, 'value' => 3]]);
        $task = $this->task(['start_date' => null, 'due_date' => null]);

        $this->actingAs($this->owner);
        $task->update(['status_id' => $this->done()]);

        $this->assertSame('2026-10-11', $task->fresh()->due_date->toDateString());
    }

    public function test_rules_for_other_changes_cost_a_single_query(): void
    {
        $this->rule(AutomationTrigger::TagAdded, Tag::factory()->for($this->project)->create()->id, [['type' => AutomationAction::Comment, 'value' => 'x']]);
        $task = $this->task();
        $this->actingAs($this->owner);

        DB::enableQueryLog();
        $task->update(['status_id' => $this->done()]);
        $automationQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], '"automations"'));

        $this->assertCount(1, $automationQueries);
        $this->assertStringContainsString('"trigger" = ?', $automationQueries->first()['query']);
    }
}
