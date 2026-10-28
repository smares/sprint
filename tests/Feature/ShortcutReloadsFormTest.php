<?php

namespace Tests\Feature;

use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Enums\ProjectRole;
use App\Models\Automation;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShortcutReloadsFormTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $anna;

    private User $rita;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->anna = User::factory()->create();
        $this->rita = User::factory()->create();
        $this->project->setRole($this->anna, ProjectRole::Editor);
        $this->project->setRole($this->rita, ProjectRole::Editor);
        $this->task = Task::factory()->for($this->project)->create(['assignee_id' => $this->anna->id]);
        $this->actingAs($this->anna);
    }

    private function ruleAssigningRita(): void
    {
        Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->anna->id,
            'name' => 'Review',
            'trigger' => AutomationTrigger::StatusChanged,
            'trigger_value' => $this->project->doneStatus()->id,
            'actions' => [['type' => AutomationAction::SetAssignee->value, 'value' => $this->rita->id]],
        ]);
    }

    public function test_the_page_is_told_when_a_rule_changed_the_task_and_loads_the_form_anew_on_request(): void
    {
        $this->ruleAssigningRita();

        $page = Livewire::test('pages::tasks.show', ['task' => $this->task])->call('toggleDoneByShortcut');

        $page->assertDispatched('task-form-outdated')->assertSet('assigneeId', (string) $this->anna->id);

        $page->call('reloadForm')->assertSet('assigneeId', (string) $this->rita->id);
    }

    public function test_nothing_is_asked_when_no_rule_changed_anything(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('toggleDoneByShortcut')->assertNotDispatched('task-form-outdated');
    }

    public function test_reloading_drops_what_was_typed(): void
    {
        $page = Livewire::test('pages::tasks.show', ['task' => $this->task])->set('title', 'Getippt')->set('assigneeId', (string) $this->rita->id);

        $page->call('reloadForm')->assertSet('title', $this->task->title)->assertSet('assigneeId', (string) $this->anna->id);
    }

    public function test_the_question_says_what_cancelling_means(): void
    {
        $this->ruleAssigningRita();

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('toggleDoneByShortcut')
            ->assertDispatched('task-form-outdated', question: __('A rule has changed this task. Reload the form and discard unsaved changes? If you cancel, saving undoes the rule.'));
    }

    public function test_a_rule_that_only_comments_does_not_ask(): void
    {
        Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->anna->id,
            'name' => 'Kommentar',
            'trigger' => AutomationTrigger::StatusChanged,
            'trigger_value' => $this->project->doneStatus()->id,
            'actions' => [['type' => AutomationAction::Comment->value, 'value' => 'Erledigt']],
        ]);

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('toggleDoneByShortcut')->assertNotDispatched('task-form-outdated');
    }

    public function test_taking_the_task_with_m_asks_when_a_rule_reacts_to_the_new_assignee(): void
    {
        $tag = Tag::factory()->create(['project_id' => $this->project->id]);
        Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->anna->id,
            'name' => 'Etikett',
            'trigger' => AutomationTrigger::AssigneeChanged,
            'trigger_value' => $this->rita->id,
            'actions' => [['type' => AutomationAction::AddTag->value, 'value' => $tag->id]],
        ]);
        $this->actingAs($this->rita);

        $page = Livewire::test('pages::tasks.show', ['task' => $this->task])->call('assignToMeByShortcut');

        $page->assertDispatched('task-form-outdated')->assertSet('tagIds', []);
        $page->call('reloadForm')->assertSet('tagIds', [(string) $tag->id])->assertSet('assigneeId', (string) $this->rita->id);
    }

    public function test_taking_the_task_with_m_asks_nothing_without_a_rule(): void
    {
        $this->actingAs($this->rita);

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('assignToMeByShortcut')->assertNotDispatched('task-form-outdated');
    }

    public function test_taking_the_task_with_m_asks_when_a_rule_hands_it_on(): void
    {
        $other = User::factory()->create();
        $this->project->setRole($other, ProjectRole::Editor);
        Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->anna->id,
            'name' => 'Weiter',
            'trigger' => AutomationTrigger::AssigneeChanged,
            'trigger_value' => $this->rita->id,
            'actions' => [['type' => AutomationAction::SetAssignee->value, 'value' => $other->id]],
        ]);
        $this->actingAs($this->rita);

        Livewire::test('pages::tasks.show', ['task' => $this->task])->call('assignToMeByShortcut')->assertDispatched('task-form-outdated');
    }
}
