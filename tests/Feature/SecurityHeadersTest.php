<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_forbid_foreign_framing_foreign_images_and_plugins(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("img-src 'self' data: blob:", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_https_is_only_enforced_once_the_site_runs_on_https(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.index'))->assertHeaderMissing('Strict-Transport-Security');
        $this->get(str_replace('http://', 'https://', route('projects.index')))->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
