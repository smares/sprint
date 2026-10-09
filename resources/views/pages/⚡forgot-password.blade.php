<?php

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public string $email = '';

    public bool $sent = false;

    /**
     * Sends the link if an active account has this address. The answer is the same either way, so the page
     * does not tell strangers which addresses have an account.
     */
    public function send(): void
    {
        $this->validate(['email' => ['required', 'email', 'max:255']], attributes: ['email' => __('Email')]);

        $throttleKey = 'forgot-password|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages(['email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)])]);
        }

        RateLimiter::hit($throttleKey);

        $user = User::query()->active()->whereRaw('lower(email) = ?', [mb_strtolower(trim($this->email))])->first();

        if ($user !== null) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        $this->sent = true;
    }

    public function rendering(View $view): void
    {
        $view->title(__('Forgot password'));
    }
};
?>

<div class="mx-auto mt-16 max-w-sm px-4 sm:mt-24">
    <div class="mb-6 flex justify-center">
        <x-app-brand :href="route('login')" />
    </div>

    <flux:card class="space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Forgot password') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Enter your email address and we will send you a link to set a new password.') }}</flux:text>
        </div>

        @if ($sent)
            <flux:callout variant="success" icon="envelope" :heading="__('Check your inbox')" :text="__('If an account exists for this address, it will receive a link in a moment. The link is valid for :minutes minutes.', ['minutes' => config('auth.passwords.users.expire')])" />
        @else
            <form wire:submit="send" class="space-y-6">
                <flux:input wire:model="email" :label="__('Email')" type="email" autofocus autocomplete="email" />
                <flux:button type="submit" variant="primary" class="w-full">{{ __('Send link') }}</flux:button>
            </form>
        @endif

        <div class="text-center">
            <flux:link :href="route('login')" wire:navigate class="text-sm">{{ __('Back to sign in') }}</flux:link>
        </div>
    </flux:card>

    <x-locale-switch />
</div>
