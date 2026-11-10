<?php

namespace Tests\Feature;

use Tests\TestCase;

class DarkModeColorsTest extends TestCase
{
    public function test_the_grays_of_the_dark_mode_are_the_warm_ones_so_they_match_the_brown_of_the_background(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $block = substr($css, strpos($css, ':root.dark {'), 700);

        foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as $step) {
            $this->assertStringContainsString("--color-zinc-{$step}: var(--color-stone-{$step});", $block);
        }
    }
}
