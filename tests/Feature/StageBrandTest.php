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

    public function test_the_collapsed_column_strip_is_balanced_and_the_quick_add_button_is_no_pill(): void
    {
        $this->assertStringContainsString("[data-flux-kanban-column-header] {\n        padding-block-end: 1.25rem;", $this->css());
        $this->assertStringContainsString('class="w-full justify-start rounded-lg!"', file_get_contents(resource_path('views/components/quick-add.blade.php')));
    }

    public function test_the_head_of_the_flyout_covers_the_padding_above_it(): void
    {
        $view = file_get_contents(resource_path('views/pages/tasks/⚡show.blade.php'));

        $this->assertStringContainsString('class="sticky -top-5 z-20 -mx-5 -mt-5 mb-2 flex', $view);
    }

    public function test_a_ring_needs_the_keyboard_modals_dim_the_page_and_the_palette_offers_the_backgrounds(): void
    {
        $css = $this->css();
        $palette = file_get_contents(resource_path('views/components/⚡command-palette.blade.php'));
        $teams = file_get_contents(resource_path('views/pages/admin/⚡teams.blade.php'));

        // A ring after a dialog closed (the browser gives the focus back as a keyboard focus) must not show for the mouse
        $this->assertStringContainsString("html[data-keyboard] :focus-visible,\ninput:focus-visible,", $css);
        $this->assertStringNotContainsString("\n:focus-visible {", $css);
        $this->assertStringContainsString("[data-flux-modal] > dialog::backdrop {\n    background-color: var(--modal-backdrop);", $css);
        $this->assertStringContainsString("ambient-background', { mode: '", $palette);
        $this->assertStringContainsString('AmbientBackground::cases()', $palette);
        $this->assertStringContainsString('x-ref="newTeam"', $teams);
        $this->assertStringContainsString("{{ __('Create a new team') }}", $teams);
    }

    public function test_project_cards_do_not_fade_in_again_when_the_page_is_drawn_again_and_the_palette_items_carry_no_group_prefix(): void
    {
        // The cards are drawn anew after a dialog of the page closed; the fade-in of the board cards would show as a flicker
        $this->assertStringContainsString('board-card animate-none! glass!', file_get_contents(resource_path('views/components/project-card.blade.php')));

        $palette = file_get_contents(resource_path('views/components/⚡command-palette.blade.php'));
        $this->assertStringContainsString('<span class="sr-only">{{ __(\'Background\') }}: </span>{{ $mode->label() }}', $palette);
    }

    public function test_navigation_in_time_and_finish_with_reopen_are_button_groups_and_the_period_has_even_room(): void
    {
        $calendar = file_get_contents(resource_path('views/pages/projects/⚡calendar.blade.php'));
        $timeline = file_get_contents(resource_path('views/pages/projects/⚡timeline.blade.php'));
        $bar = file_get_contents(resource_path('views/components/selection-bar.blade.php'));

        $this->assertMatchesRegularExpression('/<flux:button\.group>\s*<flux:button icon="chevron-left" wire:click="previousMonth".*?wire:click="today".*?<\/flux:button\.group>\s*<flux:heading size="lg" class="mx-2">/s', $calendar);
        $this->assertMatchesRegularExpression('/<flux:button\.group>\s*<flux:button icon="chevron-left" wire:click="earlier".*?wire:click="today".*?<\/flux:button\.group>\s*<flux:text class="mx-2 /s', $timeline);
        $this->assertMatchesRegularExpression('/<flux:button\.group>\s*<flux:button size="sm" icon="check" wire:click="bulkComplete".*?wire:click="bulkReopen".*?<\/flux:button\.group>/s', $bar);
    }

    public function test_the_sort_choice_its_direction_and_its_reset_are_one_button_group(): void
    {
        $board = file_get_contents(resource_path('views/pages/projects/⚡board.blade.php'));

        $this->assertMatchesRegularExpression('/<flux:button\.group>\s*<flux:dropdown align="start">.*?wire:click="resetSort".*?<\/flux:button\.group>/s', $board);
    }
}
