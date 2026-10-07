<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'anna@example.com', 'password' => 'geheim123']);
        app(EnableTwoFactorAuthentication::class)($this->user);
        $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    private function validCode(): string
    {
        return (new Google2FA)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($this->user->fresh()->two_factor_secret));
    }

    private function passwordStep()
    {
        return Livewire::test('pages::login')->set('email', 'anna@example.com')->set('password', 'geheim123')->call('login');
    }

    public function test_the_password_alone_does_not_sign_in_people_with_a_second_factor(): void
    {
        $this->passwordStep()->assertSet('needsCode', true)->assertSet('password', '')->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_a_wrong_password_never_reaches_the_code_step(): void
    {
        Livewire::test('pages::login')->set('email', 'anna@example.com')->set('password', 'falsch')->call('login')->assertHasErrors('email')->assertSet('needsCode', false);
    }

    public function test_people_without_a_second_factor_sign_in_as_before(): void
    {
        User::factory()->create(['email' => 'bert@example.com', 'password' => 'geheim123']);

        Livewire::test('pages::login')->set('email', 'bert@example.com')->set('password', 'geheim123')->call('login')->assertRedirect(route('projects.index'));

        $this->assertAuthenticated();
    }

    public function test_the_right_code_signs_in(): void
    {
        $this->passwordStep()->set('code', ' '.$this->validCode().' ')->call('confirmCode')->assertHasNoErrors()->assertRedirect(route('projects.index'));

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->passwordStep()->set('code', '000000')->call('confirmCode')->assertHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once_and_is_replaced(): void
    {
        $codes = $this->user->recoveryCodes();

        $this->passwordStep()->call('toggleRecoveryCode')->set('recoveryCode', $codes[0])->call('confirmCode')->assertHasNoErrors()->assertRedirect(route('projects.index'));

        $this->assertAuthenticatedAs($this->user);
        $this->assertNotContains($codes[0], $this->user->fresh()->recoveryCodes());
        $this->assertCount(8, $this->user->fresh()->recoveryCodes());

        auth()->logout();
        session()->flush();

        $this->passwordStep()->call('toggleRecoveryCode')->set('recoveryCode', $codes[0])->call('confirmCode')->assertHasErrors('recoveryCode');
        $this->assertGuest();
    }

    public function test_the_code_step_is_rate_limited(): void
    {
        $page = $this->passwordStep();

        foreach (range(1, 5) as $attempt) {
            $page->set('code', '000000')->call('confirmCode');
        }

        $page->set('code', $this->validCode())->call('confirmCode')->assertHasErrors('code');
        $this->assertGuest();
        RateLimiter::clear('two-factor|'.$this->user->id.'|127.0.0.1');
    }

    public function test_the_challenge_expires_and_can_be_cancelled(): void
    {
        $page = $this->passwordStep();

        $this->travel(6)->minutes();
        $page->set('code', $this->validCode())->call('confirmCode')->assertHasErrors('code')->assertSet('needsCode', false);
        $this->assertGuest();

        $this->passwordStep()->call('cancelChallenge')->assertSet('needsCode', false);
        $this->assertNull(session('login.id'));
    }

    public function test_a_returning_visitor_with_an_open_challenge_sees_the_code_step(): void
    {
        $this->passwordStep();

        Livewire::test('pages::login')->assertSet('needsCode', true)->assertSee('Code aus der Authenticator-App');
    }

    public function test_deactivated_people_do_not_get_a_code_step(): void
    {
        $this->user->deactivate();

        $this->passwordStep()->assertHasErrors('email')->assertSet('needsCode', false);
    }

    public function test_an_unconfirmed_setup_does_not_lock_the_account(): void
    {
        $this->user->forceFill(['two_factor_confirmed_at' => null])->save();

        $this->passwordStep()->assertRedirect(route('projects.index'));
    }

    public function test_the_login_page_offers_passkeys(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Mit Passkey anmelden');
    }
}
