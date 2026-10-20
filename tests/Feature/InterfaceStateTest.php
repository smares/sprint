<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterfaceStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_are_merged_into_what_is_kept(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'dark', 'sidebar_collapsed' => true])->assertNoContent();
        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'light'])->assertNoContent();

        $this->assertSame(['appearance' => 'light', 'sidebar_collapsed' => true], $user->fresh()->interfaceState());
    }

    public function test_a_device_that_did_not_see_a_change_does_not_take_it_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['board_collapsed' => [1 => [10, 11], 2 => [20]]]);
        $this->actingAs($user)->patchJson(route('interface.update'), ['board_collapsed' => [2 => []], 'sidebar_collapsed' => false]);

        $state = $user->fresh()->interfaceState();

        $this->assertSame([10, 11], $state['board_collapsed']['1']);
        $this->assertSame([], $state['board_collapsed']['2']);
        $this->assertFalse($state['sidebar_collapsed']);
    }

    public function test_columns_are_made_unique_and_whole_numbers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['mine_board_collapsed' => [3, 3, '4']])->assertNoContent();

        $this->assertSame([3, 4], $user->fresh()->interfaceState()['mine_board_collapsed']);
    }

    public function test_unknown_values_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('interface.update'), ['appearance' => 'purple'])->assertJsonValidationErrors('appearance');
        $this->actingAs($user)->patchJson(route('interface.update'), ['board_collapsed' => [1 => ['x']]])->assertJsonValidationErrors('board_collapsed.1.0');
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

        $this->actingAs($user)->get(route('tasks.mine'))->assertOk();
        $this->assertSame('list', $user->fresh()->interfaceState()['mine_view']);
        $this->actingAs($user->fresh())->get(route('projects.index'))->assertDontSee(route('tasks.mine.board'), false);

        $this->actingAs($user)->get(route('tasks.mine.board'))->assertOk();
        $this->assertSame('board', $user->fresh()->interfaceState()['mine_view']);

        $this->actingAs($user->fresh())->get(route('projects.index'))->assertSee(route('tasks.mine.board'), false);
        $this->assertSame('tasks.mine.board', $user->fresh()->mineTasksRoute());
    }

    public function test_the_columns_of_a_board_come_from_the_server(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->admin()->create(['interface' => ['board_collapsed' => [(string) $project->id => [7, 8]]]]);

        $this->actingAs($user)->get(route('projects.board', $project))->assertOk()->assertSee('collapsed: [7,8]', false);
    }
}
