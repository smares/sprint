<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_header_offers_the_appearance_switch_with_three_choices(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('projects.index'))->assertOk();

        $response->assertSee('$flux.appearance', false);

        foreach (['Hell', 'Dunkel', 'System'] as $label) {
            $response->assertSee('aria-label="'.$label.'"', false);
        }
    }

    public function test_the_mobile_menu_has_all_destinations_for_normal_users(): void
    {
        $this->actingAs(User::factory()->create())->get(route('projects.index'))
            ->assertOk()
            ->assertSee('data-flux-sidebar-toggle', false)
            ->assertSeeInOrder(['Projekte', 'Meine Aufgaben', 'Posteingang', 'Suche'])
            ->assertDontSee(route('admin.users'), false)
            ->assertDontSee(route('admin.teams'), false);
    }

    public function test_admins_also_get_the_admin_pages_in_the_mobile_menu(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->get(route('projects.index'))->assertOk();

        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), route('admin.users')));
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), route('admin.teams')));
    }

    public function test_below_large_screens_a_view_switcher_sits_under_the_project_title(): void
    {
        $project = Project::factory()->create();
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('projects.board', $project))->assertOk()->getContent();

        $start = (int) strpos($html, 'data-view-switcher');
        $switcher = substr($html, (int) strrpos(substr($html, 0, $start), '<div'), 60000);

        // Hidden where the sidebar lists the views itself; icons with accessible names on phones, the open view marked
        $this->assertStringContainsString('lg:hidden', $switcher);
        foreach (['Liste', 'Kalender', 'Zeitleiste', 'Statistik'] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $switcher);
        }
        $this->assertStringContainsString('aria-current="page"', $switcher);
        $this->assertStringContainsString('data-sidebar-views', $html);
    }

    public function test_the_task_views_use_the_whole_width_and_other_pages_keep_the_container(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->admin()->create();

        foreach (['projects.show', 'projects.board', 'projects.calendar', 'projects.timeline'] as $route) {
            $this->actingAs($user)->get(route($route, $project))->assertOk()->assertSee('data-wide', false);
        }

        foreach (['tasks.mine', 'tasks.mine.board'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk()->assertSee('data-wide', false);
        }

        foreach ([route('projects.statistics', $project), route('projects.index'), route('profile')] as $url) {
            $this->actingAs($user)->get($url)->assertOk()->assertDontSee('data-wide', false);
        }
    }

    public function test_guests_get_no_navigation(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-flux-sidebar-toggle', false)->assertDontSee('Darstellung');
    }

    public function test_the_timeline_view_uses_the_turn_arrow_icon_in_the_sidebar(): void
    {
        $project = Project::factory()->create();
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('projects.board', $project))->getContent();
        $html = substr($html, (int) strpos($html, 'data-sidebar-views'));

        $this->assertStringContainsString('Zeitleiste', $html);

        $pathOf = function (string $icon) use ($html): bool {
            foreach (['micro', 'mini', 'outline'] as $variant) {
                preg_match('/<path[^>]*d="([^"]+)"/', Blade::render('<flux:icon.'.$icon.' variant="'.$variant.'" />'), $matches);

                if (isset($matches[1]) && str_contains($html, 'd="'.$matches[1].'"')) {
                    return true;
                }
            }

            return false;
        };

        $this->assertTrue($pathOf('arrow-turn-down-right'));
        $this->assertFalse($pathOf('chart-bar'));
    }
}
