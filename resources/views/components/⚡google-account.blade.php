<?php

use App\Concerns\ConfirmsPassword;
use App\Models\SocialAccount;
use App\Services\SsoSettings;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Google account of the signed-in person: connected (and can be disconnected) or not (and can be connected, if the
 * administrators have switched signing in with Google on). Google replaces the second factor, so connecting and disconnecting
 * need the password again, like the passkeys and the two-factor settings.
 */
new class extends Component
{
    use ConfirmsPassword;

    #[Computed]
    public function account(): ?SocialAccount
    {
        return auth()->user()->socialAccounts()->where('provider', SocialAccount::GOOGLE)->first();
    }

    #[Computed]
    public function available(): bool
    {
        return app(SsoSettings::class)->isAvailable();
    }

    public function connect(): void
    {
        if (! $this->confirmed) {
            $this->confirmPassword();
        }

        $this->redirect(route('auth.google.link'));
    }

    public function disconnect(): void
    {
        if (! $this->confirmed) {
            $this->confirmPassword();
        }

        auth()->user()->socialAccounts()->where('provider', SocialAccount::GOOGLE)->delete();

        unset($this->account);
        Flux::toast(variant: 'success', text: __('Your Google account is disconnected.'));
    }
};
?>

<div @class(['space-y-8', 'hidden' => ! ($this->account || $this->available)])>
    @if ($this->account || $this->available)
        <div class="space-y-3">
            <div>
                <flux:heading>{{ __('Google account') }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ __('With it you sign in without a password, and the code of the authenticator app is not asked for.') }}</flux:text>
            </div>

            @if ($this->account)
                <form wire:submit="disconnect" class="space-y-3" x-data="{ typed: false }" x-init="$nextTick(() => typed = ($el.querySelector('input[type=password]')?.value ?? '') !== '')">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <flux:text>{{ __('Connected: :email', ['email' => $this->account->email]) }}</flux:text>
                        <flux:button type="submit" size="sm" x-bind:disabled="@js(! $this->confirmed) && ! typed" wire:confirm="{{ __('Disconnect the Google account? You can connect it again here in the profile.') }}">{{ __('Disconnect') }}</flux:button>
                    </div>
                    @unless ($this->confirmed)
                        <flux:input wire:model="password" x-on:input="typed = $event.target.value !== ''" x-on:change="typed = $event.target.value !== ''" type="password" :label="__('Current password')" :description="__('Google replaces the second factor, so changing it needs your password.')" autocomplete="current-password" />
                        <flux:text size="sm">{{ __('Signed up with Google and no password yet?') }} <flux:link :href="route('password.request')" variant="ghost" wire:navigate>{{ __('Set one with “Forgot password”.') }}</flux:link></flux:text>
                    @endunless
                    @if ($this->confirmed)
                        <x-unlocked-note :until="$this->confirmedUntil" />
                    @endif
                </form>
            @else
                <form wire:submit="connect" class="space-y-3" x-data="{ typed: false }" x-init="$nextTick(() => typed = ($el.querySelector('input[type=password]')?.value ?? '') !== '')">
                    @unless ($this->confirmed)
                        <flux:input wire:model="password" x-on:input="typed = $event.target.value !== ''" x-on:change="typed = $event.target.value !== ''" type="password" :label="__('Current password')" :description="__('Google replaces the second factor, so changing it needs your password.')" autocomplete="current-password" />
                        <flux:text size="sm">{{ __('Signed up with Google and no password yet?') }} <flux:link :href="route('password.request')" variant="ghost" wire:navigate>{{ __('Set one with “Forgot password”.') }}</flux:link></flux:text>
                    @endunless
                    <flux:button type="submit" icon="link" x-bind:disabled="@js(! $this->confirmed) && ! typed">{{ __('Connect Google account') }}</flux:button>
                    @if ($this->confirmed)
                        <x-unlocked-note :until="$this->confirmedUntil" />
                    @endif
                </form>
            @endif
        </div>

        <flux:separator />
    @endif
</div>
