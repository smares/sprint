<?php

namespace Tests\Feature;

use App\Enums\AmbientBackground;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmbientBackgroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_background_is_a_decorative_canvas_on_every_page(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="ambient" aria-hidden="true" data-mode="animated"><canvas></canvas></div>', $html);
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

    public function test_the_background_is_animated_by_default_and_the_choice_in_the_user_menu_is_kept_with_the_interface(): void
    {
        $user = User::factory()->create();

        $this->assertSame(AmbientBackground::Animated, $user->ambientBackground());
        $this->actingAs($user)->get(route('profile'))->assertOk()->assertSeeHtml('data-mode="animated"');

        foreach (['still', 'off'] as $mode) {
            $this->actingAs($user)->patchJson(route('interface.update'), ['ambient' => $mode])->assertOk();

            $this->assertSame($mode, $user->fresh()->ambientBackground()->value);
            $this->actingAs($user->fresh())->get(route('profile'))->assertOk()->assertSeeHtml('data-mode="'.$mode.'"');
        }

        $this->actingAs($user)->patchJson(route('interface.update'), ['ambient' => 'animated'])->assertOk();
        $this->assertSame(AmbientBackground::Animated, $user->fresh()->ambientBackground());
    }

    public function test_an_unknown_choice_is_refused_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $user->rememberInterface(['ambient' => 'still']);

        $this->actingAs($user)->patchJson(route('interface.update'), ['ambient' => 'confetti'])->assertUnprocessable();

        $this->assertSame(AmbientBackground::Still, $user->fresh()->ambientBackground());
    }

    public function test_the_user_menu_offers_the_three_choices_like_the_appearance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile'))->assertOk()
            ->assertSeeHtml('saveInterface({ ambient: mode })')
            ->assertSeeHtml('value="animated"')
            ->assertSeeHtml('value="still"')
            ->assertSeeHtml('value="off"');
    }
}
