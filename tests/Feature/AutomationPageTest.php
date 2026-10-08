<?php

namespace Tests\Feature;

use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Enums\ProjectRole;
use App\Models\Automation;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationPageTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $manager;

    private User $editor;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['name' => 'Webseite']);
        $this->manager = User::factory()->create(['name' => 'Olaf']);
        $this->editor = User::factory()->create();
        $this->anna = User::factory()->create(['name' => 'Anna']);
        $this->project->setRole($this->manager, ProjectRole::Admin);
        $this->project->setRole($this->editor, ProjectRole::Editor);
        $this->project->setRole($this->anna, ProjectRole::Editor);
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->manager)->test('pages::projects.automations', ['project' => $this->project]);
    }

    private function fill(Testable $page, array $actions, string $trigger = 'status_changed', ?string $triggerValue = null): Testable
    {
        return $page->set('name', 'Übergabe')->set('trigger', $trigger)->set('triggerValue', $triggerValue ?? (string) $this->project->doneStatus()->id)->set('actions', $actions);
    }

    public function test_only_managers_open_the_page(): void
    {
        $this->actingAs($this->manager)->get(route('projects.automations', $this->project))->assertOk()->assertSee('Automatisierungen');
        $this->actingAs($this->editor)->get(route('projects.automations', $this->project))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('projects.automations', $this->project))->assertForbidden();
    }

    public function test_the_project_menu_links_to_the_page_for_managers(): void
    {
        $this->actingAs($this->manager)->get(route('projects.show', $this->project))->assertOk()->assertSee(route('projects.automations', $this->project), false);
        $this->actingAs($this->editor)->get(route('projects.show', $this->project))->assertOk()->assertDontSee(route('projects.automations', $this->project), false);
    }

    public function test_a_manager_creates_a_rule(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Bug']);

        $page = $this->page()->call('openForm');
        $this->fill($page, [
            ['type' => 'set_assignee', 'value' => (string) $this->anna->id],
            ['type' => 'add_tag', 'value' => (string) $tag->id],
            ['type' => 'shift_due_date', 'value' => '-2'],
            ['type' => 'comment', 'value' => '  Bitte prüfen  '],
            ['type' => 'notify', 'value' => (string) $this->editor->id],
        ])->set('conditionTag', (string) $tag->id)->call('save')->assertHasNoErrors();

        $rule = Automation::query()->sole();
        $this->assertSame($this->project->id, $rule->project_id);
        $this->assertSame($this->manager->id, $rule->created_by);
        $this->assertSame(AutomationTrigger::StatusChanged, $rule->trigger);
        $this->assertSame($this->project->doneStatus()->id, $rule->trigger_value);
        $this->assertSame(['tag_id' => $tag->id], $rule->conditions);
        $this->assertEquals([
            ['type' => 'set_assignee', 'value' => $this->anna->id],
            ['type' => 'add_tag', 'value' => $tag->id],
            ['type' => 'shift_due_date', 'value' => -2],
            ['type' => 'comment', 'value' => 'Bitte prüfen'],
            ['type' => 'notify', 'value' => $this->editor->id],
        ], $rule->actions);
    }

    public function test_the_list_describes_each_rule_in_words(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->manager->id,
            'name' => 'Übergabe',
            'trigger' => AutomationTrigger::TagAdded,
            'trigger_value' => $tag->id,
            'conditions' => ['assignee_id' => $this->anna->id],
            'actions' => [
                ['type' => AutomationAction::SetStatus->value, 'value' => $this->project->doneStatus()->id],
                ['type' => AutomationAction::ShiftDueDate->value, 'value' => 7],
                ['type' => AutomationAction::SetAssignee->value, 'value' => null],
            ],
        ]);

        $this->page()
            ->assertSee('Übergabe')
            ->assertSee('Wenn das Tag „Bug“ hinzugefügt wird')
            ->assertSee('die zuständige Person Anna ist')
            ->assertSee('Status auf „'.$this->project->doneStatus()->name.'“ setzen')
            ->assertSee('Fälligkeit um 7 Tage verschieben')
            ->assertSee('Zuständige Person entfernen')
            ->assertSee('Handelt mit den Rechten von Olaf');
    }

    public function test_values_that_do_not_belong_to_the_project_are_rejected(): void
    {
        $foreign = Project::factory()->create();
        $foreignTag = Tag::factory()->for($foreign)->create();
        $outsider = User::factory()->create();

        $page = $this->page()->call('openForm');
        $this->fill($page, [['type' => 'add_tag', 'value' => (string) $foreignTag->id]], 'status_changed', (string) $foreign->defaultStatus()->id)
            ->set('conditionAssignee', (string) $outsider->id)
            ->call('save')
            ->assertHasErrors(['triggerValue', 'conditionAssignee', 'actions.0.value']);

        $this->fill($page, [['type' => 'notify', 'value' => (string) $outsider->id]])->call('save')->assertHasErrors(['actions.0.value']);

        $this->assertSame(0, Automation::query()->count());
    }

    public function test_every_part_is_checked(): void
    {
        $page = $this->page()->call('openForm');

        $page->set('name', '')->set('actions', [])->call('save')->assertHasErrors(['name', 'actions']);
        $this->fill($page, [['type' => 'shift_due_date', 'value' => '0']])->call('save')->assertHasErrors(['actions.0.value']);
        $this->fill($page, [['type' => 'shift_due_date', 'value' => '400']])->call('save')->assertHasErrors(['actions.0.value']);
        $this->fill($page, [['type' => 'shift_due_date', 'value' => 'x']])->call('save')->assertHasErrors(['actions.0.value']);
        $this->fill($page, [['type' => 'comment', 'value' => ' ']])->call('save')->assertHasErrors(['actions.0.value']);
        $this->fill($page, [['type' => 'delete_everything', 'value' => '1']])->call('save')->assertHasErrors(['actions.0.type']);
        $this->fill($page, [['type' => 'set_assignee', 'value' => '']], 'status_changed', '')->call('save')->assertHasErrors(['triggerValue']);

        $this->assertSame(0, Automation::query()->count());
    }

    public function test_an_assignee_trigger_may_stay_open_and_a_rule_may_remove_the_assignee(): void
    {
        $page = $this->page()->call('openForm');
        $this->fill($page, [['type' => 'set_assignee', 'value' => '']], 'assignee_changed', '')->call('save')->assertHasNoErrors();

        $rule = Automation::query()->sole();
        $this->assertNull($rule->trigger_value);
        $this->assertSame([['type' => 'set_assignee', 'value' => null]], $rule->actions);
    }

    public function test_editing_loads_the_rule_and_makes_the_editor_its_author(): void
    {
        $rule = Automation::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->anna->id,
            'name' => 'Alt',
            'trigger' => AutomationTrigger::StatusChanged,
            'trigger_value' => $this->project->doneStatus()->id,
            'actions' => [['type' => AutomationAction::ShiftDueDate->value, 'value' => 3]],
        ]);

        $this->page()
            ->call('openForm', $rule->id)
            ->assertSet('name', 'Alt')
            ->assertSet('triggerValue', (string) $this->project->doneStatus()->id)
            ->assertSet('actions', [['type' => 'shift_due_date', 'value' => '3']])
            ->set('name', 'Neu')
            ->call('save')
            ->assertHasNoErrors();

        $rule->refresh();
        $this->assertSame('Neu', $rule->name);
        $this->assertSame($this->manager->id, $rule->created_by);
    }

    public function test_changing_the_action_type_clears_its_value(): void
    {
        $this->page()->call('openForm')
            ->set('actions.0.value', (string) $this->anna->id)
            ->set('actions.0.type', 'comment')
            ->assertSet('actions.0.value', '');
    }

    public function test_switching_a_rule_on_again_hands_it_to_the_one_who_does_it(): void
    {
        $rule = Automation::factory()->disabled()->create(['project_id' => $this->project->id, 'created_by' => $this->anna->id]);

        $page = $this->page()->call('toggle', $rule->id);
        $this->assertTrue($rule->fresh()->enabled);
        $this->assertSame($this->manager->id, $rule->fresh()->created_by);

        $page->call('toggle', $rule->id);
        $this->assertFalse($rule->fresh()->enabled);
    }

    public function test_a_rule_is_deleted_after_confirmation(): void
    {
        $rule = Automation::factory()->create(['project_id' => $this->project->id]);

        $this->page()->call('confirmDelete', $rule->id)->assertSet('deletingId', (string) $rule->id)->call('delete');

        $this->assertModelMissing($rule);
    }

    public function test_rules_of_other_projects_cannot_be_touched(): void
    {
        $other = Automation::factory()->create();

        $this->page()->call('openForm', $other->id)->assertNotFound();
        $this->page()->call('toggle', $other->id)->assertNotFound();
        $this->page()->call('confirmDelete', $other->id)->assertNotFound();

        $this->assertModelExists($other);
    }

    public function test_editors_cannot_call_the_actions(): void
    {
        $rule = Automation::factory()->create(['project_id' => $this->project->id]);

        Livewire::actingAs($this->editor)->test('pages::projects.automations', ['project' => $this->project])->assertForbidden();
        $this->assertModelExists($rule);
    }
}
