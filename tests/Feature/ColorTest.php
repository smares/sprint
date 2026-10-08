<?php

namespace Tests\Feature;

use App\Color;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_hex_names_and_garbage_are_normalized(): void
    {
        $this->assertSame('#ef4444', Color::normalize('#EF4444'));
        $this->assertSame('#ef4444', Color::normalize('red'));
        $this->assertSame('#3b82f6', Color::normalize('Blue'));
        $this->assertSame(Color::FALLBACK, Color::normalize('neon'));
        $this->assertSame(Color::FALLBACK, Color::normalize(null));
        $this->assertSame(Color::FALLBACK, Color::normalize('#abc'));
        $this->assertSame(Color::FALLBACK, Color::normalize('red; background:url(x)'));
    }

    public function test_hex_is_strictly_checked(): void
    {
        $this->assertTrue(Color::isHex('#00ff7F'));

        foreach (['00ff7f', '#00ff7', '#00ff7fa', '#gg0000', null, 12, ''] as $invalid) {
            $this->assertFalse(Color::isHex($invalid));
        }
    }

    public function test_new_items_cycle_through_the_palette(): void
    {
        $count = count(Color::hexes());

        $this->assertSame(Color::hexes()[0], Color::next(0));
        $this->assertSame(Color::hexes()[1], Color::next($count + 1));
    }

    public function test_defaults_use_hex_colors_everywhere(): void
    {
        $project = Project::factory()->create();

        foreach ($project->statuses as $status) {
            $this->assertTrue(Color::isHex($status->color));
        }

        $priority = $project->customFields()->where('name', 'Priorität')->firstOrFail();
        foreach ($priority->options as $option) {
            $this->assertTrue(Color::isHex($option->color));
        }
        $this->assertInstanceOf(CustomField::class, $priority);
    }

    public function test_badges_carry_the_color_as_a_variable_and_reject_injection(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $project->statuses()->where('name', 'Offen')->update(['color' => '#123456']);
        Task::factory()->for($project)->create(['title' => 'Test']);

        $this->actingAs($admin)->get(route('projects.show', $project))->assertOk()->assertSee('--badge: #123456', false);

        $project->statuses()->where('name', 'Offen')->update(['color' => '"><script>alert(1)</script>']);

        $this->get(route('projects.show', $project))->assertOk()->assertDontSee('<script>alert(1)', false)->assertSee('--badge: '.Color::FALLBACK, false);
    }

    public function test_calendar_and_timeline_use_the_status_color(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $project->statuses()->where('name', 'Offen')->update(['color' => '#fde047']);
        Task::factory()->for($project)->create(['title' => 'Termin', 'due_date' => now()->startOfMonth()->addDays(9)]);

        $this->actingAs($admin)->get(route('projects.calendar', $project))->assertOk()->assertSee('--badge: #fde047', false);
        $this->get(route('projects.timeline', $project))->assertOk()->assertSee('--badge: #fde047', false)->assertSee('color-chip', false);
    }
}
