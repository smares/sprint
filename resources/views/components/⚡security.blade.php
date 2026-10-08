<?php

use App\Concerns\ConfirmsPassword;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
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
    use ConfirmsPassword;

    public string $code = '';

    public bool $showRecoveryCodes = false;

    public string $passkeyName = '';

    #[Computed]
    public function passkeys(): Collection
    {
        return auth()->user()->passkeys()->latest()->get();
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

        $this->validate(['code' => ['required', 'string']], attributes: ['code' => __('Code')]);

        $valid = app(TwoFactorAuthenticationProvider::class)->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            preg_replace('/\s+/', '', $this->code),
        );

        if (! $valid) {
            throw ValidationException::withMessages(['code' => __('The code is incorrect. Please check the time on your device.')]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->reset('code');
        $this->showRecoveryCodes = true;
        $this->refreshUser();
        Flux::toast(variant: 'success', text: __('Two-factor authentication turned on.'));
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
        Flux::toast(variant: 'success', text: __('New recovery codes generated. The old ones are invalid.'));
    }

    public function disableTwoFactor(): void
    {
        $this->requireConfirmed();

        app(DisableTwoFactorAuthentication::class)(auth()->user());

        $this->reset('code', 'showRecoveryCodes');
        $this->refreshUser();
        Flux::toast(variant: 'success', text: __('Two-factor authentication turned off.'));
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
        Flux::toast(variant: 'success', text: __('Passkey removed.'));
    }
};
?>

@php($user = auth()->user())

<div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
    <div class="space-y-1 p-5">
        <flux:heading size="lg">{{ __('Additional protection') }}</flux:heading>
        <flux:text>{{ __('Two-factor authentication and passkeys protect your account beyond the password. You change them together after confirming your password.') }}</flux:text>
    </div>

    @if ($this->confirmed)
        <div class="flex items-center gap-3 border-t border-green-200 bg-green-50 px-5 py-3 text-sm text-green-900 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200">
            <flux:icon.lock-open variant="mini" class="shrink-0" />
            <span>{{ __('Unlocked until :time – until then you can change everything in this group.', ['time' => $this->confirmedUntil->isoFormat('LT')]) }}</span>
        </div>
    @else
        <form wire:submit="confirmPassword" class="space-y-3 border-t border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="flex items-start gap-3">
                <flux:icon.lock-closed variant="mini" class="mt-0.5 shrink-0 text-zinc-500" />
                <div>
                    <flux:heading>{{ __('Locked – confirm password') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('Unlocks changing two-factor authentication and passkeys for :hours hours.', ['hours' => round(config('auth.password_timeout', 10800) / 3600)]) }}</flux:text>
                </div>
            </div>
            <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1">
                    <flux:input wire:model="password" type="password" placeholder="{{ __('Current password') }}" aria-label="{{ __('Current password') }}" autocomplete="current-password" />
                </div>
                <flux:button type="submit" variant="primary">{{ __('Unlock') }}</flux:button>
            </div>
            <flux:error name="password" />
        </form>
    @endif

    <div class="space-y-4 border-t border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex items-center gap-3">
            <flux:heading>{{ __('Two-factor authentication') }}</flux:heading>
            @if ($user->hasEnabledTwoFactorAuthentication())
                <flux:badge color="green" size="sm">{{ __('On') }}</flux:badge>
            @elseif ($user->two_factor_secret)
                <flux:badge color="amber" size="sm">{{ __('Incomplete') }}</flux:badge>
            @else
                <flux:badge size="sm">{{ __('Off') }}</flux:badge>
            @endif
        </div>

        @if ($user->hasEnabledTwoFactorAuthentication())
            <flux:text>{{ __('When you sign in with a password, a code from your authenticator app is also requested. With a passkey you sign in without a second code.') }}</flux:text>

            @if ($this->confirmed)
                @if ($showRecoveryCodes)
                    <div class="rounded-lg bg-zinc-100 p-4 dark:bg-zinc-800">
                        <flux:text class="mb-2">{{ __('Keep these codes safe. Each works once, in case you lose your device.') }}</flux:text>
                        <div class="grid grid-cols-2 gap-1 font-mono text-sm">
                            @foreach ($user->recoveryCodes() as $recoveryCode)
                                <div wire:key="rc-{{ $loop->index }}">{{ $recoveryCode }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="toggleRecoveryCodes">{{ $showRecoveryCodes ? __('Hide codes') : __('Show recovery codes') }}</flux:button>
                    <flux:button size="sm" wire:click="regenerateRecoveryCodes" wire:confirm="{{ __('Generate new codes? The previous ones will become invalid.') }}">{{ __('Generate new codes') }}</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="disableTwoFactor" wire:confirm="{{ __('Turn off two-factor authentication?') }}">{{ __('Turn off') }}</flux:button>
                </div>
            @endif
        @elseif ($user->two_factor_secret)
            @if ($this->confirmed)
                <flux:text>{{ __('Scan the QR code with an authenticator app (e.g. 1Password, Authy, Google Authenticator) and enter the code shown.') }}</flux:text>
                <div class="inline-block rounded-lg bg-white p-2">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <flux:text size="sm">{{ __('Or type in the key:') }} <code class="font-mono">{{ \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret) }}</code></flux:text>
                <form wire:submit="confirmTwoFactor" class="space-y-3">
                    <flux:otp wire:model="code" length="6" :label="__('Code from the app')" />
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">{{ __('Confirm and turn on') }}</flux:button>
                        <flux:button type="button" variant="ghost" wire:click="disableTwoFactor">{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            @endif
        @else
            <flux:text>{{ __('In addition to the password, a code from an authenticator app is requested at sign-in.') }}</flux:text>
            @if ($this->confirmed)
                <flux:button size="sm" wire:click="enableTwoFactor">{{ __('Set up') }}</flux:button>
            @endif
        @endif
    </div>

    <div class="space-y-4 border-t border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading>{{ __('Passkeys') }}</flux:heading>
        <flux:text>{{ __('Sign in without a password: with fingerprint, face, security key or device lock. A passkey replaces password and second factor.') }}</flux:text>

        @if ($this->passkeys->isNotEmpty())
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->passkeys as $passkey)
                    <div wire:key="passkey-{{ $passkey->id }}" class="flex items-center justify-between gap-3 p-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $passkey->name }}</div>
                            <flux:text size="sm">
                                @if ($passkey->authenticator){{ $passkey->authenticator }} · @endif
                                {{ __('created on :date', ['date' => $passkey->created_at->isoFormat('L')]) }}
                                · {{ $passkey->last_used_at ? __('last used :time', ['time' => $passkey->last_used_at->diffForHumans()]) : __('never used') }}
                            </flux:text>
                        </div>
                        @if ($this->confirmed)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deletePasskey({{ $passkey->id }})" wire:confirm="{{ __('Remove passkey “:name”?', ['name' => $passkey->name]) }}" aria-label="{{ __('Remove passkey') }}" />
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <flux:text>{{ __('No passkey created yet.') }}</flux:text>
        @endif

        @if ($this->confirmed)
            <div x-data="{ supported: !!window.PublicKeyCredential, busy: false, failed: false }" class="space-y-3">
                <flux:text x-show="!supported" x-cloak>{{ __('This browser does not support passkeys.') }}</flux:text>
                <form
                    x-show="supported"
                    x-cloak
                    class="space-y-3"
                    x-on:submit.prevent="
                        busy = true; failed = false
                        window.passkeys().then((passkeys) => passkeys.register({ name: $wire.passkeyName }))
                            .then(() => $wire.passkeysChanged())
                            .catch(() => { failed = true })
                            .finally(() => { busy = false })
                    "
                >
                    <flux:input wire:model="passkeyName" :label="__('Name of the new passkey')" placeholder="{{ __('e.g. MacBook or iPhone') }}" required />
                    <flux:button type="submit" size="sm" icon="plus" x-bind:disabled="busy">{{ __('Add passkey') }}</flux:button>
                    <flux:text x-show="failed" class="text-red-600 dark:text-red-400">{{ __('The passkey could not be created.') }}</flux:text>
                </form>
            </div>
        @endif
    </div>
</div>
