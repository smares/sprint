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

    public function test_every_gray_of_the_dark_mode_is_a_warm_one_and_secondary_text_is_darker_in_the_light_mode(): void
    {
        $css = $this->css();

        $this->assertSame(11, preg_match_all('/--color-zinc-(\d+): var\(--color-stone-\1\);/', $css));
        $this->assertStringContainsString(':root:not(.dark) {', $css);
        $this->assertStringContainsString('--color-zinc-500: var(--color-zinc-600);', $css);
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
