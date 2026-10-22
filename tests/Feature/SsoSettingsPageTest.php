<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SsoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SsoSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_open_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.sso'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.sso'))->assertOk()->assertSee('Single Sign-on');
        $this->actingAs(User::factory()->admin()->create())->get(route('projects.index'))->assertSee(route('admin.sso'), false);
        $this->actingAs(User::factory()->create())->get(route('projects.index'))->assertDontSee(route('admin.sso'), false);
    }

    public function test_it_is_switched_on_with_the_domains_and_both_are_kept(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sso')
            ->assertSet('enabled', false)
            ->set('enabled', true)->set('domains', "Example.com, @team.example.org\nexample.com")
            ->call('save')->assertHasNoErrors()
            ->assertSet('domains', "example.com\nteam.example.org");

        $sso = app(SsoSettings::class);
        $this->assertTrue($sso->isEnabled());
        $this->assertSame(['example.com', 'team.example.org'], $sso->domains());

        Livewire::actingAs($admin)->test('pages::admin.sso')->assertSet('enabled', true)->assertSet('domains', "example.com\nteam.example.org");
    }

    public function test_it_cannot_be_switched_on_without_the_credentials_of_the_server(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.sso')
            ->assertSee('Google-Zugangsdaten fehlen')
            ->set('enabled', true)->call('save')->assertHasErrors('enabled');

        $this->assertFalse(app(SsoSettings::class)->isEnabled());
    }

    public function test_a_text_that_is_no_domain_is_refused(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

        foreach (['not a domain!', 'localhost', 'a..b.com', '-x.com', 'https://example.com'] as $text) {
            Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.sso')
                ->set('domains', $text)->call('save')->assertHasErrors('domains');
        }

        $this->assertSame([], app(SsoSettings::class)->domains());
    }

    public function test_an_administrator_cannot_be_replaced_by_somebody_else_in_a_request(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::admin.sso')->assertForbidden();
    }
}
