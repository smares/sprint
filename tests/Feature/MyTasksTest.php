<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class MyTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->create(['name' => 'Webseite']);
        $this->project->setRole($this->user, ProjectRole::Editor);
        $this->actingAs($this->user);
    }

    private function mine(array $attributes = [], ?Project $project = null): Task
    {
        return Task::factory()->for($project ?? $this->project)->create(['assignee_id' => $this->user->id] + $attributes);
    }

    public function test_rows_show_the_same_pieces_as_the_project_list(): void
    {
        $task = $this->mine(['title' => 'Angebot', 'due_date' => '2020-01-01']);
        $task->tags()->attach(Tag::factory()->for($this->project)->create(['name' => 'Kunde A']));
        Task::factory()->for($this->project)->create(['parent_id' => $task->id]);
        Task::factory()->for($this->project)->done()->create(['parent_id' => $task->id]);

        Livewire::test('pages::tasks.mine')
            ->assertSee('Angebot')
            ->assertSee('Kunde A')
            ->assertSee('1/2')
            ->assertSeeHtml('aria-label="Als erledigt markieren: ')
            ->assertSeeHtml('data-opens-task="'.$task->id.'"')
            ->assertSeeHtml('data-due="red"');
    }

    public function test_ticking_a_task_off_completes_it_but_only_where_one_may_edit(): void
    {
        $task = $this->mine();
        $readOnly = Project::factory()->create();
        $readOnly->setRole($this->user, ProjectRole::Viewer);
        $watched = $this->mine([], $readOnly);

        $editable = $this->mine();

        $page = Livewire::test('pages::tasks.mine')->call('toggleDone', $task->id);
        $this->assertTrue($task->fresh()->isDone());

        // The check is disabled in the row of the read-only task, not in the others
        $row = fn (Task $of): string => preg_match('/<tr[^>]*data-task-id="'.$of->id.'".*?<\/tr>/s', $page->html(), $match) === 1 ? $match[0] : '';
        $disabledButton = '/<button\s[^>]*\sdisabled[\s=>]/';
        $this->assertMatchesRegularExpression($disabledButton, $row($watched));
        $this->assertDoesNotMatchRegularExpression($disabledButton, $row($editable));

        $page->call('toggleDone', $watched->id)->assertForbidden();
        $this->assertFalse($watched->fresh()->isDone());

        $hidden = Task::factory()->for(Project::factory())->create();
        Livewire::test('pages::tasks.mine')->call('toggleDone', $hidden->id)->assertNotFound();
        $this->assertFalse($hidden->fresh()->isDone());
    }

    public function test_a_task_opens_in_the_flyout_but_never_one_from_a_hidden_project(): void
    {
        $task = $this->mine(['title' => 'Im Flyout']);
        $hidden = Task::factory()->for(Project::factory())->create(['title' => 'Fremd']);

        Livewire::test('pages::tasks.mine')->assertSeeHtml('$openTask($event, '.$task->id.')');

        Livewire::test('task-flyout')
            ->call('openTask', $task->id)->assertSet('openTaskId', (string) $task->id)
            ->assertSeeHtml('data-modal="task-panel"')
            ->call('closeTask')
            ->call('openTask', $hidden->id)->assertSet('openTaskId', '');

        Livewire::withQueryParams(['task' => $hidden->id])->test('task-flyout')->assertDontSeeHtml('data-modal="task-panel"');
    }

    public function test_more_tasks_and_projects_cost_no_more_queries(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test('pages::tasks.mine');

            return count(DB::getQueryLog());
        };

        // In every project a task with a subtask that is blocked by another one
        $addProject = function (): void {
            $project = Project::factory()->create();
            $project->setRole($this->user, ProjectRole::Editor);
            $blocked = $this->mine([], $project);
            $blocked->blockers()->attach($this->mine([], $project));
            Task::factory()->for($project)->create(['parent_id' => $blocked->id]);
        };

        $addProject();
        $few = $queries();

        foreach (range(1, 6) as $number) {
            $addProject();
        }

        $this->assertSame($few, $queries());
    }
}
