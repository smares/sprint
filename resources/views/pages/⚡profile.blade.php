<?php

use App\Models\UserAvatar;
use App\Services\LocaleService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    /** Confirmation mails per person within CONFIRMATION_DECAY_SECONDS, so the profile cannot be used to flood a mailbox. */
    private const int CONFIRMATION_ATTEMPTS = 3;

    private const int CONFIRMATION_DECAY_SECONDS = 600;

    public string $name = '';

    public string $email = '';

    public string $emailPassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $digest = true;

    public bool $reminders = true;

    public string $locale = '';

    /** The picture as the browser cropped and shrank it (see `avatarPicker` in app.js). */
    public ?TemporaryUploadedFile $avatarUpload = null;

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
        $this->reminders = auth()->user()->reminders_enabled;
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

    public function updatedAvatarUpload(): void
    {
        $this->validate([
            'avatarUpload' => ['required', 'image', 'mimetypes:'.implode(',', UserAvatar::MIME_TYPES), 'max:'.UserAvatar::MAX_KILOBYTES, 'dimensions:max_width=2048,max_height=2048'],
        ], attributes: ['avatarUpload' => __('Profile picture')]);

        auth()->user()->setAvatar((string) $this->avatarUpload->get(), (string) $this->avatarUpload->getMimeType());
        $this->avatarUpload->delete();
        $this->reset('avatarUpload');

        // A full re-render, so that the menu at the top shows the new picture too
        $this->redirectRoute('profile', navigate: true);
    }

    public function removeAvatar(): void
    {
        auth()->user()->removeAvatar();

        $this->redirectRoute('profile', navigate: true);
    }

    public function updatedReminders(bool $value): void
    {
        auth()->user()->update(['reminders_enabled' => $value]);

        Flux::toast(variant: 'success', text: $value ? __('Reminders turned on.') : __('Reminders turned off.'));
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], attributes: ['name' => __('Name'), 'email' => __('Email')]);

        $user->update(['name' => trim($validated['name'])]);

        // Only the letter case changed: the same mailbox, so nothing to confirm
        if (Str::lower($validated['email']) === Str::lower($user->email)) {
            $user->update(['email' => $validated['email']]);
            Flux::toast(variant: 'success', text: __('Profile saved.'));

            return;
        }

        $this->validate(['emailPassword' => ['required']], attributes: ['emailPassword' => __('Password')]);
        $this->checkPasswordThrottled('change-email', $this->emailPassword, 'emailPassword', __('The password is incorrect.'));

        $user->requestEmailChange($validated['email']);
        RateLimiter::hit($this->confirmationThrottleKey(), self::CONFIRMATION_DECAY_SECONDS);

        $this->email = $user->email;
        $this->reset('emailPassword');
        Flux::toast(variant: 'success', text: __('We sent a link to :email. The address changes once you open it.', ['email' => $validated['email']]));
    }

    public function resendEmailConfirmation(): void
    {
        $user = auth()->user();

        if ($user->pending_email === null) {
            return;
        }

        if (RateLimiter::tooManyAttempts($this->confirmationThrottleKey(), self::CONFIRMATION_ATTEMPTS)) {
            Flux::toast(variant: 'warning', text: __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($this->confirmationThrottleKey())]));

            return;
        }

        RateLimiter::hit($this->confirmationThrottleKey(), self::CONFIRMATION_DECAY_SECONDS);
        $user->requestEmailChange($user->pending_email);

        Flux::toast(variant: 'success', text: __('We sent a link to :email. The address changes once you open it.', ['email' => $user->pending_email]));
    }

    public function cancelEmailChange(): void
    {
        auth()->user()->forceFill(['pending_email' => null])->save();
    }

    private function confirmationThrottleKey(): string
    {
        return 'email-confirmation|'.auth()->id();
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

        $this->checkPasswordThrottled('change-password', $this->currentPassword, 'currentPassword', __('The current password is incorrect.'));

        $user->forceFill(['password' => $this->newPassword])->save();
        $user->signOutEverywhere(session()->getId());

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        Flux::toast(variant: 'success', text: __('Password changed. All other sessions and API tokens have been signed out.'));
    }

    /**
     * Compares a password with the stored one and allows five wrong attempts per minute.
     */
    private function checkPasswordThrottled(string $purpose, string $password, string $field, string $wrongMessage): void
    {
        $throttleKey = $purpose.'|'.auth()->id().'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([$field => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)])]);
        }

        if (! Hash::check($password, auth()->user()->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([$field => $wrongMessage]);
        }

        RateLimiter::clear($throttleKey);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Profile'));
    }
};
?>

<div class="max-w-2xl space-y-6">
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

            <div class="flex items-center gap-4" x-data="avatarPicker({{ UserAvatar::SIZE }})">
                <x-user-avatar size="xl" circle :user="auth()->user()" />
                <div class="space-y-2">
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" icon="photo" x-on:click="$refs.avatarFile.click()" x-bind:disabled="busy">{{ __('Choose picture') }}</flux:button>
                        @if (auth()->user()->avatar_updated_at)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeAvatar">{{ __('Remove picture') }}</flux:button>
                        @endif
                    </div>
                    <flux:text size="sm">{{ __('Shown next to your name; it is cropped to a square.') }}</flux:text>
                    <input type="file" x-ref="avatarFile" accept="image/*" class="hidden" x-on:change="pick($event.target.files[0]); $event.target.value = ''">
                    <flux:error name="avatarUpload" />
                </div>
            </div>

            @if (session('status'))
                <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
            @endif
            @if (session('warning'))
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="session('warning')" />
            @endif
            @if (auth()->user()->pending_email)
                <flux:callout icon="envelope" :heading="__('Waiting for confirmation of :email', ['email' => auth()->user()->pending_email])" :text="__('Open the link we sent to that address. Until then, :email stays in use.', ['email' => auth()->user()->email])">
                    <x-slot name="actions">
                        <flux:button size="sm" wire:click="resendEmailConfirmation">{{ __('Send link again') }}</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="cancelEmailChange">{{ __('Cancel change') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif

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

            <flux:switch wire:model.live="reminders" :label="__('Reminder the day before')" :description="__('An entry in your inbox the day before a task you are assigned to or collaborate on is due.')" />
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
