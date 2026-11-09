<?php

namespace Tests\Feature;

use Tests\TestCase;

class PointerCursorTest extends TestCase
{
    public function test_everything_that_can_be_clicked_shows_the_hand(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $base = substr($css, strpos($css, '@layer base {'), 900);

        // Tailwind 4 leaves buttons at the arrow of the browser; the lowest layer gives them the hand, disabled ones excepted
        $this->assertStringContainsString('button:not(:disabled)', $base);
        $this->assertStringContainsString("[role='tab']", $base);
        $this->assertStringContainsString("[role='menuitem']:not([aria-disabled='true'])", $base);
        $this->assertStringContainsString('cursor: pointer', $base);

        // The select fields of Flux ask for the arrow with a utility class, which only a rule outside the layer overrides
        $this->assertStringContainsString("button[role='combobox']:not(:disabled)", $css);
    }
}
