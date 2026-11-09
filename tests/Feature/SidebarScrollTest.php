<?php

namespace Tests\Feature;

use Tests\TestCase;

class SidebarScrollTest extends TestCase
{
    public function test_the_sidebar_never_scrolls_sideways_and_the_collapsed_one_shows_no_scrollbar(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $block = substr($css, strpos($css, '[data-flux-sidebar] {'), 200);

        $this->assertStringContainsString('overflow-x: hidden', $block);
        $this->assertStringContainsString('&[data-flux-sidebar-collapsed-desktop]', $block);
        $this->assertStringContainsString('scrollbar-width: none', $block);
    }
}
