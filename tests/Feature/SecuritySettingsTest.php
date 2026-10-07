<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => 'geheim123']);
        $this->actingAs($this->user);
    }

    private function confirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function passkeyFor(User $user, string $name = 'Laptop'): Passkey
    {
        $passkey = new Passkey(['name' => $name, 'credential_id' => 'cred-'.$name.$user->id, 'credential' => ['id' => 'x']]);
        $passkey->user()->associate($user);
        $passkey->save();

        return $passkey;
    }

    public function test_the_profile_shows_the_security_section(): void
    {
        $this->get(route('profile'))->assertOk()->assertSee('Zwei-Faktor-Anmeldung')->assertSee('Passkeys')->assertSee('Passwort bestätigen');
    }

    public function test_changes_need_a_recent_password_confirmation(): void
    {
        Livewire::test('security')->call('enableTwoFactor')->assertStatus(423);

        $this->assertNull($this->user->fresh()->two_factor_secret);
    }

    public function test_confirming_the_password_unlocks_the_controls(): void
    {
        $page = Livewire::test('security')->assertDontSee('Einrichten');

        $page->set('password', 'falsch')->call('confirmPassword')->assertHasErrors('password')->assertDontSee('Einrichten');
        $page->set('password', 'geheim123')->call('confirmPassword')->assertHasNoErrors()->assertSee('Einrichten');
        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function test_the_confirmation_is_rate_limited(): void
    {
        $page = Livewire::test('security');

        foreach (range(1, 5) as $attempt) {
            $page->set('password', 'falsch')->call('confirmPassword');
        }

        $page->set('password', 'geheim123')->call('confirmPassword')->assertHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_two_factor_is_set_up_confirmed_and_switched_on(): void
    {
        $this->confirmed();
        $page = Livewire::test('security')->call('enableTwoFactor');

        $user = $this->user->fresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
        $page->assertSee('Nicht abgeschlossen')->assertSee('<svg', false);

        $page->set('code', '000000')->call('confirmTwoFactor')->assertHasErrors('code');
        $this->assertFalse($this->user->fresh()->hasEnabledTwoFactorAuthentication());

        $code = (new Google2FA)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
        $page->set('code', $code)->call('confirmTwoFactor')->assertHasNoErrors()->assertSet('showRecoveryCodes', true);

        $this->assertTrue($this->user->fresh()->hasEnabledTwoFactorAuthentication());
        $page->assertSee($this->user->fresh()->recoveryCodes()[0]);
    }

    public function test_recovery_codes_can_be_hidden_and_renewed(): void
    {
        $this->confirmed();
        app(EnableTwoFactorAuthentication::class)($this->user);
        $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $old = $this->user->fresh()->recoveryCodes();

        $page = Livewire::test('security')->assertDontSee($old[0])->call('toggleRecoveryCodes')->assertSee($old[0])->call('toggleRecoveryCodes')->assertDontSee($old[0]);

        $page->call('regenerateRecoveryCodes');
        $this->assertNotSame($old, $this->user->fresh()->recoveryCodes());
        $this->assertCount(8, $this->user->fresh()->recoveryCodes());
    }

    public function test_two_factor_can_be_switched_off_or_the_setup_cancelled(): void
    {
        $this->confirmed();
        $page = Livewire::test('security')->call('enableTwoFactor');

        $page->call('disableTwoFactor');
        $this->assertNull($this->user->fresh()->two_factor_secret);

        $page->call('enableTwoFactor');
        $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $page->call('disableTwoFactor');

        $this->assertFalse($this->user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertNull($this->user->fresh()->two_factor_recovery_codes);
    }

    public function test_confirming_without_a_setup_is_refused(): void
    {
        $this->confirmed();

        Livewire::test('security')->set('code', '123456')->call('confirmTwoFactor')->assertNotFound();
    }

    public function test_passkeys_are_listed_and_removed_only_for_the_owner(): void
    {
        $this->confirmed();
        $mine = $this->passkeyFor($this->user, 'Büro-Laptop');
        $foreign = $this->passkeyFor(User::factory()->create(), 'Fremd');

        $page = Livewire::test('security')->assertSee('Büro-Laptop')->assertDontSee('Fremd');

        Livewire::test('security')->call('deletePasskey', $foreign->id)->assertNotFound();
        $this->assertNotNull(Passkey::find($foreign->id));

        $page->call('deletePasskey', $mine->id)->assertDontSee('Büro-Laptop');
        $this->assertNull(Passkey::find($mine->id));
    }

    public function test_passkeys_cannot_be_removed_without_confirmation(): void
    {
        $mine = $this->passkeyFor($this->user);

        Livewire::test('security')->call('deletePasskey', $mine->id)->assertStatus(423);

        $this->assertNotNull(Passkey::find($mine->id));
    }

    public function test_passkey_registration_options_need_login_and_confirmation(): void
    {
        auth()->logout();
        $this->getJson(route('passkey.registration-options'))->assertUnauthorized();

        $this->actingAs($this->user)->getJson(route('passkey.registration-options'))->assertStatus(423);

        $this->confirmed()->getJson(route('passkey.registration-options'))->assertOk()->assertJsonStructure(['options' => ['challenge', 'rp', 'user']]);
    }

    public function test_passkey_login_options_are_only_for_guests(): void
    {
        auth()->logout();
        $this->getJson(route('passkey.login-options'))->assertOk()->assertJsonStructure(['options' => ['challenge']]);

        $this->actingAs($this->user)->getJson(route('passkey.login-options'))->assertRedirect();
    }

    public function test_deactivated_people_cannot_sign_in_with_a_passkey(): void
    {
        $passkey = $this->passkeyFor($this->user);

        $this->assertTrue(Passkeys::allowsLogin(Request::create('/'), $passkey));

        $this->user->deactivate();
        $this->assertFalse(Passkeys::allowsLogin(Request::create('/'), $passkey->fresh()));
    }

    public function test_the_fortify_routes_that_would_bypass_the_login_are_not_registered(): void
    {
        auth()->logout();

        $this->postJson('/login', ['email' => $this->user->email, 'password' => 'geheim123'])->assertStatus(405);
        $this->assertGuest();
        $this->assertFalse(app('router')->has('two-factor.login'));
        $this->assertFalse(app('router')->has('register'));
    }

    public function test_admins_can_reset_the_second_factors_of_a_locked_out_person(): void
    {
        $admin = User::factory()->admin()->create();
        $locked = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($locked);
        $locked->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->passkeyFor($locked);

        Livewire::actingAs($admin)->test('pages::admin.users')->assertSee('Zwei-Faktor und Passkeys zurücksetzen')->call('resetSecondFactors', $locked->id);

        $locked->refresh();
        $this->assertFalse($locked->hasEnabledTwoFactorAuthentication());
        $this->assertNull($locked->two_factor_secret);
        $this->assertSame(0, $locked->passkeys()->count());
    }

    public function test_only_admins_can_reset_second_factors(): void
    {
        Livewire::test('pages::admin.users')->assertForbidden();
    }

    public function test_deactivating_someone_does_not_touch_nor_leak_the_secrets(): void
    {
        app(EnableTwoFactorAuthentication::class)($this->user);

        $this->assertArrayNotHasKey('two_factor_secret', $this->user->fresh()->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $this->user->fresh()->toArray());
    }
}
