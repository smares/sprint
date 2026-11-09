<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmbientBackgroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_background_is_a_decorative_canvas_on_every_page(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="ambient" aria-hidden="true"><canvas></canvas></div>', $html);
    }

    public function test_the_layer_is_out_of_the_way_and_gone_with_forced_colors_and_in_print(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $block = substr($css, strpos($css, '.ambient {'), 600);

        $this->assertStringContainsString('pointer-events: none', $block);
        $this->assertStringContainsString('z-index: -10', $block);
        $this->assertStringContainsString('@media (forced-colors: active), print', $block);
    }

    public function test_the_drawing_is_calculated_follows_the_clock_and_rests_where_motion_is_not_wanted(): void
    {
        $js = file_get_contents(resource_path('js/ambient.js'));

        $this->assertStringContainsString('Math.sin(', $js);
        $this->assertStringContainsString('Date.now() / 1000', $js);
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $js);
        $this->assertStringContainsString("matchMedia('(forced-colors: active)')", $js);
        $this->assertStringContainsString('document.hidden', $js);
        $this->assertStringContainsString("import { startAmbientBackground } from './ambient.js'", file_get_contents(resource_path('js/app.js')));
    }
}
