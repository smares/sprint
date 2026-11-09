<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmbientBackgroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_background_is_decorative_and_has_three_patches_on_every_page(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="ambient" aria-hidden="true"><span></span><span></span><span></span></div>', $html);
    }

    public function test_the_drift_is_calculated_runs_in_a_loop_and_stops_where_motion_is_not_wanted(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $block = substr($css, strpos($css, '.ambient {'), 3500);

        // Calculated from one number, with whole-number frequencies so that the loop has no seam
        $this->assertStringContainsString('@property --ambient-t', $css);
        $this->assertStringContainsString('sin((var(--fx) * var(--ambient-t) + var(--px)) * 1turn)', $block);
        $this->assertStringContainsString('cos((var(--fy) * var(--ambient-t) + var(--py)) * 1turn)', $block);
        $this->assertMatchesRegularExpression('/--fx: \d+;/', $block);
        $this->assertMatchesRegularExpression('/--fy: \d+;/', $block);
        $this->assertDoesNotMatchRegularExpression('/--f[xy]: \d+\.\d/', $block);

        // Only moves for people who have not asked for less motion; not at all with forced colors and in print
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: no-preference\) \{\s*animation: ambient-drift/', $block);
        $this->assertStringContainsString('@media (forced-colors: active), print', $block);
        $this->assertStringContainsString('pointer-events: none', $block);
    }
}
