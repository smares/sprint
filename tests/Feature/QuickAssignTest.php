<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuickAssignTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        $this->project = Project::factory()->create();
        $this->member = User::factory()->create();
        $this->project->setRole($this->member, ProjectRole::Editor);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function views(): array
    {
        return ['list' => ['pages::projects.show'], 'board' => ['pages::projects.board']];
    }

    #[DataProvider('views')]
    public function test_a_task_is_assigned_with_one_call_and_the_change_is_recorded(string $view): void
    {
        $task = Task::factory()->for($this->project)->create(['assignee_id' => null]);

        Livewire::test($view, ['project' => $this->project])->call('assign', $task->id, $this->member->id)->assertOk();

        $this->assertSame($this->member->id, $task->fresh()->assignee_id);
        $this->assertTrue($task->activities()->where('type', ActivityType::AssigneeChanged)->exists());
    }

    #[DataProvider('views')]
    public function test_assigning_to_nobody_clears_the_assignee(string $view): void
    {
        $task = Task::factory()->for($this->project)->create(['assignee_id' => $this->member->id]);

        Livewire::test($view, ['project' => $this->project])->call('assign', $task->id, null);

        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_the_new_assignee_stops_being_a_collaborator(): void
    {
        $task = Task::factory()->for($this->project)->create();
        $task->collaborators()->attach($this->member);

        Livewire::test('pages::projects.show', ['project' => $this->project])->call('assign', $task->id, $this->member->id);

        $this->assertSame($this->member->id, $task->fresh()->assignee_id);
        $this->assertFalse($task->collaborators()->exists());
    }

    #[DataProvider('views')]
    public function test_only_people_who_can_be_assigned_to_the_project_are_accepted(string $view): void
    {
        $task = Task::factory()->for($this->project)->create(['assignee_id' => null]);
        $stranger = User::factory()->create();
        $deactivated = User::factory()->create(['deactivated_at' => now()]);
        $this->project->setRole($deactivated, ProjectRole::Editor);

        foreach ([$stranger, $deactivated] as $user) {
            Livewire::test($view, ['project' => $this->project])->call('assign', $task->id, $user->id)->assertDispatched('toast-show');
        }

        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_only_top_level_tasks_of_the_project_are_assigned_here(): void
    {
        $parent = Task::factory()->for($this->project)->create();
        $subtask = Task::factory()->for($this->project)->create(['parent_id' => $parent->id]);
        $foreign = Task::factory()->create();

        Livewire::test('pages::projects.show', ['project' => $this->project])->call('assign', $subtask->id, $this->member->id)->assertNotFound();
        Livewire::test('pages::projects.show', ['project' => $this->project])->call('assign', $foreign->id, $this->member->id)->assertNotFound();
    }

    #[DataProvider('views')]
    public function test_viewers_cannot_assign_and_are_not_offered_the_menu(string $view): void
    {
        $task = Task::factory()->for($this->project)->create(['title' => 'Nur ansehen', 'assignee_id' => $this->member->id]);
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer);

        Livewire::test($view, ['project' => $this->project])
            ->assertSee('Nur ansehen')
            ->assertDontSeeHtml('data-assignee-menu')
            ->assertDontSeeHtml('open-assignee-menu')
            ->call('assign', $task->id, $viewer->id)
            ->assertForbidden();

        $this->assertSame($this->member->id, $task->fresh()->assignee_id);
    }

    #[DataProvider('views')]
    public function test_editors_get_one_menu_with_themselves_the_team_and_nobody_and_a_trigger_per_task(string $view): void
    {
        $tasks = Task::factory()->for($this->project)->count(3)->create(['assignee_id' => $this->member->id]);
        $html = Livewire::test($view, ['project' => $this->project])->html();

        // The people are in the page once, not once per task
        $this->assertSame(1, substr_count($html, 'data-assignee-menu'));
        $this->assertSame(1, substr_count($html, 'pick('.$this->member->id.')'));
        $this->assertSame(1, substr_count($html, 'pick(meId)'));
        $this->assertSame(1, substr_count($html, 'pick(null)'));

        // Each task has a trigger that says which task it is and who is the assignee now, so the menu is never stale
        foreach ($tasks as $task) {
            $this->assertStringContainsString("task: {$task->id}, assignee: {$this->member->id}", $html);
        }
    }

    public function test_a_task_without_people_still_offers_the_menu_to_editors(): void
    {
        $task = Task::factory()->for($this->project)->create(['assignee_id' => null]);

        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->assertSeeHtml('aria-label="'.__('Change assignee').'"')
            ->assertSeeHtml("task: {$task->id}, assignee: null");
    }

    #[DataProvider('views')]
    public function test_a_task_with_collaborators_but_no_assignee_shows_an_empty_seat_in_front(string $view): void
    {
        $task = Task::factory()->for($this->project)->create(['assignee_id' => null]);
        $task->collaborators()->attach($this->member);

        Livewire::test($view, ['project' => $this->project])->assertSeeHtml('data-unassigned');

        $task->update(['assignee_id' => $this->member->id]);
        $task->collaborators()->detach($this->member);
        $task->collaborators()->attach(User::factory()->create());

        Livewire::test($view, ['project' => $this->project])->assertDontSeeHtml('data-unassigned');
    }

    #[DataProvider('views')]
    public function test_viewers_see_the_empty_seat_too(string $view): void
    {
        $task = Task::factory()->for($this->project)->create(['assignee_id' => null]);
        $task->collaborators()->attach($this->member);
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->actingAs($viewer);

        Livewire::test($view, ['project' => $this->project])->assertSeeHtml('data-unassigned');
    }

    public function test_taking_over_a_task_from_the_collaborators_is_recorded_as_one_change_of_the_assignee(): void
    {
        $task = Task::factory()->for($this->project)->create(['assignee_id' => null]);
        $task->collaborators()->attach($this->member);

        Livewire::test('pages::projects.show', ['project' => $this->project])->call('assign', $task->id, $this->member->id);

        $types = $task->activities()->pluck('type')->map->value->all();
        $this->assertContains(ActivityType::AssigneeChanged->value, $types);
        // The assignee stops being a collaborator without an entry of its own: the change of the assignee says it
        $this->assertNotContains(ActivityType::CollaboratorsRemoved->value, $types);
        $this->assertFalse($task->collaborators()->whereKey($this->member->id)->exists());
    }
}
