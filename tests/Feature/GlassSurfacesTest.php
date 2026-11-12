<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Sortable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlassSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    public function test_the_levels_of_glass_are_utilities_built_from_a_color_and_an_opacity(): void
    {
        $css = $this->css();

        foreach (['glass', 'glass-card', 'glass-bar', 'glass-sticky', 'glass-popover', 'surface'] as $utility) {
            $this->assertStringContainsString("@utility {$utility} {", $css);
        }

        // The sticky level is nearly opaque and has no blur
        preg_match('/@utility glass-sticky \{(.*?)\}/s', $css, $sticky);
        $this->assertStringNotContainsString('backdrop-filter', $sticky[1]);
        $this->assertMatchesRegularExpression('/--glass-sticky-a: 0\.9\d;/', $css);
    }

    public function test_glass_is_opaque_where_it_cannot_or_should_not_be_transparent(): void
    {
        $css = $this->css();
        $opaque = ['--glass-a: 1;', '--glass-card-a: 1;', '--glass-popover-a: 1;', '--glass-sticky-a: 1;', '--glass-blur: none;', '--glass-blur-popover: none;'];

        foreach (['@supports not ((backdrop-filter: blur(1px))', '@media (prefers-reduced-transparency: reduce)', ":root:has(.ambient[data-mode='off'])"] as $condition) {
            $block = substr($css, strpos($css, $condition), 450);

            foreach ($opaque as $declaration) {
                $this->assertStringContainsString($declaration, $block, $condition);
            }
        }
    }

    public function test_the_grays_and_the_accent_are_those_of_the_stage_brand_manual(): void
    {
        $css = $this->css();
        $grays = [50 => '#f6f7fb', 100 => '#eceef4', 200 => '#dde0ea', 300 => '#c3c8d6', 400 => '#8e94a6', 500 => '#5b6070', 600 => '#454957', 700 => '#32353f', 800 => '#242629', 900 => '#1a1b1d'];

        foreach ($grays as $step => $hex) {
            $this->assertStringContainsString("--color-zinc-{$step}: {$hex};", $css);
        }

        // Action blue for everything that can be clicked, accent pink for highlights; the dark mode has the lighter roles
        $this->assertStringContainsString('--color-accent: var(--color-stage-blue);', $css);
        $this->assertStringContainsString('--color-stage-pink: #e5007a;', $css);
        $this->assertStringContainsString('--color-accent: #7c9bff;', $css);
        $this->assertStringContainsString('--color-accent-foreground: #1a1b1d;', $css);

        // Text in gray 400 does not reach the contrast of the manual: in the light mode gray 400 is as dark as gray 500
        $this->assertStringContainsString('--color-zinc-400: var(--color-zinc-500);', $css);
    }

    public function test_the_typeface_is_delivered_from_here_and_nothing_comes_from_a_font_service(): void
    {
        $css = $this->css();

        $this->assertStringContainsString("font-family: 'Public Sans Variable';", $css);
        $this->assertStringContainsString("--font-sans: 'Public Sans Variable'", $css);
        $this->assertFileExists(resource_path('fonts/public-sans/public-sans-latin-wght-normal.woff2'));
        $this->assertFileExists(resource_path('fonts/public-sans/OFL.txt'));
        $this->assertStringNotContainsString('fonts.googleapis.com', $css);
        $this->assertStringNotContainsString('fonts.googleapis.com', file_get_contents(resource_path('views/layouts/app.blade.php')));
    }

    public function test_bars_and_heads_that_stick_use_the_sticky_level_and_the_sidebar_the_bar_level(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $project->setRole($user, ProjectRole::Editor);
        Task::factory()->for($project)->create(['start_date' => '2026-11-10', 'due_date' => '2026-11-12']);
        $this->actingAs($user);

        foreach (['pages::projects.show', 'pages::projects.calendar', 'pages::projects.timeline'] as $view) {
            $this->assertStringContainsString('glass-sticky', Livewire::test($view, ['project' => $project])->html(), $view);
        }

        $this->get(route('projects.show', $project))->assertOk()->assertSee('glass-bar', false);
    }

    public function test_the_task_page_has_cards_that_come_to_the_front_while_something_opens_in_them_and_the_flyout_has_none(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $project->setRole($user, ProjectRole::Editor);
        $task = Task::factory()->for($project)->create();
        $this->actingAs($user);

        $page = Livewire::test('pages::tasks.show', ['task' => $task])->html();
        $panel = Livewire::test('pages::tasks.show', ['task' => $task, 'panel' => true])->html();

        // A glass area is a stacking context of its own, so the list of a mention would lie under the next card without this
        $this->assertSame(5, substr_count($page, 'focus-within:relative focus-within:z-10'));
        $this->assertStringNotContainsString('rounded-2xl', $panel);
        $this->assertStringContainsString('glass-sticky', $panel);
    }

    public function test_a_card_that_is_dragged_by_touch_follows_the_finger_on_the_body(): void
    {
        $this->assertStringContainsString('fallbackOnBody: true', Sortable::CONFIG);
        $this->assertStringContainsString('fallbackOnBody: true', Sortable::CONFIG_MOVE_ONLY);
    }
}
