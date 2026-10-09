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

class BulkTaskActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->setRole($this->user, ProjectRole::Editor);
        $this->actingAs($this->user);
    }

    private function task(string $title, array $attributes = []): Task
    {
        return Task::factory()->for($this->project)->create(['title' => $title] + $attributes);
    }

    private function list(array $selected = [])
    {
        $page = Livewire::test('pages::projects.show', ['project' => $this->project])->set('selecting', true);

        return $selected === [] ? $page : $page->set('selected', array_map(fn (Task $task) => (string) $task->id, $selected));
    }

    public function test_completing_many_tasks_costs_a_fixed_number_of_queries_per_task(): void
    {
        $collaborator = User::factory()->create();
        $this->project->setRole($collaborator, ProjectRole::Editor);
        $queriesFor = function (int $count) use ($collaborator): int {
            $tasks = collect(range(1, $count))->map(function (int $number) use ($collaborator): Task {
                $task = $this->task("Aufgabe $number", ['assignee_id' => $collaborator->id]);
                $task->collaborators()->attach($collaborator);

                return $task;
            });
            $page = $this->list($tasks->all());

            DB::flushQueryLog();
            DB::enableQueryLog();
            $page->call('bulkComplete');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $forFive = $queriesFor(5);
        $forFifteen = $queriesFor(15);

        $this->assertLessThanOrEqual(3, ($forFifteen - $forFive) / 10, "{$forFive} queries for 5 tasks, {$forFifteen} for 15");
    }

    public function test_the_list_offers_selecting_to_editors_only(): void
    {
        $task = $this->task('Eins');

        Livewire::test('pages::projects.show', ['project' => $this->project])->assertSee('Aufgabe auswählen')->assertDontSee('Auswahl beenden');

        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);
        Livewire::actingAs($viewer)->test('pages::projects.show', ['project' => $this->project])->assertDontSee('Aufgabe auswählen')->call('selectTask', $task->id)->assertForbidden();
    }

    public function test_the_checkbox_is_for_selecting_and_a_button_of_its_own_completes(): void
    {
        $task = $this->task('Eins');

        $page = Livewire::test('pages::projects.show', ['project' => $this->project])
            ->assertSee('Als erledigt markieren')
            ->call('selectTask', $task->id)
            ->assertSet('selecting', true)
            ->assertSet('selected', [(string) $task->id]);

        $this->assertFalse($task->fresh()->isDone());

        $page->call('toggleDone', $task->id);
        $this->assertTrue($task->fresh()->isDone());
    }

    public function test_the_checkbox_of_a_viewer_cannot_start_a_selection(): void
    {
        $task = $this->task('Eins');
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        Livewire::actingAs($viewer)->test('pages::projects.show', ['project' => $this->project])->call('selectTask', $task->id)->assertForbidden();
    }

    public function test_selecting_shows_checkboxes_and_the_action_bar_with_a_count(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei');

        $this->list([$a])->assertSee('Auswahl beenden')->assertSee('Als erledigt markieren')->assertSee('Ändern')->assertSee('Löschen')->assertSeeHtml('$wire.selected');
        $this->list([$a, $b])->assertSee('Auswahl beenden');
        $this->list()->call('stopSelecting')->assertDontSee('Auswahl beenden');
    }

    public function test_the_page_can_be_selected_and_deselected_at_once(): void
    {
        $this->task('Eins');
        $this->task('Zwei');

        $page = $this->list()->call('togglePage');
        $this->assertCount(2, $page->instance()->selectedIds);

        $page->call('togglePage');
        $this->assertSame([], $page->instance()->selectedIds);
    }

    public function test_everything_matching_the_filters_can_be_selected_beyond_the_loaded_page(): void
    {
        Task::factory()->for($this->project)->count(60)->create();

        $page = $this->list();
        $this->assertCount(50, $page->instance()->tasks);

        $page->call('selectAllMatching');
        $this->assertCount(60, $page->instance()->selectedIds);
        $page->assertSet('selected', fn ($ids) => count($ids) === 60);
    }

    public function test_changing_the_filters_forgets_the_selection(): void
    {
        $a = $this->task('Eins');

        $this->list([$a])->set('statusFilter', 'all')->assertSet('selected', []);
        $this->list([$a])->call('resetFilters')->assertSet('selected', []);
        $this->list([$a])->call('clearFilter', 'assignee')->assertSet('selected', []);
    }

    public function test_only_tasks_that_are_still_shown_by_the_filters_count(): void
    {
        $open = $this->task('Offen');
        $done = $this->task('Fertig', ['status_id' => $this->project->doneStatus()->id]);

        $page = $this->list([$open, $done]);
        $this->assertSame([$open->id], $page->instance()->selectedIds);
    }

    public function test_foreign_subtasks_and_headings_cannot_be_selected(): void
    {
        $mine = $this->task('Mein');
        $foreign = Task::factory()->for(Project::factory()->create())->create();
        $child = $this->task('Kind', ['parent_id' => $mine->id]);
        $heading = $this->task('Abschnitt', ['is_section' => true]);

        $page = $this->list([$mine, $foreign, $child, $heading]);

        $this->assertSame([$mine->id], $page->instance()->selectedIds);

        $page->call('bulkDelete');
        $this->assertNotNull(Task::find($foreign->id));
        $this->assertNull(Task::find($mine->id));
        $this->assertNotNull(Task::find($heading->id));
    }

    public function test_selected_tasks_can_be_completed(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei');
        $c = $this->task('Drei');

        $this->list([$a, $b])->call('bulkComplete')->assertSet('selecting', false)->assertSet('selected', []);

        $this->assertTrue($a->fresh()->isDone());
        $this->assertTrue($b->fresh()->isDone());
        $this->assertFalse($c->fresh()->isDone());
        $this->assertContains('hat den Status von „Offen“ auf „Erledigt“ geändert', $a->activities()->get()->map->sentence()->all());
    }

    public function test_status_assignee_and_due_date_are_changed_together_and_the_rest_stays(): void
    {
        $anna = User::factory()->create();
        $this->project->setRole($anna, ProjectRole::Editor);
        $inWork = $this->project->statuses()->where('name', 'In Arbeit')->firstOrFail();
        $a = $this->task('Eins', ['description' => 'Bleibt', 'due_date' => '2026-12-01']);
        $b = $this->task('Zwei');

        $this->list([$a, $b])
            ->set('bulkStatus', (string) $inWork->id)->set('bulkAssignee', (string) $anna->id)->set('bulkDueDate', '2026-12-24')
            ->call('applyBulkChanges')->assertHasNoErrors()->assertSet('selecting', false);

        foreach ([$a, $b] as $task) {
            $task->refresh();
            $this->assertSame($inWork->id, $task->status_id);
            $this->assertSame($anna->id, $task->assignee_id);
            $this->assertSame('2026-12-24', $task->due_date->toDateString());
        }
        $this->assertSame('Bleibt', $a->fresh()->description);
    }

    public function test_untouched_fields_stay_as_they_are(): void
    {
        $anna = User::factory()->create();
        $this->project->setRole($anna, ProjectRole::Editor);
        $task = $this->task('Eins', ['assignee_id' => $anna->id, 'due_date' => '2026-12-01']);

        $this->list([$task])->set('bulkStatus', (string) $this->project->doneStatus()->id)->call('applyBulkChanges');

        $task->refresh();
        $this->assertSame($anna->id, $task->assignee_id);
        $this->assertSame('2026-12-01', $task->due_date->toDateString());
    }

    public function test_assignee_and_due_date_can_be_cleared(): void
    {
        $task = $this->task('Eins', ['assignee_id' => $this->user->id, 'due_date' => '2026-12-01']);

        $this->list([$task])->set('bulkAssignee', 'none')->set('bulkClearDueDate', true)->call('applyBulkChanges')->assertHasNoErrors();

        $task->refresh();
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);
    }

    public function test_a_new_due_date_before_the_start_moves_the_start(): void
    {
        $task = $this->task('Eins', ['start_date' => '2026-12-10', 'due_date' => '2026-12-20']);

        $this->list([$task])->set('bulkDueDate', '2026-12-05')->call('applyBulkChanges')->assertHasNoErrors();

        $this->assertSame('2026-12-05', $task->fresh()->start_date->toDateString());
        $this->assertSame('2026-12-05', $task->fresh()->due_date->toDateString());
    }

    public function test_tags_are_added_and_removed_with_history(): void
    {
        $bug = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        $idea = Tag::factory()->for($this->project)->create(['name' => 'Idee']);
        $a = $this->task('Eins');
        $b = $this->task('Zwei');
        $b->tags()->attach($bug);

        $this->list([$a, $b])->set('bulkAddTags', [(string) $idea->id])->set('bulkRemoveTags', [(string) $bug->id])->call('applyBulkChanges')->assertHasNoErrors();

        $this->assertSame([$idea->id], $a->tags()->pluck('tags.id')->all());
        $this->assertSame([$idea->id], $b->tags()->pluck('tags.id')->all());
        $this->assertContains('hat die Tags Idee hinzugefügt', $a->activities()->get()->map->sentence()->all());
        $this->assertContains('hat die Tags Bug entfernt', $b->activities()->get()->map->sentence()->all());
        $this->assertNotContains('hat die Tags Bug entfernt', $a->activities()->get()->map->sentence()->all());
    }

    public function test_foreign_ids_in_the_form_are_refused(): void
    {
        $task = $this->task('Eins');
        $foreignStatus = Project::factory()->create()->defaultStatus();
        $foreignTag = Tag::factory()->for(Project::factory()->create())->create();
        $outsider = User::factory()->create();

        $page = $this->list([$task]);

        $page->set('bulkStatus', (string) $foreignStatus->id)->call('applyBulkChanges')->assertHasErrors('bulkStatus');
        $page->set('bulkStatus', '')->set('bulkAssignee', (string) $outsider->id)->call('applyBulkChanges')->assertHasErrors('bulkAssignee');
        $page->set('bulkAssignee', '')->set('bulkAddTags', [(string) $foreignTag->id])->call('applyBulkChanges')->assertHasErrors('bulkAddTags.0');
    }

    public function test_an_empty_form_or_selection_changes_nothing(): void
    {
        $task = $this->task('Eins');

        $this->list([$task])->call('applyBulkChanges')->assertHasErrors('bulkStatus');
        $this->list()->set('bulkStatus', (string) $this->project->doneStatus()->id)->call('applyBulkChanges')->assertHasErrors('selected');
        $this->list()->call('bulkComplete')->assertHasErrors('selected');

        $this->assertFalse($task->fresh()->isDone());
    }

    public function test_selected_tasks_are_deleted_with_their_subtasks_and_the_open_panel_closes(): void
    {
        $a = $this->task('Eins');
        $b = $this->task('Zwei');
        $keep = $this->task('Behalten');
        $child = $this->task('Kind', ['parent_id' => $a->id]);

        $page = Livewire::test('pages::projects.show', ['project' => $this->project])->call('openTask', $a->id)->set('selecting', true)
            ->set('selected', [(string) $a->id, (string) $b->id])->call('bulkDelete');

        $this->assertNull(Task::find($a->id));
        $this->assertNull(Task::find($b->id));
        $this->assertNull(Task::find($child->id));
        $this->assertNotNull(Task::find($keep->id));
        $page->assertSet('openTaskId', '')->assertSet('selecting', false);
    }

    public function test_viewers_and_archived_projects_cannot_change_anything_in_bulk(): void
    {
        $task = $this->task('Eins');
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        Livewire::actingAs($viewer)->test('pages::projects.show', ['project' => $this->project])->set('selecting', true)->set('selected', [(string) $task->id])->call('bulkDelete')->assertForbidden();
        $this->assertNotNull(Task::find($task->id));
        $this->actingAs($this->user);

        $page = $this->list([$task]);
        $this->project->update(['archived_at' => now()]);
        $page->call('bulkDelete')->assertForbidden();
        $this->assertNotNull(Task::find($task->id));
    }
}
