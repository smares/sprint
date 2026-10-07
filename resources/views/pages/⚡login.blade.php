<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Anmelden')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

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

        if (! Auth::attempt([...$credentials, 'deactivated_at' => null], $this->remember)) {
            RateLimiter::hit($throttleKey);

            // Only somebody who knows the password learns that the account was deactivated.
            throw ValidationException::withMessages(['email' => Auth::validate($credentials)
                ? 'Dieses Konto wurde deaktiviert. Bitte wende dich an einen Administrator.'
                : 'E-Mail oder Passwort ist falsch.']);
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        $this->redirectIntended(route('projects.index'), navigate: true);
    }
};
?>

<div class="mx-auto mt-24 max-w-sm">
    <flux:heading size="xl" class="mb-6">Anmelden</flux:heading>

    <form wire:submit="login" class="space-y-6">
        <flux:input wire:model="email" label="E-Mail" type="email" autofocus autocomplete="email" />
        <flux:input wire:model="password" label="Passwort" type="password" autocomplete="current-password" />
        <flux:checkbox wire:model="remember" label="Angemeldet bleiben" />
        <flux:button type="submit" variant="primary" class="w-full">Anmelden</flux:button>
    </form>
</div>
