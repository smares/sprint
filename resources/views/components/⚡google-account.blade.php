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
                <form wire:submit="disconnect" class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <flux:text>{{ __('Connected: :email', ['email' => $this->account->email]) }}</flux:text>
                        <flux:button type="submit" size="sm" wire:confirm="{{ __('Disconnect the Google account? You can connect it again here in the profile.') }}">{{ __('Disconnect') }}</flux:button>
                    </div>
                    @unless ($this->confirmed)
                        <flux:input wire:model="password" type="password" :label="__('Current password')" :description="__('Google replaces the second factor, so changing it needs your password.')" autocomplete="current-password" />
                    @endunless
                </form>
            @else
                <form wire:submit="connect" class="space-y-3">
                    @unless ($this->confirmed)
                        <flux:input wire:model="password" type="password" :label="__('Current password')" :description="__('Google replaces the second factor, so changing it needs your password.')" autocomplete="current-password" />
                    @endunless
                    <flux:button type="submit" icon="link">{{ __('Connect Google account') }}</flux:button>
                </form>
            @endif
        </div>

        <flux:separator />
    @endif
</div>
