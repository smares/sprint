<?php

use App\Models\UserAvatar;
use App\Notifications\TestPush;
use App\Services\LocaleService;
use App\Services\PushService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use NotificationChannels\WebPush\PushSubscription;

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

    public bool $celebrations = true;

    public string $locale = '';

    /** The one planned absence (Y-m-d), empty when there is none. */
    public string $absentFrom = '';

    public string $absentUntil = '';

    /** Do not disturb: a daily quiet time (H:i, may span midnight) and weekdays without email (1 = Monday). */
    public bool $quietHours = false;

    public string $quietFrom = '18:00';

    public string $quietUntil = '08:00';

    /** @var list<int|string> */
    public array $quietDays = [];

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
        $this->celebrations = auth()->user()->celebrations_enabled;
        $this->locale = auth()->user()->preferredLocale();
        $this->quietHours = auth()->user()->quiet_from !== null;
        $this->quietFrom = auth()->user()->quiet_from ?? $this->quietFrom;
        $this->quietUntil = auth()->user()->quiet_until ?? $this->quietUntil;
        $this->quietDays = array_map(strval(...), auth()->user()->quiet_days ?? []);

        // An absence that is over is not shown again
        if (auth()->user()->hasPlannedAbsence()) {
            $this->absentFrom = auth()->user()->absent_from->toDateString();
            $this->absentUntil = auth()->user()->absent_until->toDateString();
        }
    }

    public function saveAbsence(): void
    {
        $validated = $this->validate([
            'absentFrom' => ['required', 'date_format:Y-m-d'],
            'absentUntil' => ['required', 'date_format:Y-m-d', 'after_or_equal:absentFrom', 'after_or_equal:today'],
        ], attributes: ['absentFrom' => __('Away from'), 'absentUntil' => __('Away until')]);

        auth()->user()->update(['absent_from' => $validated['absentFrom'], 'absent_until' => $validated['absentUntil']]);
        Flux::toast(variant: 'success', text: __('Absence saved.'));
    }

    public function saveQuietTimes(): void
    {
        $validated = $this->validate([
            'quietHours' => ['boolean'],
            'quietFrom' => ['exclude_unless:quietHours,true', 'required', 'date_format:H:i'],
            'quietUntil' => ['exclude_unless:quietHours,true', 'required', 'date_format:H:i', 'different:quietFrom'],
            'quietDays' => ['array'],
            'quietDays.*' => ['integer', 'between:1,7'],
        ], attributes: ['quietFrom' => __('From'), 'quietUntil' => __('Until')]);

        $days = array_values(array_unique(array_map(intval(...), $validated['quietDays'])));
        sort($days);

        auth()->user()->update([
            'quiet_from' => $validated['quietHours'] ? $validated['quietFrom'] : null,
            'quiet_until' => $validated['quietHours'] ? $validated['quietUntil'] : null,
            'quiet_days' => $days === [] ? null : $days,
        ]);

        Flux::toast(variant: 'success', text: __('Do not disturb saved.'));
    }

    public function clearAbsence(): void
    {
        auth()->user()->update(['absent_from' => null, 'absent_until' => null]);
        $this->reset('absentFrom', 'absentUntil');
        $this->resetErrorBag(['absentFrom', 'absentUntil']);
        Flux::toast(variant: 'success', text: __('Absence removed.'));
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

    public function updatedCelebrations(bool $value): void
    {
        auth()->user()->update(['celebrations_enabled' => $value]);

        Flux::toast(variant: 'success', text: $value ? __('Celebrations turned on.') : __('Celebrations turned off.'));
    }

    /**
     * Whether this browser's subscription (see pushToggle in app.js) is stored for the person.
     */
    #[Renderless]
    public function hasPushSubscription(string $endpoint): bool
    {
        return auth()->user()->pushSubscriptions()->where('endpoint', $endpoint)->exists();
    }

    /**
     * Stores the subscription the browser made with the installation's public key; the same browser again replaces it,
     * and a browser someone else used before is moved over to this person.
     */
    #[Renderless]
    public function savePushSubscription(string $endpoint, string $publicKey, string $authToken, string $contentEncoding): bool
    {
        abort_unless(PushService::configured(), 404);

        Validator::make(compact('endpoint', 'publicKey', 'authToken', 'contentEncoding'), [
            'endpoint' => ['required', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH, fn (string $attribute, string $value, Closure $fail) => PushService::isPushServiceEndpoint($value) || $fail(__('This browser\'s push service is not supported.'))],
            'publicKey' => ['required', 'string', 'max:255'],
            'authToken' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['required', Rule::in(['aesgcm', 'aes128gcm'])],
        ])->validate();

        auth()->user()->updatePushSubscription($endpoint, $publicKey, $authToken, $contentEncoding);

        Flux::toast(variant: 'success', text: __('Push notifications turned on for this device.'));

        return true;
    }

    #[Renderless]
    public function removePushSubscription(string $endpoint): void
    {
        auth()->user()->deletePushSubscription($endpoint);

        Flux::toast(variant: 'success', text: __('Push notifications turned off for this device.'));
    }

    /**
     * A test notification to every device the person turned push on for, a few times a minute at most.
     */
    #[Renderless]
    public function sendTestPush(): void
    {
        $key = 'test-push:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            Flux::toast(variant: 'warning', text: __('Please wait a moment before sending another test.'));

            return;
        }

        RateLimiter::hit($key);
        auth()->user()->notify(new TestPush);

        Flux::toast(text: __('Test sent. It should appear in a few seconds.'));
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

        // Each new address gets a mail: the same limit as for sending the link again
        if (RateLimiter::tooManyAttempts($this->confirmationThrottleKey(), self::CONFIRMATION_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($this->confirmationThrottleKey())])]);
        }

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
                <x-user-avatar size="xl" :user="auth()->user()" />
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

            <flux:switch wire:model.live="celebrations" :label="__('Celebrate completed tasks')" :description="__('Now and then a unicorn flies across the screen when you complete a task. Not shown if your device is set to reduce motion.')" />

            <flux:separator />

            {{-- The state of this browser is only known in the browser, see pushToggle in app.js --}}
            <div x-data="pushToggle(@js(PushService::publicKey()))" class="space-y-3">
                <div>
                    <flux:heading>{{ __('Push notifications') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('New entries in your inbox also appear as a notification on this device, even when Sprint is closed. Turn them on on each device you want them on; absence and do not disturb apply to them as well.') }}</flux:text>
                </div>

                @if (PushService::configured())
                    <flux:text x-show="state === 'unsupported'" x-cloak>{{ __('This browser does not support push notifications.') }}</flux:text>
                    <flux:text x-show="state === 'install'" x-cloak>{{ __('On iPhone and iPad, first add Sprint to the home screen (Share → Add to Home Screen) and turn push notifications on in the app opened from there.') }}</flux:text>
                    <flux:text x-show="state === 'denied'" x-cloak>{{ __('Notifications are blocked for Sprint in this browser. Allow them in the site settings of the browser and reload the page.') }}</flux:text>
                    <flux:text x-show="state === 'failed'" x-cloak class="text-red-600! dark:text-red-400!">{{ __('Push notifications could not be turned on. Please try again.') }}</flux:text>

                    <div x-show="state === 'off' || state === 'failed'" x-cloak>
                        <flux:button icon="bell" x-on:click="turnOn" x-bind:disabled="busy">{{ __('Turn on for this device') }}</flux:button>
                    </div>

                    <div x-show="state === 'on'" x-cloak class="flex flex-wrap items-center gap-2">
                        <flux:badge color="green" icon="check">{{ __('On for this device') }}</flux:badge>
                        <flux:button size="sm" wire:click="sendTestPush">{{ __('Send a test') }}</flux:button>
                        <flux:button size="sm" variant="ghost" x-on:click="turnOff" x-bind:disabled="busy">{{ __('Turn off') }}</flux:button>
                    </div>
                @else
                    <flux:text>{{ __('Not set up on this installation yet: the administrator needs to create the keys (see the documentation, “Push notifications”).') }}</flux:text>
                @endif
            </div>

            <flux:separator />

            <form wire:submit="saveQuietTimes" class="space-y-4">
                <div>
                    <flux:heading>{{ __('Do not disturb') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('No emails or push notifications at these times; your inbox still collects everything. Times are in the time zone :zone.', ['zone' => config('app.timezone')]) }}</flux:text>
                </div>

                <flux:switch wire:model.live="quietHours" :label="__('Every day')" :description="__('No emails or push notifications between these times, for example from 18:30 to 08:00.')" />

                @if ($quietHours)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:time-picker wire:model="quietFrom" :label="__('From')" time-format="24-hour" />
                        <flux:time-picker wire:model="quietUntil" :label="__('Until')" time-format="24-hour" />
                    </div>
                @endif

                <flux:checkbox.group wire:model="quietDays" variant="pills" :label="__('All day')" :description="__('No emails or push notifications at all on these days, for example at the weekend.')">
                    @foreach (range(1, 7) as $day)
                        <flux:checkbox value="{{ $day }}" :label="now()->startOfWeek()->addDays($day - 1)->isoFormat('dd')" />
                    @endforeach
                </flux:checkbox.group>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </form>

            <flux:separator />

            <form wire:submit="saveAbsence" class="space-y-4">
                <div>
                    <flux:heading>{{ __('Absence') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('While you are away you get no emails or push notifications; your inbox still collects everything. Others see “away until …” next to your name.') }}</flux:text>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:date-picker wire:model="absentFrom" :label="__('Away from')" locale="{{ app()->getLocale() }}" :placeholder="__('Select a date')" />
                    <flux:date-picker wire:model="absentUntil" :label="__('Away until')" locale="{{ app()->getLocale() }}" :placeholder="__('Select a date')" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button type="submit" variant="primary">{{ __('Save absence') }}</flux:button>
                    @if (auth()->user()->hasPlannedAbsence())
                        <flux:button type="button" variant="ghost" wire:click="clearAbsence">{{ __('Remove absence') }}</flux:button>
                    @endif
                </div>
            </form>
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
