<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DoneOnTaskPageTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $editor;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->editor = User::factory()->create(['timezone' => 'Pacific/Auckland']);
        $this->project->setRole($this->editor, ProjectRole::Editor);
        $this->task = Task::factory()->for($this->project)->create();
        $this->actingAs($this->editor);
    }

    public function test_the_task_page_has_a_done_row_that_finishes_without_changing_the_status(): void
    {
        $status = $this->task->status_id;

        $page = Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->assertSeeHtml('aria-label="Als erledigt markieren: '.e($this->task->title).'"')
            ->call('toggleDoneByShortcut');

        $this->assertTrue($this->task->fresh()->isDone());
        $this->assertSame($status, $this->task->fresh()->status_id);

        $page->assertSeeHtml('aria-label="Aufgabe wieder öffnen: '.e($this->task->title).'"')->call('toggleDoneByShortcut');

        $this->assertFalse($this->task->fresh()->isDone());
        $this->assertSame($status, $this->task->fresh()->status_id);
    }

    public function test_the_done_row_says_not_yet_while_the_task_is_open(): void
    {
        Livewire::test('pages::tasks.show', ['task' => $this->task])->assertSee('Noch nicht')->assertDontSee('Erledigt am')
            ->call('toggleDoneByShortcut')->assertDontSee('Noch nicht')->assertSee('Erledigt am')
            ->call('toggleDoneByShortcut')->assertSee('Noch nicht');
    }

    public function test_the_done_row_names_the_day_on_the_clock_of_the_person(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(20, 30));
        $this->task->update(['completed_at' => now()]);

        // 20:30 UTC is already the next morning in Auckland
        Livewire::test('pages::tasks.show', ['task' => $this->task->fresh()])->assertSee('07.11.2026');
    }

    public function test_an_unsaved_status_choice_stays_when_the_task_is_finished(): void
    {
        $other = $this->project->statuses()->skip(1)->firstOrFail();

        Livewire::test('pages::tasks.show', ['task' => $this->task])
            ->set('statusId', (string) $other->id)
            ->call('toggleDoneByShortcut')
            ->assertSet('statusId', (string) $other->id);

        $this->assertNotSame($other->id, $this->task->fresh()->status_id);
    }

    public function test_an_address_with_the_old_status_filter_wins_over_a_default_view(): void
    {
        SavedFilter::factory()->for($this->project)->projectDefault()->create([
            'filters' => ['status' => '', 'done' => 'all', 'assignee' => '', 'tag' => '', 'fields' => []],
        ]);

        Livewire::withQueryParams(['status' => 'open'])->test('pages::projects.show', ['project' => $this->project])
            ->assertSet('statusFilter', '')
            ->assertSet('doneFilter', 'open');

        Livewire::withQueryParams(['status' => 'all'])->test('pages::projects.show', ['project' => $this->project])
            ->assertSet('doneFilter', 'all');
    }

    public function test_a_saved_view_of_a_status_that_is_gone_shows_the_open_tasks(): void
    {
        $view = SavedFilter::factory()->for($this->project)->shared()->create([
            'filters' => ['status' => '999999', 'assignee' => '', 'tag' => '', 'fields' => []],
        ]);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('applyView', $view->id)
            ->assertSet('statusFilter', '')
            ->assertSet('doneFilter', 'open');
    }
}
