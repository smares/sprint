<?php

use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Teams')] class extends Component
{
    public string $newName = '';

    /** @var array<int|string, string> */
    public array $names = [];

    /** @var array<int|string, string> */
    public array $newMembers = [];

    public string $deletingId = '';

    public function mount(): void
    {
        Gate::authorize('administer');

        $this->fillNames();
    }

    public function hydrate(): void
    {
        Gate::authorize('administer');
    }

    #[Computed]
    public function teams()
    {
        return Team::query()->with('users')->withCount('projects')->orderBy('name')->get();
    }

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->get(['id', 'name', 'email']);
    }

    private function fillNames(): void
    {
        $this->names = $this->teams->pluck('name', 'id')->all();
    }

    private function refresh(): void
    {
        unset($this->teams);
        $this->fillNames();
    }

    public function create(): void
    {
        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:100', 'unique:teams,name'],
        ], attributes: ['newName' => 'Name']);

        Team::create(['name' => trim($validated['newName'])]);

        $this->reset('newName');
        $this->refresh();
    }

    public function updatedNames(string $value, string $teamId): void
    {
        $team = Team::findOrFail($teamId);
        $name = trim($value);

        $valid = $name !== '' && mb_strlen($name) <= 100
            && ! Team::where('name', $name)->whereKeyNot($team->id)->exists();

        if (! $valid) {
            $this->names[$teamId] = $team->name;
            Flux::toast(variant: 'danger', text: 'Der Name ist leer, zu lang oder schon vergeben.');

            return;
        }

        $team->update(['name' => $name]);
        $this->refresh();
    }

    public function addMember(int $teamId): void
    {
        $team = Team::findOrFail($teamId);
        $userId = $this->newMembers[$teamId] ?? '';

        $this->validate([
            "newMembers.$teamId" => ['required', Rule::exists('users', 'id')],
        ], attributes: ["newMembers.$teamId" => 'Person']);

        $team->users()->syncWithoutDetaching([(int) $userId]);

        unset($this->newMembers[$teamId]);
        $this->refresh();
    }

    public function removeMember(int $teamId, int $userId): void
    {
        Team::findOrFail($teamId)->users()->detach($userId);

        $this->refresh();
    }

    public function confirmDelete(int $teamId): void
    {
        $this->deletingId = (string) Team::findOrFail($teamId)->id;
        Flux::modal('delete-team')->show();
    }

    public function delete(): void
    {
        Team::findOrFail($this->deletingId)->delete();

        $this->reset('deletingId');
        Flux::modal('delete-team')->close();
        $this->refresh();
    }
};
?>

<div class="max-w-3xl">
    <flux:heading size="xl" class="mb-1">Teams</flux:heading>
    <flux:text class="mb-6">Ein Team ist eine Gruppe von Personen. In einem Projekt unter <em>Mitglieder</em> gibst du einem ganzen Team auf einmal Zugriff.</flux:text>

    <div class="space-y-4">
        @forelse ($this->teams as $team)
            <flux:card wire:key="team-{{ $team->id }}" class="space-y-3">
                <div class="flex items-center gap-3">
                    <flux:input size="sm" wire:model.blur="names.{{ $team->id }}" aria-label="Name des Teams" class="max-w-xs font-semibold" />
                    <flux:text size="sm" class="flex-1">{{ $team->projects_count }} {{ $team->projects_count === 1 ? 'Projekt' : 'Projekte' }}</flux:text>
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $team->id }})" aria-label="Team löschen" />
                </div>

                <div class="flex flex-wrap gap-2">
                    @forelse ($team->users as $member)
                        <flux:badge wire:key="team-{{ $team->id }}-user-{{ $member->id }}" size="lg">
                            {{ $member->name }}
                            <flux:badge.close wire:click="removeMember({{ $team->id }}, {{ $member->id }})" />
                        </flux:badge>
                    @empty
                        <flux:text size="sm">Noch keine Personen.</flux:text>
                    @endforelse
                </div>

                <form wire:submit="addMember({{ $team->id }})" class="flex items-end gap-2">
                    <flux:select size="sm" variant="listbox" searchable wire:model="newMembers.{{ $team->id }}" placeholder="Person hinzufügen …" class="max-w-xs">
                        @foreach ($this->users->reject(fn ($user) => $team->users->contains('id', $user->id)) as $candidate)
                            <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" type="submit" icon="plus">Hinzufügen</flux:button>
                </form>
                @error('newMembers.'.$team->id) <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
            </flux:card>
        @empty
            <flux:text>Es gibt noch keine Teams.</flux:text>
        @endforelse
    </div>

    <form wire:submit="create" class="mt-8 flex items-end gap-2">
        <flux:input wire:model="newName" label="Neues Team" placeholder="z. B. Entwicklung" class="max-w-xs" />
        <flux:button type="submit" icon="plus">Anlegen</flux:button>
    </form>
    @error('newName') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror

    <flux:modal name="delete-team" class="min-w-[22rem]">
        <div class="space-y-6">
            <flux:heading size="lg">Team löschen?</flux:heading>
            <flux:text>Das Team und sein Zugriff auf Projekte werden entfernt. Die Personen selbst bleiben bestehen.</flux:text>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Abbrechen</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Löschen</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
