<?php

use App\Services\LocaleService;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $emailPassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $digest = true;

    public string $locale = '';

    #[Url(as: 'tab', except: 'profile')]
    public string $tab = 'profile';

    public function mount(): void
    {
        if (! in_array($this->tab, ['profile', 'security', 'api'], true)) {
            $this->tab = 'profile';
        }

        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
        $this->digest = auth()->user()->digest_enabled;
        $this->locale = auth()->user()->preferredLocale();
    }

    public function updatedLocale(string $value): void
    {
        $this->validate(['locale' => ['required', Rule::in(LocaleService::codes())]]);

        auth()->user()->update(['locale' => $value]);
        session()->put('locale', $value);
        LocaleService::apply($value);

        $this->redirectRoute('profile', navigate: true);
    }

    public function updatedDigest(bool $value): void
    {
        auth()->user()->update(['digest_enabled' => $value]);

        Flux::toast(variant: 'success', text: $value ? __('Daily digest turned on.') : __('Daily digest turned off.'));
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], attributes: ['name' => __('Name'), 'email' => __('Email')]);

        if (Str::lower($validated['email']) !== Str::lower($user->email)) {
            $this->validate(['emailPassword' => ['required']], attributes: ['emailPassword' => __('Password')]);

            if (! Hash::check($this->emailPassword, $user->password)) {
                throw ValidationException::withMessages(['emailPassword' => __('The password is incorrect.')]);
            }
        }

        $user->update(['name' => trim($validated['name']), 'email' => $validated['email']]);

        $this->reset('emailPassword');
        Flux::toast(variant: 'success', text: __('Profile saved.'));
    }

    public function changePassword(): void
    {
        $user = auth()->user();

        $this->validate([
            'currentPassword' => ['required'],
            'newPassword' => ['required', 'string', 'min:8', 'max:255', 'same:newPasswordConfirmation', 'different:currentPassword'],
            'newPasswordConfirmation' => ['required'],
        ], attributes: [
            'currentPassword' => __('Current password'),
            'newPassword' => __('New password'),
            'newPasswordConfirmation' => __('Password confirmation'),
        ]);

        if (! Hash::check($this->currentPassword, $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => __('The current password is incorrect.')]);
        }

        $user->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        Flux::toast(variant: 'success', text: __('Password changed.'));
    }

    public function rendering($view): void
    {
        $view->title(__('Profile'));
    }
};
?>

<div class="max-w-xl space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Profile') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Your account, its security and access for AI agents.') }}</flux:text>
    </div>

    <flux:tab.group>
        <flux:tabs wire:model="tab" scrollable>
            <flux:tab name="profile" icon="user">{{ __('Profile') }}</flux:tab>
            <flux:tab name="security" icon="shield-check">{{ __('Security') }}</flux:tab>
            <flux:tab name="api" icon="key">{{ __('API access') }}</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="profile" class="space-y-8">
            <div>
                <flux:heading size="lg">{{ __('Personal details') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Your name and email address appear on assignments, comments and in notifications.') }}</flux:text>
            </div>

            <form wire:submit="saveProfile" class="space-y-4">
                <flux:input wire:model="name" :label="__('Name')" autocomplete="name" />
                <flux:input wire:model="email" type="email" :label="__('Email')" autocomplete="email" />
                @if (\Illuminate\Support\Str::lower($email) !== \Illuminate\Support\Str::lower(auth()->user()->email))
                    <flux:input wire:model="emailPassword" type="password" :label="__('Password to confirm')" :description="__('You need your current password to change the email address.')" autocomplete="current-password" />
                @endif
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </form>

            <flux:separator />

            <flux:select variant="listbox" wire:model.live="locale" :label="__('Language')" :description="__('The interface and emails appear in this language.')">
                @foreach (\App\Services\LocaleService::available() as $code => $name)
                    <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:separator />

            <flux:switch wire:model.live="digest" :label="__('Daily digest by email')" :description="__('On weekday mornings, an email with your overdue and soon-due tasks, only if there is something to report.')" />
        </flux:tab.panel>

        <flux:tab.panel name="security" class="space-y-8">
            <form wire:submit="changePassword" class="space-y-4">
                <flux:heading size="lg">{{ __('Change password') }}</flux:heading>
                <flux:input wire:model="currentPassword" type="password" :label="__('Current password')" autocomplete="current-password" />
                <flux:input wire:model="newPassword" type="password" :label="__('New password')" :description="__('At least 8 characters.')" autocomplete="new-password" />
                <flux:input wire:model="newPasswordConfirmation" type="password" :label="__('Repeat new password')" autocomplete="new-password" />
                <flux:button type="submit">{{ __('Change password') }}</flux:button>
            </form>

            <flux:separator />

            <livewire:security />
        </flux:tab.panel>

        <flux:tab.panel name="api">
            <livewire:api-tokens />
        </flux:tab.panel>
    </flux:tab.group>
</div>
