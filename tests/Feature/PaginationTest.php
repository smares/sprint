<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
        $this->project = Project::factory()->create();
    }

    private function tasks(int $count, array $attributes = []): void
    {
        foreach (range(1, $count) as $number) {
            Task::factory()->for($this->project)->create($attributes + ['title' => sprintf('Aufgabe %03d', $number), 'position' => $number]);
        }
    }

    public function test_the_list_shows_the_first_fifty_and_loads_more_on_demand(): void
    {
        $this->tasks(120);

        $list = Livewire::test('pages::projects.show', ['project' => $this->project]);

        $this->assertCount(50, $list->instance()->tasks);
        $this->assertSame(120, $list->instance()->totalTasks);
        $list->assertSee('Aufgabe 050')->assertDontSee('Aufgabe 051')->assertSee('50 von 120 Aufgaben');

        $list->call('loadMore')->assertSee('Aufgabe 100')->assertDontSee('Aufgabe 101')->assertSee('100 von 120');
        $list->call('loadMore')->assertSee('Aufgabe 120')->assertDontSee('Mehr laden');
    }

    public function test_short_lists_have_no_load_more_control(): void
    {
        $this->tasks(10);

        Livewire::test('pages::projects.show', ['project' => $this->project])->assertDontSee('Mehr laden')->assertDontSee('von 10 Aufgaben');
    }

    public function test_changing_filters_or_sorting_starts_again_at_the_first_page(): void
    {
        $this->tasks(120);
        $list = Livewire::test('pages::projects.show', ['project' => $this->project])->call('loadMore')->assertSee('100 von 120');

        $list->set('statusFilter', 'all')->assertSee('50 von 120');
        $list->call('loadMore')->call('sort', 'title')->assertSee('50 von 120');
        $list->call('loadMore')->set('assigneeFilter', 'me')->assertDontSee('Mehr laden');
        $list->call('loadMore')->call('resetFilters')->assertSee('50 von 120');
    }

    public function test_the_total_follows_the_filters(): void
    {
        $this->tasks(60);
        $this->tasks(70, ['status_id' => $this->project->doneStatus()->id, 'title' => 'Erledigt']);

        $open = Livewire::test('pages::projects.show', ['project' => $this->project]);
        $this->assertSame(60, $open->instance()->totalTasks);

        $this->assertSame(130, $open->set('statusFilter', 'all')->instance()->totalTasks);
    }

    public function test_the_page_size_cannot_be_raised_from_the_browser(): void
    {
        $this->tasks(60);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('pages::projects.show', ['project' => $this->project])->set('limit', 100000);
    }

    public function test_dragging_in_a_partial_list_keeps_the_hidden_tasks_in_place(): void
    {
        $this->tasks(60);
        $last = Task::where('title', 'Aufgabe 060')->firstOrFail();
        $first = Task::where('title', 'Aufgabe 001')->firstOrFail();

        Livewire::test('pages::projects.show', ['project' => $this->project])->call('moveTask', $first->id, 49);

        $order = $this->project->tasks()->orderBy('position')->orderBy('id')->pluck('title')->all();
        $this->assertSame('Aufgabe 002', $order[0]);
        $this->assertSame('Aufgabe 001', $order[49]);
        $this->assertSame('Aufgabe 051', $order[50]);
        $this->assertSame('Aufgabe 060', end($order));
        $this->assertNotNull($last);
    }

    public function test_board_columns_show_thirty_cards_with_the_real_total_and_load_more_per_column(): void
    {
        $this->tasks(75);
        $open = $this->project->defaultStatus();

        $board = Livewire::test('pages::projects.board', ['project' => $this->project]);

        $this->assertCount(30, $board->instance()->columns[$open->id]);
        $this->assertSame(75, $board->instance()->columnTotals[$open->id]);
        $board->assertSee('Aufgabe 030')->assertDontSee('Aufgabe 031')->assertSee('Mehr laden (30 von 75)');

        $board->call('loadMoreInColumn', $open->id)->assertSee('Aufgabe 060')->assertSee('Mehr laden (60 von 75)');
        $board->call('loadMoreInColumn', $open->id)->assertSee('Aufgabe 075')->assertDontSee('Mehr laden');
    }

    public function test_loading_more_only_works_for_statuses_of_the_project(): void
    {
        $foreign = Project::factory()->create()->defaultStatus();

        Livewire::test('pages::projects.board', ['project' => $this->project])->call('loadMoreInColumn', $foreign->id)->assertNotFound();
    }

    public function test_moving_a_card_in_a_limited_column_respects_what_is_visible(): void
    {
        $this->tasks(40);
        $open = $this->project->defaultStatus();
        $moved = Task::where('title', 'Aufgabe 040')->firstOrFail();

        Livewire::test('pages::projects.board', ['project' => $this->project])->call('moveTask', $moved->id, 0, (string) $open->id);

        $this->assertSame('Aufgabe 040', $this->project->tasks()->orderBy('position')->orderBy('id')->first()->title);
    }

    public function test_my_tasks_and_search_page_too(): void
    {
        $this->tasks(70, ['assignee_id' => $this->user->id]);

        $mine = Livewire::test('pages::tasks.mine');
        $this->assertCount(50, $mine->instance()->tasks);
        $mine->assertSee('50 von 70 Aufgaben')->call('loadMore')->assertDontSee('Mehr laden');

        $search = Livewire::test('pages::search')->set('query', 'aufgabe');
        $search->assertSee('Mehr laden')->call('loadMore')->assertDontSee('Mehr laden');
        $search->set('query', 'aufgabe 01')->assertDontSee('Mehr laden');
    }
}
