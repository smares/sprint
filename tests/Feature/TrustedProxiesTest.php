<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_without_trusted_proxies_forwarded_headers_are_ignored(): void
    {
        $this->fromProxy('10.0.0.2')->assertDontSee('https://localhost/forgot-password');
    }

    public function test_listed_proxies_are_trusted(): void
    {
        $this->trustProxies('10.0.0.1, 10.0.0.2');

        $this->fromProxy('10.0.0.2')->assertSee('https://localhost/forgot-password');
    }

    public function test_other_addresses_are_not_trusted(): void
    {
        $this->trustProxies('10.0.0.1, 10.0.0.2');

        $this->fromProxy('10.0.0.9')->assertDontSee('https://localhost/forgot-password');
    }

    public function test_a_star_trusts_every_proxy(): void
    {
        $this->trustProxies('*');

        $this->fromProxy('172.18.0.5')->assertSee('https://localhost/forgot-password');
    }

    private function trustProxies(string $proxies): void
    {
        config(['app.trusted_proxies' => $proxies]);
        (new AppServiceProvider($this->app))->boot();
    }

    /**
     * The login page as a proxy that terminated HTTPS would request it.
     */
    private function fromProxy(string $address): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->get('/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->assertOk();
    }
}
