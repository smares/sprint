<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\AccountRegisteredWithGoogle;
use App\Services\SsoSettings;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** Whether the sign-in is to be remembered ("Stay signed in"). */
    private const string REMEMBER_SESSION_KEY = 'sso.remember';

    public function __construct(private readonly SsoSettings $sso) {}

    /**
     * Sign in or register: on to Google.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $this->ensureAvailable();
        $request->session()->forget(self::LINK_SESSION_KEY);
        $request->session()->put(self::REMEMBER_SESSION_KEY, $request->query('remember') === '1');

        return $this->toGoogle(hintDomain: true, hintEmail: Str::lower(trim(is_string($request->query('email')) ? $request->query('email') : '')));
    }

    /**
     * Connect the Google account to the account of the person who is signed in.
     */
    public function link(Request $request): RedirectResponse
    {
        $this->ensureAvailable();

        // Google replaces the second factor: connecting needs the password again (asked in the profile)
        if (time() - (int) $request->session()->get('auth.password_confirmed_at', 0) >= (int) config('auth.password_timeout', 10800)) {
            return to_route('profile', ['tab' => 'security'])->with('warning', __('Please confirm your password first, then connect your Google account.'));
        }

        $request->session()->put(self::LINK_SESSION_KEY, $request->user()->id);

        return $this->toGoogle(hintDomain: false, hintEmail: Str::lower((string) $request->user()->email));
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->ensureAvailable();

        $linkingId = $request->session()->pull(self::LINK_SESSION_KEY);
        $linkingId = $linkingId === null ? null : (int) $linkingId;
        $remember = (bool) $request->session()->pull(self::REMEMBER_SESSION_KEY, false);

        // Somebody who signed in meanwhile does not switch accounts through a Google round trip started as a guest
        if ($linkingId === null && Auth::check()) {
            return to_route('profile', ['tab' => 'security']);
        }

        if ($request->query('error') === 'access_denied') {
            return $this->failed($linkingId, __('Signing in with Google was cancelled.'));
        }

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
            return $this->connect($request, $linkingId, $google);
        }

        return $this->signIn($request, $google, $email, $remember);
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

        if ($user->socialAccounts()->where('provider', SocialAccount::GOOGLE)->where('provider_id', '!=', $google->getId())->exists()) {
            return to_route('profile', ['tab' => 'security'])->with('warning', __('Another Google account is connected already. Disconnect it first.'));
        }

        $this->remember($user, $google);

        return to_route('profile', ['tab' => 'security'])->with('status', __('Your Google account is connected.'));
    }

    private function signIn(Request $request, GoogleUser $google, string $email, bool $remember): RedirectResponse
    {
        $user = SocialAccount::query()->where('provider', SocialAccount::GOOGLE)->where('provider_id', $google->getId())->first()?->user;

        if ($user === null) {
            // The address alone connects an account only when Google is the one who knows who owns it; anybody else can ask
            // for a confirmation of an address at a domain Google does not manage. Those people connect in their profile.
            $matches = User::query()->whereRaw('lower(email) = ?', [$email])->limit(2)->get();

            if ($matches->count() > 1) {
                return $this->failed(null, __('This Google account could not be matched to one account. Please contact an administrator.'));
            }

            $user = $matches->first();

            if ($user !== null && (! $this->sso->isAuthoritativeFor($email, $google->user['hd'] ?? null) || $user->socialAccounts()->where('provider', SocialAccount::GOOGLE)->exists())) {
                return $this->failed(null, __('Please sign in with your password and connect your Google account in the profile.'));
            }
        }

        $registered = false;

        if ($user === null) {
            if (! $this->sso->allowsRegistration($email, $google->user['hd'] ?? null)) {
                return $this->failed(null, __('This Google account may not register here. Please ask an administrator for an account.'));
            }

            $user = $this->register($google, $email);
            $registered = true;
        }

        if (! $user->isActive()) {
            return $this->failed(null, __('This account has been deactivated. Please contact an administrator.'));
        }

        $this->remember($user, $google);

        if ($registered) {
            Notification::send(User::query()->active()->where('is_admin', true)->get(), new AccountRegisteredWithGoogle($user));
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('projects.index'));
    }

    /**
     * A new account: no administrator, no password anybody knows (it can be asked for on the login page), the language of
     * the browser.
     */
    private function register(GoogleUser $google, string $email): User
    {
        $user = new User([
            'name' => Str::limit(trim((string) $google->getName()) ?: Str::before($email, '@'), 255, ''),
            'email' => $email,
            'password' => Str::password(40),
            'locale' => app()->getLocale(),
        ]);

        try {
            $user->forceFill(['email_verified_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            // Two returns from Google at once: the other one registered this person first
            return User::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();
        }

        return $user;
    }

    private function remember(User $user, GoogleUser $google): void
    {
        $keys = ['user_id' => $user->id, 'provider' => SocialAccount::GOOGLE, 'provider_id' => $google->getId()];
        $email = Str::lower((string) $google->getEmail());

        try {
            SocialAccount::query()->firstOrCreate($keys, ['email' => $email])->update(['email' => $email]);
        } catch (UniqueConstraintViolationException) {
            // Two returns from Google at once: the other one connected the account first
            SocialAccount::query()->where($keys)->update(['email' => $email]);
        }
    }

    private function toGoogle(bool $hintDomain, string $hintEmail): RedirectResponse
    {
        $domains = $this->sso->domains();
        $onlyDomain = count($domains) === 1 && $hintDomain && ! $this->sso->isAuthoritativeFor('x@'.$domains[0], null) ? $domains[0] : null;

        // The domain and the address typed on the login page are only hints for Google's own page (the address is checked on
        // our side). With an address Google picks the matching account of the browser; without, the account is chosen every time.
        $loginHint = filter_var($hintEmail, FILTER_VALIDATE_EMAIL) !== false ? $hintEmail : null;

        return $this->provider()->with(array_filter([
            'prompt' => $loginHint === null ? 'select_account' : null,
            'login_hint' => $loginHint,
            'hl' => app()->getLocale(),
            'hd' => $loginHint === null || Str::lower(Str::afterLast($loginHint, '@')) === $onlyDomain ? $onlyDomain : null,
        ]))->redirect();
    }

    private function provider(): AbstractProvider
    {
        $provider = Socialite::driver('google');

        return $provider instanceof AbstractProvider ? $provider : throw new LogicException('The Google provider is an OAuth 2 provider.');
    }

    private function failed(?int $linkingId, string $message): RedirectResponse
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
