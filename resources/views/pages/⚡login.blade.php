<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Anmelden')] class extends Component
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
                'email' => 'Zu viele Versuche. Bitte in '.RateLimiter::availableIn($throttleKey).' Sekunden erneut versuchen.',
            ]);
        }

        $credentials = ['email' => $this->email, 'password' => $this->password];
        $provider = Auth::getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if ($user === null || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => 'E-Mail oder Passwort ist falsch.']);
        }

        if (! $user->isActive()) {
            RateLimiter::hit($throttleKey);

            // Only somebody who knows the password learns that the account was deactivated.
            throw ValidationException::withMessages(['email' => 'Dieses Konto wurde deaktiviert. Bitte wende dich an einen Administrator.']);
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

            throw ValidationException::withMessages(['code' => 'Die Anmeldung ist abgelaufen. Bitte noch einmal von vorn.']);
        }

        $throttleKey = 'two-factor|'.$user->getKey().'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Zu viele Versuche. Bitte in '.RateLimiter::availableIn($throttleKey).' Sekunden erneut versuchen.',
            ]);
        }

        if (! $this->codeIsValid($user)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([$this->useRecoveryCode ? 'recoveryCode' : 'code' => $this->useRecoveryCode
                ? 'Dieser Wiederherstellungscode stimmt nicht.'
                : 'Der Code stimmt nicht.']);
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
};
?>

<div class="mx-auto mt-24 max-w-sm px-4">
    <flux:heading size="xl" class="mb-6">Anmelden</flux:heading>

    @if ($needsCode)
        <form wire:submit="confirmCode" class="space-y-6">
            @if ($useRecoveryCode)
                <flux:input wire:model="recoveryCode" label="Wiederherstellungscode" description="Jeder Code gilt nur einmal." autofocus autocomplete="off" />
            @else
                <flux:input wire:model="code" label="Code aus der Authenticator-App" inputmode="numeric" autofocus autocomplete="one-time-code" />
            @endif
            <flux:button type="submit" variant="primary" class="w-full">Bestätigen</flux:button>
            <div class="flex justify-between">
                <flux:button type="button" variant="subtle" size="sm" wire:click="toggleRecoveryCode">{{ $useRecoveryCode ? 'Code aus der App verwenden' : 'Wiederherstellungscode verwenden' }}</flux:button>
                <flux:button type="button" variant="subtle" size="sm" wire:click="cancelChallenge">Abbrechen</flux:button>
            </div>
        </form>
    @else
        <form wire:submit="login" class="space-y-6">
            <flux:input wire:model="email" label="E-Mail" type="email" autofocus autocomplete="email webauthn" />
            <flux:input wire:model="password" label="Passwort" type="password" autocomplete="current-password" />
            <flux:checkbox wire:model="remember" label="Angemeldet bleiben" />
            <flux:button type="submit" variant="primary" class="w-full">Anmelden</flux:button>
        </form>

        <div
            x-data="{ supported: !!window.PublicKeyCredential, busy: false, failed: false }"
            x-show="supported"
            x-cloak
            class="mt-6 space-y-3"
        >
            <flux:separator text="oder" />
            <flux:button
                type="button"
                icon="finger-print"
                class="w-full"
                x-bind:disabled="busy"
                x-on:click="
                    busy = true; failed = false
                    window.Passkeys.verify({ remember: $wire.remember })
                        .then((response) => { window.location.href = response.redirect })
                        .catch(() => { failed = true })
                        .finally(() => { busy = false })
                "
            >Mit Passkey anmelden</flux:button>
            <flux:text x-show="failed" class="text-red-600 dark:text-red-400">Die Anmeldung mit dem Passkey hat nicht geklappt.</flux:text>
        </div>
    @endif
</div>
