<?php

use App\Models\SocialAccount;
use App\Services\SsoSettings;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Google account of the signed-in person: connected (and can be disconnected) or not (and can be connected, if the
 * administrators have switched signing in with Google on).
 */
new class extends Component
{
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

    public function disconnect(): void
    {
        auth()->user()->socialAccounts()->where('provider', SocialAccount::GOOGLE)->delete();

        unset($this->account);
        Flux::toast(variant: 'success', text: __('Your Google account is disconnected.'));
    }
};
?>

<div>
    @if ($this->account || $this->available)
        <div class="space-y-3">
            <div>
                <flux:heading>{{ __('Google account') }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ __('With it you sign in without a password, and the code of the authenticator app is not asked for.') }}</flux:text>
            </div>

            @if ($this->account)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:text>{{ __('Connected: :email', ['email' => $this->account->email]) }}</flux:text>
                    <flux:button size="sm" wire:click="disconnect" wire:confirm="{{ __('Disconnect the Google account? You can sign in with your password (or have a new one sent by email) afterwards.') }}">{{ __('Disconnect') }}</flux:button>
                </div>
            @else
                {{-- A plain link: the way leads to Google and back, not through the page loading of the app --}}
                <flux:button :href="route('auth.google.link')" icon="link">{{ __('Connect Google account') }}</flux:button>
            @endif
        </div>
    @endif
</div>
