<?php

namespace App\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * Sensitive account actions need the password again; the confirmation lasts for the session's password timeout.
 */
trait ConfirmsPassword
{
    public string $password = '';

    #[Computed]
    public function confirmed(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) < config('auth.password_timeout', 10800);
    }

    #[Computed]
    public function confirmedUntil(): Carbon
    {
        return now()->setTimestamp((int) session('auth.password_confirmed_at', 0) + (int) config('auth.password_timeout', 10800));
    }

    public function confirmPassword(): void
    {
        $this->resetErrorBag();

        $throttleKey = 'confirm-password|'.auth()->id().'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages(['password' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)])]);
        }

        if (! Hash::check($this->password, auth()->user()->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }

        RateLimiter::clear($throttleKey);
        session(['auth.password_confirmed_at' => time()]);
        $this->reset('password');
        unset($this->confirmed, $this->confirmedUntil);
    }

    protected function requireConfirmed(): void
    {
        abort_unless($this->confirmed, 423);
    }
}
