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

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => 'E-Mail oder Passwort ist falsch.']);
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
