<?php

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Component;

new class extends Component
{
    private const CHALLENGE_MINUTES = 5;

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
        ]);

        $throttleKey = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

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
    <flux:heading size="xl" class="mb-6">{{ __('Sign in') }}</flux:heading>

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
            <flux:input wire:model="password" :label="__('Password')" type="password" autocomplete="current-password" />
            <div class="flex items-center justify-between gap-4">
                <flux:checkbox wire:model="remember" :label="__('Stay signed in')" />
                <flux:link :href="route('password.request')" wire:navigate class="text-sm">{{ __('Forgot password?') }}</flux:link>
            </div>
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Sign in') }}</flux:button>
        </form>

        <div
            x-data="{ supported: !!window.PublicKeyCredential, busy: false, failed: false }"
            x-show="supported"
            x-cloak
            class="mt-6 space-y-3"
        >
            <flux:separator :text="__('or')" />
            <flux:button
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
    @endif
    </flux:card>

    <form method="POST" action="{{ route('locale.update') }}" class="mt-8 flex justify-center gap-1">
        @csrf
        @foreach (\App\Services\LocaleService::available() as $code => $name)
            <flux:button type="submit" name="locale" value="{{ $code }}" size="sm" :variant="app()->getLocale() === $code ? 'filled' : 'ghost'" lang="{{ $code }}">{{ $name }}</flux:button>
        @endforeach
    </form>
</div>
