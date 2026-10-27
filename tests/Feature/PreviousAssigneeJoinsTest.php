<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
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
}
