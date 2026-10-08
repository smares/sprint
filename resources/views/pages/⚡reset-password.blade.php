<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $token = '';

    #[Url]
    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    /**
     * A new password ends every session and API token of the account, like a change in the profile.
     */
    public function save(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'same:passwordConfirmation'],
        ], attributes: ['email' => __('Email'), 'password' => __('New password')]);

        $status = Password::broker()->reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->passwordConfirmation, 'token' => $this->token],
            function (User $user, string $password) {
                if (! $user->isActive()) {
                    throw ValidationException::withMessages(['email' => __('This account has been deactivated. Please contact an administrator.')]);
                }

                $user->forceFill(['password' => $password])->save();
                $user->signOutEverywhere();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        session()->flash('status', __($status));
        $this->redirectRoute('login', navigate: true);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Set a new password'));
    }
};
?>

<div class="mx-auto mt-16 max-w-sm px-4 sm:mt-24">
    <div class="mb-6 flex justify-center">
        <x-app-brand :href="route('login')" />
    </div>

    <flux:card>
        <flux:heading size="xl" class="mb-6">{{ __('Set a new password') }}</flux:heading>

        <form wire:submit="save" class="space-y-6">
            <flux:input wire:model="email" :label="__('Email')" type="email" autocomplete="email" />
            <flux:input wire:model="password" :label="__('New password')" type="password" autocomplete="new-password" autofocus :description="__('At least 8 characters.')" />
            <flux:input wire:model="passwordConfirmation" :label="__('Password confirmation')" type="password" autocomplete="new-password" />
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Save password') }}</flux:button>
        </form>
    </flux:card>
</div>
