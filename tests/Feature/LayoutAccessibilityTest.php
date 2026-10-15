<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_has_a_skip_link_a_main_region_and_one_top_level_heading(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('projects.index'))->assertOk();

        $response->assertSeeHtml('href="#main-content"');
        $response->assertSeeHtml('id="main-content"');
        $response->assertSeeHtml('role="main"');
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_the_menu_button_tells_whether_the_sidebar_is_open(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.index'))->assertSeeHtml('x-bind:aria-expanded="open"');
    }
}
