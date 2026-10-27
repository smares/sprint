<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\AccountRegisteredWithGoogle;
use App\Services\SsoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private ?GoogleUser $googleUser = null;

    private ?GoogleProvider $googleProvider = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        app(SsoSettings::class)->update(true, ['example.com']);
    }

    /**
     * What Google tells about the person who came back from its page.
     *
     * @param  array<string, mixed>  $raw
     */
    private function googleSays(string $email = 'anna@example.com', string $id = 'g-1', array $raw = [], string $name = 'Anna Beispiel'): void
    {
        $this->googleUser = (new GoogleUser)->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $this->googleUser->user = $raw + ['email_verified' => true, 'hd' => Str::afterLast($email, '@')];

        // The mock of the manager is made once; calling this again only changes who comes back
        if (! $this->googleProvider instanceof GoogleProvider) {
            $this->googleProvider = Mockery::mock(GoogleProvider::class);
            $this->googleProvider->shouldReceive('user')->andReturnUsing(fn () => $this->googleUser);
            $this->googleProvider->shouldReceive('with')->andReturnSelf();
            $this->googleProvider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
            Socialite::shouldReceive('driver')->with('google')->andReturn($this->googleProvider);
        }
    }

    public function test_the_login_page_offers_google_only_while_it_is_on_and_set_up(): void
    {
        $this->get(route('login'))->assertSee('Mit Google anmelden')->assertSee(route('auth.google.redirect'), false);

        app(SsoSettings::class)->update(false, ['example.com']);
        $this->get(route('login'))->assertDontSee('Mit Google anmelden');

        app(SsoSettings::class)->update(true, ['example.com']);
        config(['services.google.client_secret' => null]);
        $this->get(route('login'))->assertDontSee('Mit Google anmelden');
    }

    public function test_nothing_is_found_while_it_is_off(): void
    {
        app(SsoSettings::class)->update(false, ['example.com']);

        $this->get(route('auth.google.redirect'))->assertNotFound();
        $this->get(route('auth.google.callback'))->assertNotFound();
    }

    public function test_an_address_typed_on_the_login_page_is_handed_to_google_as_a_hint(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('with')->once()->with(['login_hint' => 'anna@example.com', 'hl' => app()->getLocale(), 'hd' => 'example.com'])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.redirect', ['email' => ' Anna@Example.com ']))->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_the_way_to_google_asks_for_the_account_and_hints_the_only_domain(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('with')->once()->with(['prompt' => 'select_account', 'hl' => app()->getLocale(), 'hd' => 'example.com'])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.redirect'))->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_somebody_at_an_allowed_domain_registers_and_the_administrators_are_told(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $inactiveAdmin = User::factory()->admin()->create(['deactivated_at' => now()]);
        $other = User::factory()->create();
        $this->googleSays();

        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')->get(route('auth.google.callback'))->assertRedirect(route('projects.index'));

        $user = User::where('email', 'anna@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Anna Beispiel', $user->name);
        $this->assertFalse($user->is_admin);
        $this->assertSame('de', $user->locale);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('g-1', $user->socialAccounts()->firstOrFail()->provider_id);

        Notification::assertSentTo($admin, AccountRegisteredWithGoogle::class);
        Notification::assertNotSentTo($inactiveAdmin, AccountRegisteredWithGoogle::class);
        Notification::assertNotSentTo($other, AccountRegisteredWithGoogle::class);
    }

    public function test_registering_needs_a_domain_on_the_list_and_the_workspace_of_that_domain(): void
    {
        $refused = [
            'a domain that is not on the list' => ['anna@other.com', 'g-1', []],
            'an address at the domain from a personal account' => ['anna@example.com', 'g-2', ['hd' => null]],
            'a Workspace of another domain' => ['anna@example.com', 'g-3', ['hd' => 'other.com']],
            'an address Google has not confirmed' => ['anna@example.com', 'g-4', ['email_verified' => false]],
        ];

        foreach ($refused as $why => [$email, $id, $raw]) {
            $this->googleSays($email, $id, $raw);

            $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
            $this->assertGuest();
            $this->assertSame(0, User::count(), $why);
        }
    }

    public function test_nobody_registers_without_a_domain_on_the_list(): void
    {
        app(SsoSettings::class)->update(true, []);
        $this->googleSays();

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHas('warning');
        $this->assertSame(0, User::count());
    }

    public function test_a_personal_google_account_can_register_when_its_domain_is_listed(): void
    {
        app(SsoSettings::class)->update(true, ['gmail.com']);
        $this->googleSays('anna@gmail.com', 'g-9', ['hd' => null]);

        $this->get(route('auth.google.callback'))->assertRedirect(route('projects.index'));
        $this->assertSame(1, User::count());
    }

    public function test_an_existing_account_is_connected_by_the_address_when_google_owns_it_even_off_the_list(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'Anna@Elsewhere.org']);
        $this->googleSays('anna@elsewhere.org', 'g-5', ['hd' => 'elsewhere.org']);

        $this->get(route('auth.google.callback'))->assertRedirect(route('projects.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('g-5', $user->socialAccounts()->firstOrFail()->provider_id);
        Notification::assertNothingSent();
    }

    public function test_the_address_alone_connects_nothing_at_a_domain_google_does_not_manage(): void
    {
        $user = User::factory()->create(['email' => 'anna@elsewhere.org']);
        $this->googleSays('anna@elsewhere.org', 'g-5', ['hd' => null]);

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHas('warning');

        $this->assertGuest();
        $this->assertSame(0, $user->socialAccounts()->count());
    }

    public function test_another_google_account_with_the_same_address_does_not_take_over_a_connection(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.com']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-old', 'email' => 'anna@example.com']);
        $this->googleSays('anna@example.com', 'g-new');

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHas('warning');

        $this->assertGuest();
        $this->assertSame('g-old', $user->socialAccounts()->firstOrFail()->provider_id);
    }

    public function test_addresses_that_differ_only_in_case_are_not_guessed(): void
    {
        User::factory()->create(['email' => 'anna@example.com']);
        User::factory()->create(['email' => 'Anna@example.com']);
        $this->googleSays();

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHas('warning');

        $this->assertGuest();
    }

    public function test_resetting_the_password_or_the_second_factors_removes_the_connection(): void
    {
        $user = User::factory()->create();
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-1', 'email' => 'anna@example.com']);

        $user->resetSecondFactors();
        $this->assertSame(0, $user->socialAccounts()->count());

        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-1', 'email' => 'anna@example.com']);
        $user->signOutEverywhere(withPasskeys: true);
        $this->assertSame(0, $user->socialAccounts()->count());
    }

    public function test_a_connected_person_is_found_by_the_google_id_even_with_another_address(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.com']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-1', 'email' => 'anna@example.com']);
        $this->googleSays('anna.neu@example.com', 'g-1');

        $this->get(route('auth.google.callback'))->assertRedirect(route('projects.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        $this->assertSame('anna.neu@example.com', $user->socialAccounts()->firstOrFail()->email);
    }

    public function test_a_deactivated_account_is_not_signed_in_and_not_connected(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.com', 'deactivated_at' => now()]);
        $this->googleSays();

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHas('warning');

        $this->assertGuest();
        $this->assertSame(0, $user->socialAccounts()->count());
    }

    public function test_google_replaces_the_code_of_the_authenticator_app(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.com', 'two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()]);
        $this->googleSays();

        $this->get(route('auth.google.callback'))->assertRedirect(route('projects.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_stay_signed_in_carries_through_google_only_when_asked_for(): void
    {
        $this->googleSays();
        $this->get(route('auth.google.redirect', ['remember' => 1]));
        $this->get(route('auth.google.callback'))->assertRedirect(route('projects.index'))->assertCookie(Auth::guard()->getRecallerName());

        Auth::logout();
        $this->flushSession();
        $this->get(route('auth.google.redirect'));
        $this->get(route('auth.google.callback'))->assertRedirect(route('projects.index'))->assertCookieMissing(Auth::guard()->getRecallerName());
    }

    public function test_connecting_from_the_profile_hints_the_address_of_the_account(): void
    {
        $user = User::factory()->create(['email' => 'Anna@Example.com']);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('with')->once()->with(['login_hint' => 'anna@example.com', 'hl' => app()->getLocale()])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('auth.google.link'))->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_the_domain_hint_gives_way_to_an_address_of_another_domain(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('with')->once()->with(['login_hint' => 'foo@gmail.com', 'hl' => app()->getLocale()])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.redirect', ['email' => 'foo@gmail.com']))->assertRedirect();
    }

    public function test_odd_values_in_the_way_to_google_do_not_break_it(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('with')->once()->with(['prompt' => 'select_account', 'hl' => app()->getLocale(), 'hd' => 'example.com'])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/redirect?email[]=x&remember[]=1')->assertRedirect();
    }

    public function test_cancelling_at_google_says_so(): void
    {
        $this->googleSays();

        $this->get(route('auth.google.callback', ['error' => 'access_denied']))->assertRedirect(route('login'))->assertSessionHas('warning', 'Die Anmeldung mit Google wurde abgebrochen.');
        $this->assertGuest();
    }

    public function test_somebody_signed_in_is_not_switched_to_another_account_by_a_return_from_google(): void
    {
        $signedIn = User::factory()->create();
        User::factory()->create(['email' => 'anna@example.com']);
        $this->googleSays();

        $this->actingAs($signedIn)->get(route('auth.google.callback'))->assertRedirect(route('profile', ['tab' => 'security']));

        $this->assertAuthenticatedAs($signedIn);
    }

    public function test_connecting_another_google_account_needs_the_first_one_disconnected(): void
    {
        $user = User::factory()->create();
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-old', 'email' => 'x@example.com']);
        $this->googleSays('anna@gmail.com', 'g-new', ['hd' => null]);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('auth.google.link'));
        $this->actingAs($user)->get(route('auth.google.callback'))->assertSessionHas('warning');

        $this->assertSame('g-old', $user->socialAccounts()->firstOrFail()->provider_id);
    }

    public function test_a_failed_return_from_google_leads_back_to_the_login_page(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHas('warning');
        $this->assertGuest();
    }

    public function test_a_signed_in_person_connects_their_google_account(): void
    {
        $user = User::factory()->create(['email' => 'anna@private.net']);
        $this->googleSays('anna@gmail.com', 'g-7', ['hd' => null]);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('auth.google.link'))->assertRedirect('https://accounts.google.com/o/oauth2/auth');
        $this->actingAs($user)->get(route('auth.google.callback'))->assertRedirect(route('profile', ['tab' => 'security']))->assertSessionHas('status');

        $this->assertSame('g-7', $user->socialAccounts()->firstOrFail()->provider_id);
        $this->assertSame('anna@gmail.com', $user->socialAccounts()->firstOrFail()->email);
    }

    public function test_a_google_account_belongs_to_one_person_only(): void
    {
        $first = User::factory()->create();
        SocialAccount::create(['user_id' => $first->id, 'provider' => 'google', 'provider_id' => 'g-7', 'email' => 'x@example.com']);
        $second = User::factory()->create();
        $this->googleSays('x@example.com', 'g-7');

        $this->actingAs($second)->withSession(['auth.password_confirmed_at' => time()])->get(route('auth.google.link'));
        $this->actingAs($second)->get(route('auth.google.callback'))->assertRedirect(route('profile', ['tab' => 'security']))->assertSessionHas('warning');

        $this->assertSame(0, $second->socialAccounts()->count());
    }

    public function test_the_connection_needs_the_link_route_first(): void
    {
        $user = User::factory()->create(['email' => 'anna@private.net']);
        $this->googleSays('anna@gmail.com', 'g-8', ['hd' => null]);

        // without it the callback is a sign-in, which this address cannot do
        $this->actingAs($user)->get(route('auth.google.callback'));

        $this->assertSame(0, $user->socialAccounts()->count());
    }

    public function test_what_a_redirect_tells_the_profile_comes_as_a_toast(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        session()->flash('status', 'Your Google account is connected.');
        session()->flash('warning', 'Something is off.');

        Livewire::test('pages::profile')
            ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['slots']['text'] ?? null) === 'Your Google account is connected.')
            ->assertDispatched('toast-show', fn (string $event, array $params) => ($params['slots']['text'] ?? null) === 'Something is off.');
    }

    public function test_the_profile_connects_and_disconnects(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('google-account')->assertSee('Google-Konto verbinden')->assertDontSee('Trennen');

        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-1', 'email' => 'anna@example.com']);

        session(['auth.password_confirmed_at' => time()]);
        Livewire::actingAs($user)->test('google-account')->assertSee('Verbunden: anna@example.com')->call('disconnect')->assertSee('Google-Konto verbinden');
        $this->assertSame(0, $user->socialAccounts()->count());

        app(SsoSettings::class)->update(false, []);
        Livewire::actingAs($user)->test('google-account')->assertDontSee('Google-Konto verbinden');
    }

    public function test_connecting_and_disconnecting_need_the_password(): void
    {
        $user = User::factory()->create(['password' => 'geheim-1234']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-1', 'email' => 'anna@example.com']);

        Livewire::actingAs($user)->test('google-account')->call('disconnect')->assertHasErrors('password');
        $this->assertSame(1, $user->socialAccounts()->count());

        Livewire::actingAs($user)->test('google-account')->set('password', 'falsch')->call('disconnect')->assertHasErrors('password');
        $this->assertSame(1, $user->socialAccounts()->count());

        Livewire::actingAs($user)->test('google-account')->set('password', 'geheim-1234')->call('disconnect')->assertHasNoErrors();
        $this->assertSame(0, $user->socialAccounts()->count());

        session()->forget('auth.password_confirmed_at');
        Livewire::actingAs($user)->test('google-account')->call('connect')->assertHasErrors('password')->assertNoRedirect();
        Livewire::actingAs($user)->test('google-account')->set('password', 'geheim-1234')->call('connect')->assertRedirect(route('auth.google.link'));
    }

    public function test_the_link_to_google_needs_a_recent_confirmation_of_the_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('auth.google.link'))->assertRedirect(route('profile', ['tab' => 'security']))->assertSessionHas('warning');
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time() - 20000])->get(route('auth.google.link'))->assertRedirect(route('profile', ['tab' => 'security']));
    }

    public function test_the_domains_are_checked_in_lower_case_and_by_their_end(): void
    {
        $sso = app(SsoSettings::class);
        $sso->update(true, ['Example.com']);

        $this->assertTrue($sso->allowsRegistration('Anna@EXAMPLE.com', 'example.com'));
        $this->assertFalse($sso->allowsRegistration('anna@evil-example.com', 'example.com'));
        $this->assertFalse($sso->allowsRegistration('anna@sub.example.com', 'example.com'));
        $this->assertFalse($sso->allowsRegistration('example.com', 'example.com'));
    }

    public function test_the_mail_to_the_administrators_names_the_new_account(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Chef', 'locale' => 'de']);
        $new = User::factory()->create(['name' => 'Neu Person', 'email' => 'neu@example.com']);

        $this->app->setLocale('de');
        $mail = (new AccountRegisteredWithGoogle($new))->toMail($admin);

        $this->assertSame('Neue Registrierung mit Google in '.config('app.name'), $mail->subject);
        $rendered = (string) $mail->render();
        $this->assertStringContainsString('Neu Person', $rendered);
        $this->assertStringContainsString('neu@example.com', $rendered);
        $this->assertStringContainsString(route('admin.users'), $rendered);
    }

    public function test_confirming_the_password_here_unlocks_the_other_cards_of_the_page_too(): void
    {
        $user = User::factory()->create(['password' => 'geheim-1234']);

        Livewire::actingAs($user)->test('google-account')
            ->set('password', 'geheim-1234')
            ->call('confirmPassword')
            ->assertDispatched('password-confirmed');
    }
}
