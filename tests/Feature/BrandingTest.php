<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_is_called_sprint(): void
    {
        $this->assertSame('Sprint', config('app.name'));
    }

    public function test_page_titles_end_with_the_product_name(): void
    {
        $project = Project::factory()->create(['name' => 'Website']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('<title>Website – Sprint</title>', false);
    }

    public function test_pages_show_the_mark_and_link_the_icons(): void
    {
        foreach ([route('login'), route('password.request')] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
                ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false)
                ->assertSee('M32 18l14 14-14 14', false);
        }

        $this->actingAs(User::factory()->create())->get(route('projects.index'))->assertOk()->assertSee('M32 18l14 14-14 14', false);
    }

    public function test_the_icon_files_exist(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }

        $this->assertStringContainsString('M32 18l14 14-14 14', (string) file_get_contents(public_path('favicon.svg')));
    }
}
