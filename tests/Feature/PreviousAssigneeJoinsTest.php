<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Mcp\Servers\SprintServer;
use App\Mcp\Tools\UpdateTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PreviousAssigneeJoinsTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $ben;

    private User $carla;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->anna, $this->ben, $this->carla] = User::factory()->count(3)->create()->all();
        $this->project = Project::factory()->create();

        foreach ([$this->anna, $this->ben, $this->carla] as $person) {
            $this->project->setRole($person, ProjectRole::Editor);
        }

        $this->task = Task::factory()->for($this->project)->create(['creator_id' => $this->carla->id, 'assignee_id' => $this->anna->id]);
        $this->task->collaborators()->detach();
    }

    private function collaboratorIds(): array
    {
        return $this->task->collaborators()->pluck('users.id')->all();
    }

    public function test_the_person_who_hands_the_task_over_becomes_a_collaborator_without_an_entry_about_it(): void
    {
        $this->actingAs($this->ben);

        $this->task->assignTo($this->ben);

        $this->assertSame($this->ben->id, $this->task->fresh()->assignee_id);
        $this->assertSame([$this->anna->id], $this->collaboratorIds());
        $this->assertSame(0, $this->task->activities()->whereIn('type', ['collaborators_added', 'collaborators_removed'])->count());
    }

    public function test_it_also_works_from_one_person_to_another_and_in_the_flyout_form(): void
    {
        $this->actingAs($this->anna);

        $this->task->assignTo($this->carla);
        $this->assertSame([$this->anna->id], $this->collaboratorIds());

        $page = Livewire::test('pages::tasks.show', ['task' => $this->task->fresh()]);
        $page->set('assigneeId', (string) $this->ben->id)->call('save')->assertHasNoErrors();

        $this->assertEqualsCanonicalizing([$this->anna->id, $this->carla->id], $this->collaboratorIds());
        $this->assertSame(0, $this->task->activities()->where('type', 'collaborators_removed')->count());
    }

    public function test_the_form_keeps_the_previous_assignee_when_it_hands_the_task_over(): void
    {
        $this->actingAs($this->ben);

        Livewire::test('pages::tasks.show', ['task' => $this->task])->set('assigneeId', (string) $this->ben->id)->call('save')->assertHasNoErrors();

        $this->assertSame([$this->anna->id], $this->collaboratorIds());
    }

    public function test_unassigning_imports_and_people_without_access_add_nobody(): void
    {
        $this->actingAs($this->ben);
        $this->task->assignTo(null);
        $this->assertSame([], $this->collaboratorIds());

        $outsider = User::factory()->create();
        $withoutAccess = Task::factory()->for($this->project)->create(['creator_id' => $this->carla->id, 'assignee_id' => $outsider->id]);
        $withoutAccess->collaborators()->detach();
        $withoutAccess->assignTo($this->ben);
        $this->assertSame([], $withoutAccess->collaborators()->pluck('users.id')->all());

        $this->task->assignTo($this->anna);
        $this->task->collaborators()->detach();

        Task::withoutAssignmentNotices(fn () => $this->task->assignTo($this->ben));
        $this->assertSame([], $this->collaboratorIds());

        $this->task->assignTo($this->anna);
        $this->anna->update(['deactivated_at' => now()]);
        $this->task->assignTo($this->ben);
        $this->assertSame([], $this->collaboratorIds());
    }

    public function test_bulk_and_agent_changes_use_the_same_rule(): void
    {
        $this->actingAs($this->ben);

        $this->task->updateAssigning(['assignee_id' => $this->carla->id]);

        $this->assertSame([$this->anna->id], $this->collaboratorIds());
    }

    public function test_saving_the_form_twice_does_not_undo_it_and_the_history_stays_quiet(): void
    {
        $this->actingAs($this->ben);

        $page = Livewire::test('pages::tasks.show', ['task' => $this->task])->set('assigneeId', (string) $this->ben->id)->call('save')->assertHasNoErrors();
        $page->assertSet('collaboratorIds', [(string) $this->anna->id]);

        $page->set('title', 'Anders')->call('save')->assertHasNoErrors();

        $this->assertSame([$this->anna->id], $this->collaboratorIds());
        $this->assertSame(0, $this->task->activities()->where('type', 'collaborators_removed')->count());
    }

    public function test_taking_a_task_with_the_shortcut_and_saving_keeps_the_previous_assignee(): void
    {
        $this->actingAs($this->ben);

        $page = Livewire::test('pages::tasks.show', ['task' => $this->task])->call('assignToMeByShortcut');
        $page->set('title', 'Anders')->call('save')->assertHasNoErrors();

        $this->assertSame([$this->anna->id], $this->collaboratorIds());
        $this->assertSame(0, $this->task->activities()->where('type', 'collaborators_removed')->count());
    }

    public function test_going_back_and_forth_leaves_no_entry_about_removals(): void
    {
        $this->actingAs($this->ben);

        $this->task->assignTo($this->ben);
        $this->task->assignTo($this->anna);

        $this->assertSame([$this->ben->id], $this->collaboratorIds());
        $this->assertSame(0, $this->task->activities()->where('type', 'collaborators_removed')->count());
    }

    public function test_the_list_menu_hands_a_task_over_with_the_same_rule(): void
    {
        $this->actingAs($this->ben);

        Livewire::test('pages::projects.show', ['project' => $this->project])->call('assign', $this->task->id, $this->ben->id);

        $this->assertSame([$this->anna->id], $this->collaboratorIds());
    }

    public function test_an_agent_that_names_the_collaborators_does_not_lose_the_previous_assignee(): void
    {
        $call = SprintServer::actingAs($this->ben)->tool(UpdateTask::class, ['task_id' => $this->task->id, 'assignee' => $this->ben->email, 'collaborators' => [$this->carla->email]]);

        $call->assertOk();
        $this->assertEqualsCanonicalizing([$this->anna->id, $this->carla->id], $this->collaboratorIds());
        $this->assertSame(0, $this->task->activities()->where('type', 'collaborators_removed')->count());
    }

    public function test_the_shortcut_keeps_what_was_changed_in_the_list_and_not_saved_yet(): void
    {
        $this->actingAs($this->ben);

        $page = Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('collaboratorIds', [(string) $this->carla->id, (string) $this->ben->id])
            ->call('assignToMeByShortcut');

        $page->assertSet('collaboratorIds', [(string) $this->carla->id, (string) $this->anna->id]);
    }
}
