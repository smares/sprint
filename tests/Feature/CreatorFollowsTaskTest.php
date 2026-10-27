<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Mcp\Servers\SprintServer;
use App\Mcp\Tools\CreateTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreatorFollowsTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $ben;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = User::factory()->create();
        $this->ben = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->anna, ProjectRole::Editor);
        $this->project->setRole($this->ben, ProjectRole::Editor);
    }

    private function createdBy(User $creator, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create(['creator_id' => $creator->id] + $attributes);
    }

    public function test_the_creator_of_a_task_for_somebody_else_becomes_a_collaborator_and_hears_about_it(): void
    {
        $task = $this->createdBy($this->anna, ['assignee_id' => $this->ben->id]);

        $this->assertTrue($task->collaborators()->whereKey($this->anna->id)->exists());
        $this->assertEqualsCanonicalizing([$this->anna->id, $this->ben->id], $task->usersToNotify()->pluck('id')->all());
        $this->assertTrue(Task::assignedToOrCollaboratingOn($this->anna)->whereKey($task->id)->exists());
        $this->assertSame(0, $task->activities()->where('type', 'collaborators_added')->count());
    }

    public function test_the_setting_can_be_turned_off(): void
    {
        $this->anna->update(['join_own_tasks' => false]);

        $task = $this->createdBy($this->anna, ['assignee_id' => $this->ben->id]);

        $this->assertFalse($task->collaborators()->whereKey($this->anna->id)->exists());
    }

    public function test_it_is_on_for_new_people(): void
    {
        $this->assertTrue(User::factory()->create()->fresh()->join_own_tasks);
    }

    public function test_a_task_one_takes_oneself_a_section_and_a_copy_do_not_add_anybody(): void
    {
        $own = $this->createdBy($this->anna, ['assignee_id' => $this->anna->id]);
        $section = $this->createdBy($this->anna, ['is_section' => true]);
        $original = $this->createdBy($this->ben, ['assignee_id' => $this->ben->id]);

        $this->actingAs($this->anna);
        $copy = $original->duplicate();

        $this->assertFalse($own->collaborators()->exists());
        $this->assertFalse($section->collaborators()->exists());
        $this->assertFalse($copy->collaborators()->whereKey($this->anna->id)->exists());
    }

    public function test_a_task_created_in_the_dialog_is_followed_by_its_creator(): void
    {
        $this->actingAs($this->anna);

        Livewire::test('task-create', ['project' => $this->project])->set('title', 'Neu für Ben')->call('create');

        $task = Task::where('title', 'Neu für Ben')->firstOrFail();
        $this->assertTrue($task->collaborators()->whereKey($this->anna->id)->exists());
    }

    public function test_the_profile_switch_changes_the_setting(): void
    {
        $this->actingAs($this->anna);

        Livewire::test('pages::profile')->assertSet('joinOwnTasks', true)->set('joinOwnTasks', false);

        $this->assertFalse($this->anna->fresh()->join_own_tasks);
    }

    public function test_a_task_created_through_mcp_follows_the_profile_setting_too(): void
    {
        $create = fn (array $extra = []) => SprintServer::actingAs($this->anna)->tool(CreateTask::class, $extra + ['project_id' => $this->project->id, 'assignee' => $this->ben->email]);

        $create(['title' => 'Agent 1'])->assertOk();
        $create(['title' => 'Agent 2', 'collaborators' => [$this->ben->email]])->assertOk();

        foreach (['Agent 1', 'Agent 2'] as $title) {
            $task = Task::where('title', $title)->firstOrFail();
            $this->assertTrue($task->collaborators()->whereKey($this->anna->id)->exists(), $title);
            $this->assertSame(0, $task->activities()->where('type', 'collaborators_removed')->count(), $title);
        }

        $this->anna->update(['join_own_tasks' => false]);

        $create(['title' => 'Agent 3', 'collaborators' => [$this->ben->email]])->assertOk();

        $this->assertFalse(Task::where('title', 'Agent 3')->firstOrFail()->collaborators()->whereKey($this->anna->id)->exists());
    }

    public function test_whoever_takes_the_task_in_the_dialog_is_not_added_as_a_collaborator(): void
    {
        $this->actingAs($this->anna);

        Livewire::test('task-create', ['project' => $this->project])->set('title', 'Für mich')->set('assigneeId', (string) $this->anna->id)->call('create');

        $task = Task::where('title', 'Für mich')->firstOrFail();
        $this->assertSame($this->anna->id, $task->assignee_id);
        $this->assertFalse($task->collaborators()->exists());
    }

    public function test_somebody_without_access_to_the_project_is_not_added(): void
    {
        $outsider = User::factory()->create();

        $task = Task::factory()->for($this->project)->create(['creator_id' => $outsider->id, 'assignee_id' => $this->ben->id]);

        $this->assertFalse($task->collaborators()->exists());
    }

    public function test_taking_the_task_later_leaves_no_entry_about_the_creator_being_removed(): void
    {
        $this->actingAs($this->anna);
        $task = $this->createdBy($this->anna);

        $this->assertTrue($task->collaborators()->whereKey($this->anna->id)->exists());

        $task->collaborators()->syncWithoutDetaching([$this->anna->id]);
        $task->assignTo($this->anna);

        $this->assertFalse($task->collaborators()->exists());
        $this->assertSame(0, $task->activities()->where('type', 'collaborators_removed')->count());
    }
}
