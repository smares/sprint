<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressiveWebAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_makes_sprint_installable_with_its_icons(): void
    {
        $response = $this->get(route('pwa.manifest'))->assertOk()->assertHeader('Content-Type', 'application/manifest+json');

        $response->assertJson(['name' => config('app.name'), 'start_url' => '/', 'scope' => '/', 'display' => 'standalone']);

        foreach ($response->json('icons') as $icon) {
            $this->assertFileExists(public_path($icon['src']));
            $this->assertSame($icon['sizes'], implode('x', array_slice((array) getimagesize(public_path($icon['src'])), 0, 2)));
        }

        $this->assertContains('maskable', array_column($response->json('icons'), 'purpose'));
    }

    public function test_every_page_links_the_manifest(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.index'))->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('Keine Verbindung. Änderungen können gerade nicht gespeichert werden.');
    }

    public function test_the_service_worker_is_served_from_the_root_with_the_files_of_this_build(): void
    {
        $response = $this->get('/sw.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

        $script = $response->getContent();
        $this->assertStringContainsString('const VERSION = "'.config('sprint.version').'-', $script);
        $this->assertStringContainsString('"/offline"', $script);
        $this->assertStringContainsString('"/icon-192.png"', $script);
        $this->assertStringContainsString("addEventListener('fetch'", $script);
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_offline_page_needs_no_login_and_carries_every_language(): void
    {
        $this->get(route('offline'))->assertOk()
            ->assertSee('data-locale="de"', false)->assertSee('data-locale="en"', false)
            ->assertSee('Keine Verbindung')->assertSee('No connection')
            ->assertSee(config('app.name').' braucht eine Internetverbindung.');
    }
}
