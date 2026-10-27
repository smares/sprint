<?php

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Component;

new class extends Component
{
    private const CHALLENGE_MINUTES = 5;

    /**
     * Every failed check takes at least this long, so the response time does not tell whether an address has an account.
     */
    private const MINIMUM_CHECK_MICROSECONDS = 400_000;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public bool $needsCode = false;

    public bool $useRecoveryCode = false;

    public string $code = '';

    public string $recoveryCode = '';

    public function mount(): void
    {
        $this->needsCode = $this->pendingUser() !== null;
    }

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], attributes: ['email' => __('Email'), 'password' => __('Password')]);

        $throttleKey = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        $user = (new Timebox)->call(function (Timebox $timebox) use ($throttleKey): User {
            $credentials = ['email' => $this->email, 'password' => $this->password];
            $provider = Auth::getProvider();
            $user = $provider->retrieveByCredentials($credentials);

            if ($user === null || ! $provider->validateCredentials($user, $credentials)) {
                RateLimiter::hit($throttleKey);

                throw ValidationException::withMessages(['email' => __('Email or password is incorrect.')]);
            }

            if (! $user->isActive()) {
                RateLimiter::hit($throttleKey);

                // Only somebody who knows the password learns that the account was deactivated.
                throw ValidationException::withMessages(['email' => __('This account has been deactivated. Please contact an administrator.')]);
            }

            $timebox->returnEarly();

            return $user;
        }, microseconds: self::MINIMUM_CHECK_MICROSECONDS);

        RateLimiter::clear($throttleKey);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            session([
                'login.id' => $user->getKey(),
                'login.remember' => $this->remember,
                'login.expires' => now()->addMinutes(self::CHALLENGE_MINUTES)->timestamp,
            ]);

            $this->reset('password');
            $this->needsCode = true;

            return;
        }

        $this->signIn($user, $this->remember);
    }

    public function confirmCode(): void
    {
        $user = $this->pendingUser();

        if ($user === null) {
            $this->cancelChallenge();

            throw ValidationException::withMessages(['code' => __('Your sign-in has expired. Please start over.')]);
        }

        $throttleKey = 'two-factor|'.$user->getKey();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        if (! $this->codeIsValid($user)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([$this->useRecoveryCode ? 'recoveryCode' : 'code' => $this->useRecoveryCode
                ? __('This recovery code is incorrect.')
                : __('The code is incorrect.')]);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) session('login.remember');
        $this->cancelChallenge();
        $this->signIn($user, $remember);
    }

    public function toggleRecoveryCode(): void
    {
        $this->useRecoveryCode = ! $this->useRecoveryCode;
        $this->reset('code', 'recoveryCode');
        $this->resetErrorBag();
    }

    public function cancelChallenge(): void
    {
        session()->forget(['login.id', 'login.remember', 'login.expires']);
        $this->reset('needsCode', 'useRecoveryCode', 'code', 'recoveryCode');
    }

    private function pendingUser(): ?User
    {
        if (! session()->has('login.id') || session('login.expires', 0) < now()->timestamp) {
            return null;
        }

        return User::active()->find(session('login.id'));
    }

    private function codeIsValid(User $user): bool
    {
        if ($this->useRecoveryCode) {
            $given = trim($this->recoveryCode);
            $match = collect($user->recoveryCodes())->first(fn (string $code) => hash_equals($code, $given));

            if ($match === null) {
                return false;
            }

            $user->replaceRecoveryCode($match);

            return true;
        }

        return app(TwoFactorAuthenticationProvider::class)->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            preg_replace('/\s+/', '', $this->code),
        );
    }

    private function signIn(User $user, bool $remember): void
    {
        Auth::login($user, $remember);
        session()->regenerate();

        $this->redirectIntended(route('projects.index'), navigate: true);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Sign in'));
    }
};
?>

<div class="mx-auto mt-16 max-w-sm px-4 sm:mt-24">
    <div class="mb-6 flex justify-center">
        <x-app-brand :href="route('login')" />
    </div>

    <flux:card>
    <flux:heading level="1" size="xl" class="mb-6">{{ __('Sign in') }}</flux:heading>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mb-6" :heading="session('status')" />
    @endif
    @if (session('warning'))
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" :heading="session('warning')" />
    @endif

    @if ($needsCode)
        <form wire:submit="confirmCode" class="space-y-6">
            @if ($useRecoveryCode)
                <flux:input wire:model="recoveryCode" :label="__('Recovery code')" :description="__('Each code can only be used once.')" autofocus autocomplete="off" />
            @else
                <flux:otp wire:model="code" length="6" submit="auto" :label="__('Code from the authenticator app')" x-init="$nextTick(() => $el.querySelector('input')?.focus())" />
            @endif
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Confirm') }}</flux:button>
            <div class="flex justify-between">
                <flux:button type="button" variant="subtle" size="sm" wire:click="toggleRecoveryCode">{{ $useRecoveryCode ? __('Use code from the app') : __('Use a recovery code') }}</flux:button>
                <flux:button type="button" variant="subtle" size="sm" wire:click="cancelChallenge">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <form wire:submit="login" class="space-y-6">
            <flux:input wire:model="email" :label="__('Email')" type="email" autofocus autocomplete="email webauthn" />
            {{-- The way to a new password sits in the label, where one looks when the password does not come to mind --}}
            <flux:field>
                <flux:label>
                    {{ __('Password') }}
                    <span class="ms-1 font-normal text-zinc-500 dark:text-zinc-400">(<flux:link variant="ghost" :href="route('password.request')" wire:navigate>{{ __('Forgot?') }}</flux:link>)</span>
                </flux:label>
                <flux:input wire:model="password" type="password" autocomplete="current-password" />
                <flux:error name="password" />
            </flux:field>
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Sign in') }}</flux:button>
        </form>

        @php($google = app(\App\Services\SsoSettings::class)->isAvailable())

        <div
            x-data="{ supported: !!window.PublicKeyCredential, busy: false, failed: false }"
            x-show="supported || {{ $google ? 'true' : 'false' }}"
            x-cloak
            class="mt-6 space-y-3"
        >
            <flux:separator :text="__('or')" />
            @if ($google)
                {{-- A plain link: the way leads to Google and back, not through the page loading of the app --}}
                <flux:button :href="route('auth.google.redirect')" x-on:click="$el.href = '{{ route('auth.google.redirect') }}?' + new URLSearchParams({ ...($wire.remember ? { remember: 1 } : {}), email: $wire.email ?? '' })" class="w-full">
                    <svg class="size-4" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.6 5.9c4.4-4.1 7-10.1 7-17.6z"/><path fill="#FBBC05" d="M10.5 28.7c-.5-1.5-.8-3-.8-4.7s.3-3.2.8-4.7l-7.9-6.1C.9 16.4 0 20.1 0 24s.9 7.6 2.6 10.8l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.9 2.3-8.3 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
                    {{ __('Sign in with Google') }}
                </flux:button>
            @endif
            <flux:button
                x-show="supported"
                type="button"
                icon="finger-print"
                class="w-full"
                x-bind:disabled="busy"
                x-on:click="
                    busy = true; failed = false
                    window.passkeys().then((passkeys) => passkeys.verify({ remember: $wire.remember }))
                        .then((response) => { window.location.href = response.redirect })
                        .catch(() => { failed = true })
                        .finally(() => { busy = false })
                "
            >{{ __('Sign in with a passkey') }}</flux:button>
            <flux:text x-show="failed" class="text-red-600 dark:text-red-400">{{ __('Signing in with the passkey failed.') }}</flux:text>
        </div>

        {{-- Below every way to sign in, not inside the form of the password: it counts for the password, a passkey and Google alike, which the place says --}}
        <div class="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-700">
            <flux:checkbox wire:model="remember" :label="__('Stay signed in')" />
        </div>
    @endif
    </flux:card>

    <x-locale-switch />
</div>
