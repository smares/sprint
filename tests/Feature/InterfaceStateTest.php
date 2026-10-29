<?php

namespace Tests\Feature;

use App\Enums\ProjectView;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class InterfaceStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_are_merged_into_what_is_kept(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'dark', 'sidebar_collapsed' => true])->assertOk();
        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'light'])->assertOk();

        $this->assertSame(['appearance' => 'light', 'sidebar_collapsed' => true], Arr::except($user->fresh()->interfaceState(), 'rev'));
    }

    public function test_a_column_is_added_or_removed_on_its_own(): void
    {
        $user = User::factory()->create(['interface' => ['board_collapsed' => ['1' => [10, 11], '2' => [20]], 'mine_board_collapsed' => [5]]]);

        // a device that saw an older state: it collapses 12 and opens 10, and knows nothing of 11 or of the other board
        $this->actingAs($user)->patchJson(route('interface.update'), ['board_collapsed' => [1 => [12 => true, 10 => false]], 'mine_board_collapsed' => [6 => true], 'sidebar_collapsed' => false])->assertOk();

        $state = $user->fresh()->interfaceState();

        $this->assertEqualsCanonicalizing([11, 12], $state['board_collapsed']['1']);
        $this->assertSame([20], $state['board_collapsed']['2']);
        $this->assertEqualsCanonicalizing([5, 6], $state['mine_board_collapsed']);
        $this->assertFalse($state['sidebar_collapsed']);
    }

    public function test_a_change_is_merged_into_what_is_stored_now_not_into_what_was_loaded(): void
    {
        $user = User::factory()->create(['interface' => ['mine_board_collapsed' => [1]]]);

        // this request loaded the user a moment ago; another request changed the row meanwhile
        $loaded = User::find($user->id);
        User::find($user->id)->rememberInterface(['mine_board_collapsed' => [2 => true], 'appearance' => 'dark']);

        $loaded->rememberInterface(['mine_board_collapsed' => [3 => true]]);

        $state = $user->fresh()->interfaceState();

        $this->assertEqualsCanonicalizing([1, 2, 3], $state['mine_board_collapsed']);
        $this->assertSame('dark', $state['appearance']);
        $this->assertEqualsCanonicalizing([1, 2, 3], $loaded->interfaceState()['mine_board_collapsed']);
    }

    public function test_a_column_collapsed_twice_is_kept_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['mine_board_collapsed' => [3 => true]])->assertOk();
        $this->actingAs($user)->patchJson(route('interface.update'), ['mine_board_collapsed' => [3 => true, 4 => true]])->assertOk();

        $this->assertSame([3, 4], $user->fresh()->interfaceState()['mine_board_collapsed']);
    }

    public function test_unknown_values_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'purple'])->assertJsonValidationErrors('appearance');
        $this->actingAs($user)->patchJson(route('interface.update'), ['board_collapsed' => [1 => [5 => 'x']]])->assertJsonValidationErrors('board_collapsed.1.5');
        $this->actingAs($user)->patchJson(route('interface.update'), ['sidebar_collapsed' => 'maybe'])->assertJsonValidationErrors('sidebar_collapsed');

        $this->assertSame([], $user->fresh()->interfaceState());
    }

    public function test_only_signed_in_people_can_save(): void
    {
        $this->patchJson(route('interface.update'), ['appearance' => 'dark'])->assertUnauthorized();
    }

    public function test_the_layout_hands_the_kept_state_to_the_browser(): void
    {
        $user = User::factory()->create(['interface' => ['appearance' => 'dark', 'sidebar_collapsed' => true]]);

        $this->actingAs($user)->get(route('projects.index'))
            ->assertOk()
            ->assertSee('\u0022appearance\u0022:\u0022dark\u0022,\u0022sidebar_collapsed\u0022:true', false)
            ->assertSee('name="sprint-interface"', false)
            ->assertSee("const appearance = 'dark'", false);
    }

    public function test_my_tasks_opens_in_the_view_used_last(): void
    {
        $user = User::factory()->create();
        $inApp = ['X-Livewire-Navigate' => '1'];

        $this->actingAs($user)->get(route('tasks.mine'), $inApp)->assertOk();
        $this->assertSame('list', $user->fresh()->interfaceState()['mine_view']);
        $this->actingAs($user->fresh())->get(route('projects.index'))->assertDontSee('href="'.route('tasks.mine.board').'"', false);

        $this->actingAs($user)->get(route('tasks.mine.board'), $inApp)->assertOk();
        $this->assertSame('board', $user->fresh()->interfaceState()['mine_view']);

        $this->actingAs($user->fresh())->get(route('projects.index'))->assertSee('href="'.route('tasks.mine.board').'"', false);
        $this->assertSame('tasks.mine.board', $user->fresh()->mineTasksRoute());
    }

    public function test_a_project_opens_in_the_view_used_last(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $user = User::factory()->admin()->create();
        $inApp = ['X-Livewire-Navigate' => '1'];

        $this->assertSame('projects.show', $user->projectRoute($project));

        $this->actingAs($user)->get(route('projects.board', $project), $inApp)->assertOk();
        $this->assertSame('board', $user->fresh()->interfaceState()['last_views'][$project->id]);
        $this->assertSame('projects.board', $user->fresh()->projectRoute($project));
        $this->assertSame('projects.show', $user->fresh()->projectRoute($other));

        $this->actingAs($user->fresh())->get(route('projects.index'))->assertSee('href="'.route('projects.board', $project).'"', false);

        $this->actingAs($user)->get(route('projects.show', $project), $inApp)->assertOk();
        $this->assertSame('projects.show', $user->fresh()->projectRoute($project));
        $this->assertArrayNotHasKey('last_views', $user->fresh()->interfaceState());
    }

    public function test_every_view_of_a_project_is_remembered(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->admin()->create();

        foreach (ProjectView::cases() as $view) {
            $this->actingAs($user)->get(route($view->routeName(), $project), ['X-Livewire-Navigate' => '1'])->assertOk();

            $this->assertSame($view->routeName(), $user->fresh()->projectRoute($project));
        }
    }

    public function test_an_unknown_view_in_the_state_opens_the_list(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create(['interface' => ['last_views' => [(string) $project->id => 'kanban']]]);

        $this->assertSame('projects.show', $user->projectRoute($project));
    }

    public function test_a_project_view_opened_by_a_link_from_outside_is_not_made_the_one_used_last(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->admin()->create(['interface' => ['last_views' => [(string) $project->id => 'board']]]);

        $this->actingAs($user)->get(route('projects.show', $project))->assertOk();

        $this->assertSame('projects.board', $user->fresh()->projectRoute($project));
    }

    public function test_the_views_remembered_for_projects_stay_few_and_the_last_changed_is_kept(): void
    {
        $user = User::factory()->create();
        $first = Project::factory()->create();
        $user->rememberProjectView($first, ProjectView::Board);

        $user->rememberInterface(['last_views' => array_fill_keys(range(1000, 1000 + User::INTERFACE_MAX_BOARDS), 'board')]);
        $views = $user->fresh()->interfaceState()['last_views'];

        $this->assertCount(User::INTERFACE_MAX_BOARDS, $views);
        $this->assertArrayNotHasKey($first->id, $views);
        $this->assertArrayHasKey(1000 + User::INTERFACE_MAX_BOARDS, $views);
    }

    public function test_the_command_palette_opens_a_project_in_the_view_used_last(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->admin()->create(['interface' => ['last_views' => [(string) $project->id => 'calendar']]]);
        $user->rememberProjectVisit($project);

        $html = $this->actingAs($user->fresh())->get(route('projects.index'))->assertOk()->getContent();

        $this->assertStringContainsString("[window.sprintInterface?.last_views?.[{$project->id}]] ?? '".route('projects.show', $project)."'", $html);

        foreach (ProjectView::cases() as $view) {
            $this->assertStringContainsString('\\u0022'.$view->value.'\\u0022:', $html);
        }
    }

    public function test_a_link_that_opens_the_list_with_a_filter_does_not_change_the_view_used_last(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->admin()->create(['interface' => ['last_views' => [(string) $project->id => 'board']]]);

        $this->actingAs($user)->get(route('projects.show', [$project, 'dates' => 'overdue']), ['X-Livewire-Navigate' => '1'])->assertOk();
        $this->actingAs($user)->get(route('projects.show', [$project, 'dates' => 'none', 'status' => 'all']), ['X-Livewire-Navigate' => '1'])->assertOk();

        $this->assertSame('projects.board', $user->fresh()->projectRoute($project));
    }

    public function test_nothing_is_remembered_for_a_project_the_person_may_not_see(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('projects.board', $project), ['X-Livewire-Navigate' => '1'])->assertForbidden();

        $this->assertArrayNotHasKey('last_views', $user->fresh()->interfaceState());
    }

    public function test_the_columns_of_a_board_come_from_the_server(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->admin()->create(['interface' => ['board_collapsed' => [(string) $project->id => [7, 8]]]]);

        $this->actingAs($user)->get(route('projects.board', $project))->assertOk()->assertSee('?? [7,8]', false);
    }

    public function test_a_view_opened_by_a_link_from_outside_is_not_made_the_one_used_last(): void
    {
        $user = User::factory()->create(['interface' => ['mine_view' => 'board']]);

        $this->actingAs($user)->get(route('tasks.mine'))->assertOk();

        $this->assertSame('tasks.mine.board', $user->fresh()->mineTasksRoute());
    }

    public function test_keys_that_are_not_ids_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['board_collapsed' => [7 => ['abc' => true]]])->assertJsonValidationErrors('board_collapsed.7');
        $this->actingAs($user)->patchJson(route('interface.update'), ['board_collapsed' => [0 => [1 => true]]])->assertJsonValidationErrors('board_collapsed');
        $this->actingAs($user)->patchJson(route('interface.update'), ['mine_board_collapsed' => ['-3' => true]])->assertJsonValidationErrors('mine_board_collapsed');
        $this->actingAs($user)->patchJson(route('interface.update'), ['mine_board_collapsed' => ['99999999999999999999999' => true]])->assertJsonValidationErrors('mine_board_collapsed');

        $this->assertSame([], $user->fresh()->interfaceState());
    }

    public function test_the_unsorted_column_of_my_tasks_can_be_collapsed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['mine_board_collapsed' => [0 => true]])->assertOk();

        $this->assertSame([0], $user->fresh()->collapsedColumns());
    }

    public function test_the_stored_state_stays_small(): void
    {
        $user = User::factory()->create();

        foreach (range(0, 2) as $round) {
            $columns = collect(range(1, User::INTERFACE_MAX_COLUMNS))->mapWithKeys(fn (int $id) => [$round * 100 + $id => true])->all();
            $user->rememberInterface(['mine_board_collapsed' => $columns, 'board_collapsed' => [5 => $columns]]);
        }

        $this->assertCount(User::INTERFACE_MAX_COLUMNS, $user->fresh()->collapsedColumns());
        $this->assertCount(User::INTERFACE_MAX_COLUMNS, $user->fresh()->collapsedColumns(5));

        foreach (range(1, User::INTERFACE_MAX_BOARDS + 5) as $project) {
            $user->rememberInterface(['board_collapsed' => [$project => [1 => true]]]);
        }

        $boards = $user->fresh()->interfaceState()['board_collapsed'];

        $this->assertCount(User::INTERFACE_MAX_BOARDS, $boards);
        $this->assertArrayHasKey(User::INTERFACE_MAX_BOARDS + 5, $boards);
        $this->assertArrayNotHasKey(1, $boards);
    }

    public function test_a_board_without_collapsed_columns_is_dropped(): void
    {
        $user = User::factory()->create();

        $user->rememberInterface(['board_collapsed' => [3 => [9 => true]]]);
        $user->rememberInterface(['board_collapsed' => [3 => [9 => false]]]);

        $this->assertArrayNotHasKey('board_collapsed', $user->fresh()->interfaceState());
    }

    public function test_saving_does_not_count_as_changing_the_user(): void
    {
        $user = User::factory()->create();
        $updatedAt = $user->fresh()->updated_at;

        $this->travel(5)->minutes();
        $user->rememberInterface(['appearance' => 'dark']);

        $this->assertEquals($updatedAt, $user->fresh()->updated_at);
    }

    public function test_the_state_is_not_part_of_what_is_serialized_for_a_user(): void
    {
        $user = User::factory()->create(['interface' => ['appearance' => 'dark']]);

        $this->assertArrayNotHasKey('interface', $user->fresh()->toArray());
    }

    public function test_every_change_raises_the_revision_and_a_repeat_does_not(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'dark'])->assertOk()->assertExactJson(['rev' => 1]);
        $this->actingAs($user)->patchJson(route('interface.update'), ['sidebar_collapsed' => true])->assertExactJson(['rev' => 2]);
        $this->actingAs($user)->patchJson(route('interface.update'), ['sidebar_collapsed' => true])->assertExactJson(['rev' => 2]);
    }

    public function test_a_value_that_is_not_a_list_is_refused_with_a_message_not_an_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['board_collapsed' => 'x'])->assertUnprocessable();
        $this->actingAs($user)->patchJson(route('interface.update'), ['mine_board_collapsed' => 'x'])->assertUnprocessable();
    }

    public function test_every_write_raises_the_version_that_guards_the_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'dark'])->assertOk();
        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'dark'])->assertOk();
        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'light'])->assertOk();

        $this->assertSame(2, (int) $user->newQuery()->whereKey($user->id)->value('interface_rev'));
    }

    public function test_saving_for_a_person_who_is_gone_is_not_an_error(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertSame(0, $user->rememberInterface(['appearance' => 'dark']));
    }
}
