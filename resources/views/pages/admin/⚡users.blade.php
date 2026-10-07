<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Benutzer')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $makeAdmin = false;

    /** @var array<int|string, bool> */
    public array $admins = [];

    /** A password that was just generated; shown once. */
    public ?string $shownPassword = null;

    public ?string $shownFor = null;

    public function mount(): void
    {
        Gate::authorize('administer');

        $this->fillAdmins();
    }

    public function hydrate(): void
    {
        Gate::authorize('administer');
    }

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->get();
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
        ], attributes: ['name' => 'Name', 'email' => 'E-Mail', 'password' => 'Passwort']);

        $password = $validated['password'] ?: Str::password(16, symbols: false);

        User::create([
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'password' => $password,
            'is_admin' => $this->makeAdmin,
        ]);

        $this->shownPassword = $password;
        $this->shownFor = $validated['email'];
        $this->reset('name', 'email', 'password', 'makeAdmin');
        unset($this->users);
        $this->fillAdmins();
    }

    public function updatedAdmins(bool $value, string $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->is($this->me())) {
            $this->admins[$userId] = $user->is_admin;
            Flux::toast(variant: 'danger', text: 'Du kannst dir die Administrator-Rechte nicht selbst entziehen.');

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

        $this->shownPassword = $password;
        $this->shownFor = $user->email;
    }

    public function dismissPassword(): void
    {
        $this->reset('shownPassword', 'shownFor');
    }

    private function me(): User
    {
        return auth()->user();
    }
};
?>

<div class="max-w-3xl">
    <flux:heading size="xl" class="mb-1">Benutzer</flux:heading>
    <flux:text class="mb-6">Hier legst du Personen an und bestimmst die Administratoren. Wer Zugriff auf welche Projekte hat, stellst du im jeweiligen Projekt unter <em>Mitglieder</em> ein.</flux:text>

    @if ($shownPassword)
        <flux:callout variant="warning" icon="key" class="mb-6" heading="Passwort für {{ $shownFor }}">
            <flux:text>Dieses Passwort wird nur jetzt angezeigt: <code class="font-mono font-semibold">{{ $shownPassword }}</code></flux:text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="dismissPassword">Verstanden</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <ul class="space-y-2">
        @foreach ($this->users as $user)
            <li wire:key="user-{{ $user->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:avatar size="sm" :name="$user->name" />
                <div class="min-w-0 flex-1">
                    <flux:heading class="truncate">{{ $user->name }} @if ($user->is(auth()->user())) <flux:badge size="sm">Du</flux:badge> @endif</flux:heading>
                    <flux:text size="sm" class="truncate">{{ $user->email }}</flux:text>
                </div>
                <flux:checkbox wire:model.live="admins.{{ $user->id }}" label="Administrator" />
                <flux:button size="xs" variant="ghost" icon="key" wire:click="resetPassword({{ $user->id }})" wire:confirm="Neues Passwort für {{ $user->name }} erzeugen? Das alte gilt dann nicht mehr." aria-label="Neues Passwort erzeugen" />
            </li>
        @endforeach
    </ul>

    <flux:heading size="lg" class="mb-3 mt-10">Neue Person anlegen</flux:heading>
    <form wire:submit="create" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" label="Name" />
            <flux:input wire:model="email" type="email" label="E-Mail" />
        </div>
        <flux:input wire:model="password" type="password" label="Passwort" description="Leer lassen, dann wird eines erzeugt und einmalig angezeigt." />
        <flux:checkbox wire:model="makeAdmin" label="Administrator der ganzen Anwendung" />
        <flux:button type="submit" variant="primary" icon="plus">Anlegen</flux:button>
    </form>
</div>
