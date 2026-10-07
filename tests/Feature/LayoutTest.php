<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_view_switcher_buttons_keep_accessible_names_without_visible_labels_on_phones(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();

        $response = $this->actingAs($admin)->get(route('projects.board', $project))->assertOk();

        foreach (['Liste', 'Kalender', 'Zeitleiste'] as $label) {
            $response->assertSee('aria-label="'.$label.'"', false);
        }
        $response->assertSee('max-sm:sr-only', false);
    }

    public function test_guests_get_no_navigation(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-flux-sidebar-toggle', false)->assertDontSee('Darstellung');
    }
}
