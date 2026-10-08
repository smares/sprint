<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

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
