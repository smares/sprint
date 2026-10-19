<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SelectionInViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 10:00:00');
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->user, ProjectRole::Editor);
        $this->actingAs($this->user);
    }

    /**
     * @return list<string>
     */
    private function views(): array
    {
        return ['pages::projects.board', 'pages::projects.calendar', 'pages::projects.timeline'];
    }

    private function task(array $attributes = [], ?Project $project = null): Task
    {
        return Task::factory()->for($project ?? $this->project)->create(['start_date' => '2026-10-10', 'due_date' => '2026-10-12'] + $attributes);
    }

    public function test_the_button_starts_the_selection_with_a_box_before_every_task_and_the_bar_stays_while_nothing_is_selected(): void
    {
        $this->task(['title' => 'Auswahl']);

        foreach ($this->views() as $view) {
            $page = Livewire::test($view, ['project' => $this->project])
                ->assertSee('Auswählen')
                ->assertDontSeeHtml('aria-label="Aufgabe auswählen"')
                ->call('startSelecting')
                ->assertSet('selecting', true)
                ->assertSet('selected', [])
                ->assertSeeHtml('aria-label="Aufgabe auswählen"')
                ->assertSee('Auswahl beenden');

            $page->call('stopSelecting')->assertSet('selecting', false)->assertDontSeeHtml('aria-label="Aufgabe auswählen"');
        }
    }

    public function test_the_selected_tasks_are_completed_in_every_view(): void
    {
        foreach ($this->views() as $view) {
            $one = $this->task();
            $two = $this->task();
            $other = $this->task();

            Livewire::test($view, ['project' => $this->project])
                ->call('startSelecting')
                ->set('selected', [(string) $one->id, (string) $two->id])
                ->call('bulkComplete')
                ->assertSet('selecting', false);

            $this->assertTrue($one->fresh()->isDone());
            $this->assertTrue($two->fresh()->isDone());
            $this->assertFalse($other->fresh()->isDone());
        }
    }

    public function test_the_selected_tasks_are_deleted_and_the_dialog_changes_them(): void
    {
        $gone = $this->task();
        $kept = $this->task();
        $changed = $this->task(['assignee_id' => null]);

        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('startSelecting')
            ->set('selected', [(string) $gone->id])
            ->call('bulkDelete');

        $this->assertModelMissing($gone);
        $this->assertModelExists($kept);

        Livewire::test('pages::projects.calendar', ['project' => $this->project])
            ->call('startSelecting')
            ->set('selected', [(string) $changed->id])
            ->set('bulkAssignee', (string) $this->user->id)
            ->call('applyBulkChanges')
            ->assertHasNoErrors();

        $this->assertSame($this->user->id, $changed->fresh()->assignee_id);
    }

    public function test_only_top_level_tasks_of_the_project_can_be_selected(): void
    {
        $foreign = $this->task([], Project::factory()->create());
        $parent = $this->task();
        $subtask = $this->task(['parent_id' => $parent->id]);

        foreach ($this->views() as $view) {
            Livewire::test($view, ['project' => $this->project])
                ->call('startSelecting')
                ->set('selected', [(string) $foreign->id, (string) $subtask->id])
                ->call('bulkComplete')
                ->assertHasErrors('selected');
        }

        $this->assertFalse($foreign->fresh()->isDone());
        $this->assertFalse($subtask->fresh()->isDone());
    }

    public function test_people_who_may_not_edit_cannot_start_a_selection(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        $this->task(['title' => 'Nur lesen']);

        foreach ($this->views() as $view) {
            Livewire::actingAs($viewer)->test($view, ['project' => $this->project])
                ->assertDontSee('Auswählen')
                ->call('startSelecting')->assertForbidden();
        }
    }

    public function test_my_tasks_select_across_projects_and_leave_out_projects_one_may_not_edit(): void
    {
        $readOnly = Project::factory()->create();
        $readOnly->setRole($this->user, ProjectRole::Viewer);
        $mine = $this->task(['assignee_id' => $this->user->id]);
        $inViewed = $this->task(['assignee_id' => $this->user->id], $readOnly);
        $notMine = $this->task(['assignee_id' => User::factory()->create()->id]);

        foreach (['pages::tasks.mine', 'pages::tasks.mine-board'] as $view) {
            Livewire::test($view)
                ->assertSee('Auswählen')
                ->call('startSelecting')
                ->assertSeeHtml('aria-label="Aufgabe auswählen"')
                ->assertDontSee('Ändern')
                ->set('selected', [(string) $mine->id, (string) $inViewed->id, (string) $notMine->id])
                ->call('bulkComplete')
                ->assertHasNoErrors()
                ->assertSet('selecting', false);

            $this->assertTrue($mine->fresh()->isDone());
            $this->assertFalse($inViewed->fresh()->isDone());
            $this->assertFalse($notMine->fresh()->isDone());

            $mine->fresh()->update(['status_id' => $this->project->defaultStatus()->id]);
        }
    }

    public function test_my_tasks_delete_the_selected_ones_and_need_a_selection(): void
    {
        $gone = $this->task(['assignee_id' => $this->user->id]);
        $kept = $this->task(['assignee_id' => $this->user->id]);

        Livewire::test('pages::tasks.mine')
            ->call('startSelecting')
            ->call('bulkDelete')
            ->assertHasErrors('selected')
            ->set('selected', [(string) $gone->id])
            ->call('bulkDelete')
            ->assertSet('selecting', false);

        $this->assertModelMissing($gone);
        $this->assertModelExists($kept);
    }
}
