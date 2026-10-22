<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\AccountRegisteredWithGoogle;
use App\Services\LocaleService;
use App\Services\SsoSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use LogicException;
use Throwable;

/**
 * Sign in, register and connect with Google. People are found by their Google id; somebody without a connection is found by
 * their (verified) email address and connected, and somebody unknown registers if their address is at one of the domains the
 * administrators allow. Google counts as the second factor: the two-factor code of the app is not asked for.
 */
class GoogleAuthController extends Controller
{
    /** What the callback is for when a signed-in person connects their account. */
    private const string LINK_SESSION_KEY = 'sso.link';

    public function __construct(private readonly SsoSettings $sso) {}

    /**
     * Sign in or register: on to Google.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $this->ensureAvailable();
        $request->session()->forget(self::LINK_SESSION_KEY);

        return $this->toGoogle();
    }

    /**
     * Connect the Google account to the account of the person who is signed in.
     */
    public function link(Request $request): RedirectResponse
    {
        $this->ensureAvailable();
        $request->session()->put(self::LINK_SESSION_KEY, $request->user()->id);

        return $this->toGoogle();
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->ensureAvailable();

        $linkingId = $request->session()->pull(self::LINK_SESSION_KEY);

        try {
            $google = $this->provider()->user();
        } catch (Throwable) {
            return $this->failed($linkingId, __('Signing in with Google did not work. Please try again.'));
        }

        if (! $google instanceof GoogleUser) {
            return $this->failed($linkingId, __('Signing in with Google did not work. Please try again.'));
        }

        $email = Str::lower((string) $google->getEmail());

        if ($email === '' || ($google->user['email_verified'] ?? false) !== true) {
            return $this->failed($linkingId, __('Google has not confirmed the email address of this account.'));
        }

        if ($linkingId !== null) {
            return $this->connect($request, (int) $linkingId, $google);
        }

        return $this->signIn($request, $google, $email);
    }

    private function connect(Request $request, int $linkingId, GoogleUser $google): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || $user->id !== $linkingId) {
            return $this->failed(null, __('Signing in with Google did not work. Please try again.'));
        }

        $existing = SocialAccount::query()->where('provider', SocialAccount::GOOGLE)->where('provider_id', $google->getId())->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            return to_route('profile', ['tab' => 'security'])->with('warning', __('This Google account is already connected to another account.'));
        }

        $this->remember($user, $google);

        return to_route('profile', ['tab' => 'security'])->with('status', __('Your Google account is connected.'));
    }

    private function signIn(Request $request, GoogleUser $google, string $email): RedirectResponse
    {
        $user = SocialAccount::query()->where('provider', SocialAccount::GOOGLE)->where('provider_id', $google->getId())->first()->user
            ?? User::query()->whereRaw('lower(email) = ?', [$email])->first();

        $registered = false;

        if ($user === null) {
            if (! $this->sso->allowsRegistration($email, $google->user['hd'] ?? null)) {
                return $this->failed(null, __('This Google account may not register here. Please ask an administrator for an account.'));
            }

            $user = $this->register($request, $google, $email);
            $registered = true;
        }

        if (! $user->isActive()) {
            return $this->failed(null, __('This account has been deactivated. Please contact an administrator.'));
        }

        $this->remember($user, $google);

        if ($registered) {
            Notification::send(User::query()->active()->where('is_admin', true)->get(), new AccountRegisteredWithGoogle($user));
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('projects.index'));
    }

    /**
     * A new account: no administrator, no password anybody knows (it can be asked for on the login page), the language of
     * the browser.
     */
    private function register(Request $request, GoogleUser $google, string $email): User
    {
        $user = new User([
            'name' => Str::limit(trim((string) $google->getName()) ?: Str::before($email, '@'), 255, ''),
            'email' => $email,
            'password' => Str::password(40),
            'locale' => LocaleService::fromHeader($request->header('Accept-Language')) ?? config('app.locale'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function remember(User $user, GoogleUser $google): void
    {
        SocialAccount::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => SocialAccount::GOOGLE],
            ['provider_id' => $google->getId(), 'email' => Str::lower((string) $google->getEmail())],
        );
    }

    private function toGoogle(): RedirectResponse
    {
        $domains = $this->sso->domains();

        // Only a hint for Google's own page (the address is checked on our side), and the choice of the account every time
        return $this->provider()->with(array_filter([
            'prompt' => 'select_account',
            'hd' => count($domains) === 1 ? $domains[0] : null,
        ]))->redirect();
    }

    private function provider(): AbstractProvider
    {
        $provider = Socialite::driver('google');

        return $provider instanceof AbstractProvider ? $provider : throw new LogicException('The Google provider is an OAuth 2 provider.');
    }

    private function failed(mixed $linkingId, string $message): RedirectResponse
    {
        return $linkingId !== null && Auth::check()
            ? to_route('profile', ['tab' => 'security'])->with('warning', $message)
            : to_route('login')->with('warning', $message);
    }

    private function ensureAvailable(): void
    {
        abort_unless($this->sso->isAvailable(), 404);
    }
}
