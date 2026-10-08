<?php

use App\Models\User;
use App\Services\LocaleService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $makeAdmin = false;

    public string $locale = '';

    /** @var array<int|string, bool> */
    public array $admins = [];

    /** A password that was just generated; shown once. */
    public ?string $shownPassword = null;

    public ?string $shownFor = null;

    public function mount(): void
    {
        Gate::authorize('administer');

        $this->locale = app()->getLocale();

        $this->fillAdmins();
    }

    public function hydrate(): void
    {
        Gate::authorize('administer');
    }

    #[Computed]
    public function users(): Collection
    {
        return User::query()->withCount('passkeys')->orderBy('name')->get();
    }

    private function fillAdmins(): void
    {
        $this->admins = $this->users->pluck('is_admin', 'id')->all();
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'locale' => ['required', Rule::in(LocaleService::codes())],
        ], attributes: ['name' => __('Name'), 'email' => __('Email'), 'password' => __('Password')]);

        $password = $validated['password'] ?: Str::password(16, symbols: false);

        User::create([
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'password' => $password,
            'is_admin' => $this->makeAdmin,
            'locale' => $validated['locale'],
        ]);

        $this->shownPassword = $password;
        $this->shownFor = $validated['email'];
        $this->reset('name', 'email', 'password', 'makeAdmin');
        $this->locale = app()->getLocale();
        unset($this->users);
        $this->fillAdmins();
    }

    public function updatedAdmins(bool $value, string $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->is($this->me())) {
            $this->admins[$userId] = $user->is_admin;
            Flux::toast(variant: 'danger', text: __('You cannot remove your own administrator rights.'));

            return;
        }

        $user->update(['is_admin' => $value]);
        unset($this->users);
    }

    public function resetPassword(int $userId): void
    {
        $user = User::findOrFail($userId);
        $password = Str::password(16, symbols: false);

        $user->update(['password' => $password]);
        $user->signOutEverywhere($user->is(auth()->user()) ? session()->getId() : null);

        $this->shownPassword = $password;
        $this->shownFor = $user->email;
    }

    public function resetSecondFactors(int $userId): void
    {
        User::findOrFail($userId)->resetSecondFactors();

        unset($this->users);
        Flux::toast(variant: 'success', text: __('Two-factor authentication and passkeys reset.'));
    }

    public function deactivate(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->is($this->me())) {
            Flux::toast(variant: 'danger', text: __('You cannot deactivate yourself.'));

            return;
        }

        if ($user->isLastActiveAdmin()) {
            Flux::toast(variant: 'danger', text: __('That is the last active administrator.'));

            return;
        }

        $user->deactivate();
        unset($this->users);
    }

    public function reactivate(int $userId): void
    {
        User::findOrFail($userId)->reactivate();
        unset($this->users);
    }

    public function dismissPassword(): void
    {
        $this->reset('shownPassword', 'shownFor');
    }

    private function me(): User
    {
        return auth()->user();
    }

    public function rendering(View $view): void
    {
        $view->title(__('Users'));
    }
};
?>

<div class="max-w-4xl">
    <flux:heading size="xl" class="mb-1">{{ __('Users') }}</flux:heading>
    <flux:text class="mb-6">{!! __('Create people here and decide who the administrators are. Who has access to which projects is set in the project under <em>Members</em>.') !!}</flux:text>

    @if ($shownPassword)
        <flux:callout variant="warning" icon="key" class="mb-6" :heading="__('Password for :email', ['email' => $shownFor])">
            <flux:text>{{ __('This password is only shown now:') }} <code class="font-mono font-semibold">{{ $shownPassword }}</code></flux:text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="dismissPassword">{{ __('Understood') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <ul class="space-y-2">
        @foreach ($this->users as $user)
            <li wire:key="user-{{ $user->id }}" @class(['flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700', 'opacity-60' => ! $user->isActive()])>
                <flux:avatar size="sm" :name="$user->name" />
                <div class="min-w-0 flex-1">
                    <flux:heading class="truncate">{{ $user->name }} @if ($user->is(auth()->user())) <flux:badge size="sm">{{ __('You') }}</flux:badge> @endif @unless ($user->isActive()) <flux:badge size="sm" color="zinc">{{ __('Deactivated') }}</flux:badge> @endunless</flux:heading>
                    <flux:text size="sm" class="truncate">{{ $user->email }}</flux:text>
                </div>
                {{-- On phones the actions get their own line under the name; the buttons keep their places in every row. --}}
                <div class="flex items-center gap-1 max-sm:w-full max-sm:ps-11">
                    <flux:checkbox wire:model.live="admins.{{ $user->id }}" :label="__('Administrator')" class="me-2" />
                    <flux:button size="xs" variant="ghost" icon="key" wire:click="resetPassword({{ $user->id }})" wire:confirm="{{ __('Generate a new password for :name? The old one will no longer work, and all sessions and API tokens are signed out.', ['name' => $user->name]) }}" :aria-label="__('Generate new password')" :tooltip="__('Generate new password')" />
                    @if ($user->two_factor_secret || $user->passkeys_count)
                        <flux:button size="xs" variant="ghost" icon="shield-exclamation" wire:click="resetSecondFactors({{ $user->id }})" wire:confirm="{{ __('Reset two-factor authentication and passkeys for :name? Afterwards the password alone is enough again.', ['name' => $user->name]) }}" :aria-label="__('Reset two-factor and passkeys')" :tooltip="__('Reset two-factor and passkeys')" />
                    @else
                        <span class="inline-block size-6" aria-hidden="true"></span>
                    @endif
                    @if ($user->isActive())
                        @if ($user->is(auth()->user()))
                            <span class="inline-block size-6" aria-hidden="true"></span>
                        @else
                            <flux:button size="xs" variant="ghost" icon="no-symbol" wire:click="deactivate({{ $user->id }})" wire:confirm="{{ __('Deactivate :name? They will no longer be able to sign in or receive assignments or emails; tasks, comments and history are kept.', ['name' => $user->name]) }}" :aria-label="__('Deactivate')" :tooltip="__('Deactivate')" />
                        @endif
                    @else
                        <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="reactivate({{ $user->id }})" :aria-label="__('Reactivate')" :tooltip="__('Reactivate')" />
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    <flux:heading size="lg" class="mb-3 mt-10">{{ __('Add a new person') }}</flux:heading>
    <form wire:submit="create" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" />
            <flux:input wire:model="email" type="email" :label="__('Email')" />
        </div>
        <flux:input wire:model="password" type="password" :label="__('Password')" :description="__('Leave empty to generate one and show it once.')" />
        <flux:select variant="listbox" wire:model="locale" :label="__('Language')">
            @foreach (\App\Services\LocaleService::available() as $code => $name)
                <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:checkbox wire:model="makeAdmin" :label="__('Administrator of the whole application')" />
        <flux:button type="submit" variant="primary" icon="plus">{{ __('Create person') }}</flux:button>
    </form>
</div>
