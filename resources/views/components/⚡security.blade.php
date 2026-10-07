<?php

use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $password = '';

    public string $code = '';

    public bool $showRecoveryCodes = false;

    public string $passkeyName = '';

    #[Computed]
    public function confirmed(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) < config('auth.password_timeout', 10800);
    }

    #[Computed]
    public function passkeys()
    {
        return auth()->user()->passkeys()->latest()->get();
    }

    public function confirmPassword(): void
    {
        $this->resetErrorBag();

        $throttleKey = 'confirm-password|'.auth()->id().'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages(['password' => 'Zu viele Versuche. Bitte in '.RateLimiter::availableIn($throttleKey).' Sekunden erneut versuchen.']);
        }

        if (! Hash::check($this->password, auth()->user()->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['password' => 'Das Passwort stimmt nicht.']);
        }

        RateLimiter::clear($throttleKey);
        session(['auth.password_confirmed_at' => time()]);
        $this->reset('password');
        unset($this->confirmed);
    }

    private function requireConfirmed(): void
    {
        abort_unless($this->confirmed, 423);
    }

    private function refreshUser(): void
    {
        auth()->user()->refresh();
        unset($this->passkeys);
    }

    public function enableTwoFactor(): void
    {
        $this->requireConfirmed();

        app(EnableTwoFactorAuthentication::class)(auth()->user());

        $this->reset('code');
        $this->refreshUser();
    }

    public function confirmTwoFactor(): void
    {
        $this->requireConfirmed();

        $user = auth()->user();
        abort_if($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null, 404);

        $this->validate(['code' => ['required', 'string']], attributes: ['code' => 'Code']);

        $valid = app(TwoFactorAuthenticationProvider::class)->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            preg_replace('/\s+/', '', $this->code),
        );

        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'Der Code stimmt nicht. Bitte prüfe die Uhrzeit auf deinem Gerät.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->reset('code');
        $this->showRecoveryCodes = true;
        $this->refreshUser();
        Flux::toast(variant: 'success', text: 'Zwei-Faktor-Anmeldung eingeschaltet.');
    }

    public function toggleRecoveryCodes(): void
    {
        $this->requireConfirmed();

        $this->showRecoveryCodes = ! $this->showRecoveryCodes;
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->requireConfirmed();
        abort_unless(auth()->user()->hasEnabledTwoFactorAuthentication(), 404);

        app(GenerateNewRecoveryCodes::class)(auth()->user());

        $this->showRecoveryCodes = true;
        $this->refreshUser();
        Flux::toast(variant: 'success', text: 'Neue Wiederherstellungscodes erzeugt. Die alten sind ungültig.');
    }

    public function disableTwoFactor(): void
    {
        $this->requireConfirmed();

        app(DisableTwoFactorAuthentication::class)(auth()->user());

        $this->reset('code', 'showRecoveryCodes');
        $this->refreshUser();
        Flux::toast(variant: 'success', text: 'Zwei-Faktor-Anmeldung ausgeschaltet.');
    }

    public function passkeysChanged(): void
    {
        unset($this->passkeys);
        $this->reset('passkeyName');
    }

    public function deletePasskey(int $passkeyId): void
    {
        $this->requireConfirmed();

        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        app(DeletePasskey::class)(auth()->user(), $passkey);

        unset($this->passkeys);
        Flux::toast(variant: 'success', text: 'Passkey entfernt.');
    }
};
?>

@php($user = auth()->user())

<div class="space-y-8">
    @unless ($this->confirmed)
        <form wire:submit="confirmPassword" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:input wire:model="password" type="password" label="Passwort bestätigen" description="Zum Ändern der Sicherheitseinstellungen brauchen wir kurz dein Passwort." autocomplete="current-password" />
            <flux:button type="submit">Bestätigen</flux:button>
        </form>
    @endunless

    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <flux:heading size="lg">Zwei-Faktor-Anmeldung</flux:heading>
            @if ($user->hasEnabledTwoFactorAuthentication())
                <flux:badge color="green" size="sm">An</flux:badge>
            @elseif ($user->two_factor_secret)
                <flux:badge color="amber" size="sm">Nicht abgeschlossen</flux:badge>
            @else
                <flux:badge size="sm">Aus</flux:badge>
            @endif
        </div>

        @if ($user->hasEnabledTwoFactorAuthentication())
            <flux:text>Bei der Anmeldung mit Passwort wird zusätzlich ein Code aus deiner Authenticator-App abgefragt. Mit einem Passkey meldest du dich ohne zweiten Code an.</flux:text>

            @if ($this->confirmed)
                @if ($showRecoveryCodes)
                    <div class="rounded-lg bg-zinc-100 p-4 dark:bg-zinc-800">
                        <flux:text class="mb-2">Bewahre diese Codes sicher auf. Jeder funktioniert einmal, falls du dein Gerät verlierst.</flux:text>
                        <div class="grid grid-cols-2 gap-1 font-mono text-sm">
                            @foreach ($user->recoveryCodes() as $recoveryCode)
                                <div wire:key="rc-{{ $loop->index }}">{{ $recoveryCode }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="toggleRecoveryCodes">{{ $showRecoveryCodes ? 'Codes verbergen' : 'Wiederherstellungscodes anzeigen' }}</flux:button>
                    <flux:button size="sm" wire:click="regenerateRecoveryCodes" wire:confirm="Neue Codes erzeugen? Die bisherigen werden ungültig.">Neue Codes erzeugen</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="disableTwoFactor" wire:confirm="Zwei-Faktor-Anmeldung ausschalten?">Ausschalten</flux:button>
                </div>
            @endif
        @elseif ($user->two_factor_secret)
            @if ($this->confirmed)
                <flux:text>Scanne den QR-Code mit einer Authenticator-App (z. B. 1Password, Authy, Google Authenticator) und gib den angezeigten Code ein.</flux:text>
                <div class="inline-block rounded-lg bg-white p-2">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <flux:text size="sm">Oder den Schlüssel eintippen: <code class="font-mono">{{ \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret) }}</code></flux:text>
                <form wire:submit="confirmTwoFactor" class="space-y-3">
                    <flux:input wire:model="code" label="Code aus der App" inputmode="numeric" autocomplete="one-time-code" />
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">Bestätigen und einschalten</flux:button>
                        <flux:button type="button" variant="ghost" wire:click="disableTwoFactor">Abbrechen</flux:button>
                    </div>
                </form>
            @endif
        @else
            <flux:text>Zusätzlich zum Passwort wird bei der Anmeldung ein Code aus einer Authenticator-App abgefragt.</flux:text>
            @if ($this->confirmed)
                <flux:button size="sm" wire:click="enableTwoFactor">Einrichten</flux:button>
            @endif
        @endif
    </div>

    <flux:separator />

    <div class="space-y-4">
        <flux:heading size="lg">Passkeys</flux:heading>
        <flux:text>Melde dich ohne Passwort an: mit Fingerabdruck, Gesicht, Sicherheitsschlüssel oder Gerätesperre. Ein Passkey ersetzt Passwort und zweiten Faktor.</flux:text>

        @if ($this->passkeys->isNotEmpty())
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->passkeys as $passkey)
                    <div wire:key="passkey-{{ $passkey->id }}" class="flex items-center justify-between gap-3 p-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $passkey->name }}</div>
                            <flux:text size="sm">
                                @if ($passkey->authenticator){{ $passkey->authenticator }} · @endif
                                angelegt am {{ $passkey->created_at->format('d.m.Y') }}
                                · {{ $passkey->last_used_at ? 'zuletzt benutzt '.$passkey->last_used_at->diffForHumans() : 'noch nie benutzt' }}
                            </flux:text>
                        </div>
                        @if ($this->confirmed)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deletePasskey({{ $passkey->id }})" wire:confirm="Passkey „{{ $passkey->name }}“ entfernen?" aria-label="Passkey entfernen" />
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <flux:text>Noch kein Passkey angelegt.</flux:text>
        @endif

        @if ($this->confirmed)
            <div x-data="{ supported: !!window.PublicKeyCredential, busy: false, failed: false }" class="space-y-3">
                <flux:text x-show="!supported" x-cloak>Dieser Browser unterstützt keine Passkeys.</flux:text>
                <form
                    x-show="supported"
                    x-cloak
                    class="space-y-3"
                    x-on:submit.prevent="
                        busy = true; failed = false
                        window.Passkeys.register({ name: $wire.passkeyName })
                            .then(() => $wire.passkeysChanged())
                            .catch(() => { failed = true })
                            .finally(() => { busy = false })
                    "
                >
                    <flux:input wire:model="passkeyName" label="Name des neuen Passkeys" placeholder="z. B. MacBook oder iPhone" required />
                    <flux:button type="submit" size="sm" icon="plus" x-bind:disabled="busy">Passkey hinzufügen</flux:button>
                    <flux:text x-show="failed" class="text-red-600 dark:text-red-400">Der Passkey konnte nicht angelegt werden.</flux:text>
                </form>
            </div>
        @endif
    </div>
</div>
