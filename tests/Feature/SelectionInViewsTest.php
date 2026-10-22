<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_the_button_starts_the_selection_in_the_browser_with_a_box_ready_before_every_task_and_the_bar_stays_while_nothing_is_selected(): void
    {
        $this->task(['title' => 'Auswahl']);

        foreach ($this->views() as $view) {
            // The boxes are drawn for every task in advance (in a template), the browser makes them at once; the server follows
            Livewire::test($view, ['project' => $this->project])
                ->assertSee('Auswählen')
                ->assertSeeHtml('x-if="$wire.selecting"')
                ->assertSeeHtml('aria-label="Aufgabe auswählen: ')
                ->assertSeeHtml('$wire.selecting = true; $wire.$refresh()')
                ->call('startSelecting')
                ->assertSet('selecting', true)
                ->assertSet('selected', [])
                ->assertSee('Auswahl beenden')
                ->call('stopSelecting')
                ->assertSet('selecting', false);
        }
    }

    public function test_on_phones_the_button_stands_in_the_row_of_the_views_and_otherwise_in_the_toolbar(): void
    {
        $this->task(['title' => 'Auswahl']);

        foreach ($this->views() as $view) {
            $html = Livewire::test($view, ['project' => $this->project])->html();

            // Two buttons: the one in the row of the views (below sm) and the one in the toolbar of the page (from sm)
            $this->assertSame(2, substr_count($html, 'data-selection-toggle>'), $view);
            $this->assertMatchesRegularExpression('/data-view-switcher>.*?class="[^"]*sm:hidden[^"]*"[^>]*data-selection-toggle>/s', $html, $view);
            $this->assertStringContainsString('ms-auto max-sm:hidden', $html, $view);
        }
    }

    public function test_the_boards_have_the_boxes_and_the_bar_ready_and_stand_still_while_selecting(): void
    {
        $this->task(['title' => 'Auswahl', 'assignee_id' => $this->user->id]);

        foreach (['pages::projects.board' => ['project' => $this->project], 'pages::tasks.mine-board' => []] as $view => $parameters) {
            Livewire::test($view, $parameters)
                ->assertSeeHtml('x-if="$wire.selecting"')
                ->assertSeeHtml('aria-label="Aufgabe auswählen: Auswahl"')
                ->assertSeeHtml('$wire.selecting = true; $wire.$refresh()')
                ->assertSeeHtml('x-show="$wire.selecting"')
                ->assertSeeHtml('filter(event) { return $wire.selecting || event.target.closest(')
                ->assertSeeHtml('Auswahl beenden');
        }
    }

    public function test_the_board_leaves_the_bar_and_the_boxes_out_for_those_who_may_not_edit(): void
    {
        $readOnly = Project::factory()->create();
        $readOnly->setRole($this->user, ProjectRole::Viewer);
        $this->task(['title' => 'Nur lesen'], $readOnly);

        Livewire::test('pages::projects.board', ['project' => $readOnly])
            ->assertDontSeeHtml('x-if="$wire.selecting"')
            ->assertDontSeeHtml('Auswahl beenden')
            ->set('selecting', true)
            ->assertDontSeeHtml('Auswahl beenden');
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
                ->assertSeeHtml('aria-label="Aufgabe auswählen: ')
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

    public function test_paging_through_the_calendar_and_the_timeline_ends_the_selection_of_tasks_no_longer_shown(): void
    {
        $task = $this->task();

        foreach ([['pages::projects.calendar', 'nextMonth'], ['pages::projects.calendar', 'today'], ['pages::projects.timeline', 'later'], ['pages::projects.timeline', 'today']] as [$view, $action]) {
            Livewire::test($view, ['project' => $this->project])
                ->call('startSelecting')
                ->set('selected', [(string) $task->id])
                ->call($action)
                ->assertSet('selected', [])
                ->assertSet('selecting', true);
        }
    }

    public function test_the_calendar_and_the_timeline_stop_offering_other_things_to_do_with_a_click_while_selecting(): void
    {
        $this->task(['title' => 'Verschiebbar']);

        // The browser selects at once, so the pages carry the rule themselves: nothing is drawn differently by the server
        $calendar = Livewire::test('pages::projects.calendar', ['project' => $this->project]);
        $calendar->assertSeeHtml("if (! \$wire.selecting && ! \$event.target.closest('a, button'))")
            ->assertSeeHtml('x-on:drop.prevent="over = false; if ($wire.selecting) { return }')
            ->assertSeeHtml('x-on:dragstart="if ($wire.selecting) { return }')
            ->assertSeeHtml("'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-700/40': ! \$wire.selecting")
            ->assertSeeHtml("'ps-6': \$wire.selecting, '[@media(hover:none)]:pe-7': ! \$wire.selecting")
            ->assertSeeHtml('x-show="! $wire.selecting" x-on:click.stop="$wire.$dispatch(\'new-task\'')
            ->assertSeeHtml('<span x-show="! $wire.selecting" class="absolute inset-y-0 end-0 hidden');

        // The bar of the timeline does not start moving while tasks are selected (timelineBar, resources/js/app.js)
        $timeline = Livewire::test('pages::projects.timeline', ['project' => $this->project]);
        $timeline->assertSeeHtml('x-data="timelineBar(')
            ->assertSeeHtml("\$wire.selecting ? '' : 'cursor-grab touch-none select-none'")
            ->assertSeeHtml('<span x-show="! $wire.selecting" class="absolute inset-y-0 start-0 w-1.5')
            ->assertSeeHtml('<span x-show="! $wire.selecting" class="absolute inset-y-0 end-0 w-1.5');
        $this->assertStringContainsString('this.$wire.selecting', file_get_contents(base_path('resources/js/app.js')));
    }

    public function test_the_views_leave_the_boxes_and_the_bar_out_for_those_who_may_not_edit(): void
    {
        $readOnly = Project::factory()->create();
        $readOnly->setRole($this->user, ProjectRole::Viewer);
        $this->task(['title' => 'Nur lesen'], $readOnly);

        foreach (['pages::projects.show', 'pages::projects.calendar', 'pages::projects.timeline'] as $view) {
            Livewire::test($view, ['project' => $readOnly])
                ->assertDontSeeHtml('x-if="$wire.selecting"')
                ->assertDontSeeHtml('aria-label="Aufgabe auswählen: ')
                ->assertDontSeeHtml('Auswahl beenden');
        }
    }

    public function test_the_boxes_follow_the_selection_and_work_with_the_keyboard(): void
    {
        $this->task(['title' => 'Mit Tastatur']);

        // The box sets its state from the selection and changes it on `change` (the space bar sends no click)
        Livewire::test('pages::projects.board', ['project' => $this->project])
            ->call('startSelecting')
            ->assertSeeHtml('x-on:change=')
            ->assertSeeHtml('$wire.selected.map(String).includes(')
            ->assertDontSeeHtml('x-on:click="$wire.selected');
    }

    public function test_in_my_tasks_tasks_of_projects_one_may_not_edit_have_no_box_and_the_selection_ends_with_the_switch(): void
    {
        $readOnly = Project::factory()->create();
        $readOnly->setRole($this->user, ProjectRole::Viewer);
        $mine = $this->task(['assignee_id' => $this->user->id, 'title' => 'Eigene']);
        $this->task(['assignee_id' => $this->user->id, 'title' => 'Nur lesbar'], $readOnly);

        foreach (['pages::tasks.mine', 'pages::tasks.mine-board'] as $view) {
            Livewire::test($view)
                ->call('startSelecting')
                ->assertSeeHtml('aria-label="Aufgabe auswählen: Eigene"')
                ->assertDontSeeHtml('aria-label="Aufgabe auswählen: Nur lesbar"')
                ->set('selected', [(string) $mine->id])
                ->set('includeCollaborating', true)
                ->assertSet('selected', [])
                ->assertSet('selecting', true);
        }
    }

    public function test_my_tasks_complete_selected_tasks_with_a_fixed_number_of_queries(): void
    {
        $queriesFor = function (int $count): int {
            $page = Livewire::test('pages::tasks.mine')->call('startSelecting');
            $ids = collect(range(1, $count))->map(fn () => (string) $this->task(['assignee_id' => $this->user->id])->id)->all();
            $page->set('selected', $ids);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $page->call('bulkComplete');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $forThree = $queriesFor(3);
        $forTwelve = $queriesFor(12);

        // Three queries more per task (the update and what follows it), not a fourth to look up the done status of its project
        $this->assertLessThanOrEqual(9 * 3, $forTwelve - $forThree);
    }

    public function test_my_tasks_tell_when_nothing_selected_can_be_changed(): void
    {
        $readOnly = Project::factory()->create();
        $readOnly->setRole($this->user, ProjectRole::Viewer);
        $task = $this->task(['assignee_id' => $this->user->id], $readOnly);

        Livewire::test('pages::tasks.mine')
            ->call('startSelecting')
            ->set('selected', [(string) $task->id])
            ->call('bulkDelete')
            ->assertHasErrors('selected')
            ->assertSee('Du darfst diese Aufgaben nicht ändern.');

        $this->assertModelExists($task);
    }

    public function test_the_choices_of_the_dialog_are_asked_for_once_and_not_with_every_render(): void
    {
        $this->project->tags()->create(['name' => 'Kunde', 'color' => 'blue']);
        $this->task();

        foreach (['pages::projects.calendar' => 'nextMonth', 'pages::projects.timeline' => 'later'] as $view => $action) {
            $page = Livewire::test($view, ['project' => $this->project])->call('startSelecting')->assertSee('Kunde');

            DB::flushQueryLog();
            DB::enableQueryLog();
            $page->call($action)->call($action);
            DB::disableQueryLog();

            $this->assertSame([], collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => str_contains($query, 'from "tags"'))->all());
        }
    }

    public function test_the_button_is_only_offered_where_there_is_something_to_select(): void
    {
        foreach ($this->views() as $view) {
            Livewire::test($view, ['project' => $this->project])->assertDontSeeHtml('data-selection-toggle>');
        }

        foreach (['pages::tasks.mine', 'pages::tasks.mine-board'] as $view) {
            Livewire::test($view)->assertDontSeeHtml('data-selection-toggle>');
        }

        $this->task(['assignee_id' => $this->user->id]);

        foreach ([...$this->views(), 'pages::tasks.mine', 'pages::tasks.mine-board'] as $view) {
            Livewire::test($view, str_contains($view, 'projects.') ? ['project' => $this->project] : [])->assertSeeHtml('data-selection-toggle>');
        }
    }
}
