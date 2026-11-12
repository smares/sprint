<?php

namespace Tests\Feature;

use Tests\TestCase;

class StageBrandTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    public function test_a_field_with_an_error_keeps_the_red_border_and_does_not_get_the_border_of_a_field(): void
    {
        $css = $this->css();

        // The border of the fields excludes the invalid ones, which have their own rule (red, with a second line, lighter in the dark mode)
        $this->assertStringContainsString("input[data-flux-control]:not([type='checkbox'], [type='radio'], [data-invalid], [aria-invalid='true'])", $css);
        $this->assertStringContainsString("[data-flux-control][aria-invalid='true'] {\n    border-color: var(--color-red-500);", $css);
        $this->assertStringContainsString('border-color: #ff6b6b;', $css);
        $this->assertStringContainsString('--color-red-500: #e8171e;', $css);
    }

    public function test_the_primary_button_gets_darker_on_hover_and_when_pressed_only_where_there_is_a_hover(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('@media (hover: hover) {', $css);
        $this->assertStringContainsString("[data-flux-button][class*='bg-[var(--color-accent)]']:hover:not(:disabled)", $css);
        $this->assertStringContainsString('--stage-action-hover: #1b50e0;', $css);
        $this->assertStringContainsString('--stage-action-active: #153cb4;', $css);
        $this->assertStringContainsString('--stage-action-hover: #9bb3ff;', $css);

        // The class that Flux gives its primary button is the one the rule looks for
        $flux = file_get_contents(base_path('vendor/livewire/flux/stubs/resources/views/flux/button/index.blade.php'));
        $this->assertStringContainsString("'bg-[var(--color-accent)] hover:", $flux);
    }

    public function test_links_are_underlined_and_the_keys_in_a_menu_show_the_ring(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\[data-flux-link\],\n\.markdown a \{\n    text-decoration-line: underline;/', $css);
        $this->assertStringContainsString("html[data-keyboard] [data-flux-menu-item][data-active],\nhtml[data-keyboard] ui-option[data-active] {\n    outline: 3px solid var(--stage-focus);", $css);
        $this->assertStringContainsString("outline: 3px solid var(--stage-focus);\n    outline-offset: 3px;", $css);
    }

    public function test_a_selected_row_or_card_has_a_fill_and_a_border_besides_the_tick(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('tr[data-task-id][data-task-selected] > td {', $css);
        $this->assertStringContainsString('box-shadow: inset 0 0 0 2px var(--stage-selected-border);', $css);
        $this->assertStringContainsString('--stage-selected-bg: #e3ebff;', $css);
        $this->assertStringContainsString('--stage-selected-bg: #1c2c5e;', $css);
    }

    public function test_the_pieces_of_the_manual_have_their_places_small_things_are_pills_and_highlights_are_pink(): void
    {
        $css = $this->css();

        $this->assertStringContainsString("[data-flux-badge],\n.color-chip {\n    border-radius: 999px;", $css);
        $this->assertStringContainsString('--color-highlight: #e5007a;', $css);
        $this->assertStringContainsString('--color-highlight: #ff4da6;', $css);
        $this->assertStringContainsString('--color-accent-content: #1b50e0;', $css);
        $this->assertStringNotContainsString('orange', preg_replace('/\/\*.*?\*\//s', '', $css));
    }

    public function test_today_is_pink_in_the_date_picker_and_the_ring_of_the_command_palette_lies_inside(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('ui-calendar td[data-today]:not([data-selected]) button {', $css);
        $this->assertStringContainsString("[data-flux-command-input]:has(input:focus-visible) {\n    outline: 3px solid var(--stage-focus);\n    outline-offset: -3px;", $css);
        $this->assertStringContainsString('ui-calendar td[data-today]:not([data-selected]) button div.absolute > div {', $css);
    }

    public function test_the_panel_of_the_command_palette_is_a_rounded_box_and_the_dialog_around_it_has_no_fill_of_its_own(): void
    {
        $css = $this->css();
        $palette = file_get_contents(resource_path('views/components/⚡command-palette.blade.php'));

        $this->assertStringContainsString('<flux:command class="glass-popover! inline-flex max-h-[76vh] flex-col rounded-[14px]!', $palette);
        $this->assertStringContainsString('[data-flux-modal] > dialog:not([data-flux-flyout], .bg-transparent) {', $css);
    }

    public function test_dark_surfaces_lean_to_dark_blue_and_menu_rings_follow_the_keyboard(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('--color-zinc-800: #1c2038;', $css);
        $this->assertStringContainsString('--glass-card-rgb: 24 28 48;', $css);
        $this->assertStringNotContainsString('--glass-card-rgb: 45 48 51;', $css);
        $this->assertStringContainsString('document.documentElement.dataset.keyboard', $js);
        $this->assertStringContainsString("th[data-flux-column],\n.column-head,", $css);
        $this->assertStringContainsString('--color-highlight-content: #c70069;', $css);
        $this->assertStringContainsString("@media (prefers-reduced-transparency: reduce) {\n    :root,\n    :root.dark {", $css);
    }

    public function test_the_sticky_table_head_looks_like_the_head_of_the_other_tables_and_the_first_card_keeps_its_edge(): void
    {
        $css = $this->css();
        $list = file_get_contents(resource_path('views/pages/projects/⚡show.blade.php'));

        $this->assertStringContainsString('@utility glass-head {', $css);
        // Opaque, so that rows passing beneath it do not show through the labels
        $this->assertStringContainsString('background-color: rgb(var(--glass-head-rgb));', $css);
        $this->assertStringNotContainsString('--glass-head-a', $css);
        $this->assertStringContainsString('<flux:table.columns sticky class="glass-head">', $list);
        $this->assertStringContainsString('padding-block-start: 0.5rem;', $css);
    }

    public function test_the_numbers_in_badges_are_round_for_one_digit(): void
    {
        $css = $this->css();

        $this->assertStringContainsString("@utility count-badge {\n    justify-content: center;\n    min-width: 1.5rem;", $css);

        foreach (['pages/projects/⚡show', 'pages/projects/⚡board'] as $view) {
            $this->assertStringContainsString('class="count-badge"', file_get_contents(resource_path("views/{$view}.blade.php")), $view);
        }
    }

    public function test_the_head_of_a_board_column_has_the_fill_and_tint_of_a_table_head(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(".column-head,\n[data-flux-kanban-column-header] {", $css);
        $this->assertStringContainsString("[data-flux-kanban-column-header] {\n    background-color: rgb(var(--glass-head-rgb));", $css);
        // The head has a line below it like a table head, and the column a border all around (the status line stays on top)
        $this->assertStringContainsString('border-block-end: 1px solid color-mix(in srgb, var(--color-zinc-800) 10%, transparent);', $css);
        $this->assertStringContainsString("[data-flux-kanban-column] > div {\n    border-inline: 1px solid var(--glass-border);", $css);
    }
}
