<?php

use App\Models\Project;
use App\Models\User;
use App\ProjectRole;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Project $project;

    public string $newUserId = '';

    public string $newRole = 'editor';

    /** @var array<int|string, string> */
    public array $roles = [];

    public function hydrate(): void
    {
        Gate::authorize('manage', $this->project);
    }

    public function mount(): void
    {
        Gate::authorize('manage', $this->project);

        $this->fillRoles();
    }

    #[Computed]
    public function members()
    {
        return $this->project->members()->get();
    }

    #[Computed]
    public function candidates()
    {
        return User::query()
            ->whereDoesntHave('projects', fn ($projects) => $projects->whereKey($this->project->getKey()))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function fillRoles(): void
    {
        $this->roles = $this->members->mapWithKeys(fn (User $member) => [$member->id => $member->pivot->role])->all();
    }

    private function refresh(): void
    {
        unset($this->members, $this->candidates);
        $this->fillRoles();
    }

    /**
     * A project always keeps at least one member who may manage it.
     */
    private function keepsAnAdmin(int $changedUserId, ?ProjectRole $newRole): bool
    {
        if ($newRole === ProjectRole::Admin) {
            return true;
        }

        return $this->project->members()
            ->wherePivot('role', ProjectRole::Admin->value)
            ->where('users.id', '!=', $changedUserId)
            ->exists();
    }

    public function add(): void
    {
        $validated = $this->validate([
            'newUserId' => ['required', Rule::in($this->candidates->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'newRole' => ['required', Rule::enum(ProjectRole::class)],
        ], attributes: ['newUserId' => 'Person', 'newRole' => 'Rolle']);

        $this->project->setRole(User::findOrFail($validated['newUserId']), ProjectRole::from($validated['newRole']));

        $this->reset('newUserId');
        $this->refresh();
    }

    public function updatedRoles(string $value, string $userId): void
    {
        $member = $this->project->members()->where('users.id', $userId)->firstOrFail();
        $role = ProjectRole::tryFrom($value);

        if ($role === null || ! $this->keepsAnAdmin($member->id, $role)) {
            $this->roles[$userId] = $member->pivot->role;

            if ($role !== null) {
                Flux::toast(variant: 'danger', text: 'Ein Projekt braucht mindestens ein Mitglied, das es verwalten darf.');
            }

            return;
        }

        $this->project->setRole($member, $role);
        $this->refresh();
    }

    public function remove(int $userId): void
    {
        $member = $this->project->members()->where('users.id', $userId)->firstOrFail();

        if (! $this->keepsAnAdmin($member->id, null)) {
            Flux::toast(variant: 'danger', text: 'Ein Projekt braucht mindestens ein Mitglied, das es verwalten darf.');

            return;
        }

        $this->project->members()->detach($member->id);
        $this->refresh();
    }

    public function rendering($view): void
    {
        $view->title('Mitglieder – '.$this->project->name);
    }
};
?>

<div class="max-w-2xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item href="{{ route('projects.show', $project) }}" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Mitglieder</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" class="mb-1">Mitglieder</flux:heading>
    <flux:text class="mb-6">Nur Mitglieder sehen dieses Projekt. <strong>Ansehen</strong> darf lesen, <strong>Bearbeiten</strong> darf Aufgaben ändern und kommentieren, <strong>Verwalten</strong> darf zusätzlich Mitglieder und Status ändern. Administratoren der Anwendung haben immer Zugriff.</flux:text>

    <ul class="space-y-2">
        @foreach ($this->members as $member)
            <li wire:key="member-{{ $member->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:avatar size="sm" :name="$member->name" />
                <div class="min-w-0 flex-1">
                    <flux:heading class="truncate">{{ $member->name }}</flux:heading>
                    <flux:text size="sm" class="truncate">{{ $member->email }}</flux:text>
                </div>
                <flux:select size="sm" variant="listbox" wire:model.live="roles.{{ $member->id }}" aria-label="Rolle" class="max-w-36">
                    @foreach (ProjectRole::cases() as $role)
                        <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $member->id }})" aria-label="Entfernen" />
            </li>
        @endforeach
    </ul>

    <form wire:submit="add" class="mt-6 flex items-end gap-2">
        <flux:select variant="listbox" searchable wire:model="newUserId" label="Person hinzufügen" placeholder="Person wählen …" class="min-w-0 flex-1">
            @foreach ($this->candidates as $candidate)
                <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select variant="listbox" wire:model="newRole" label="Rolle" class="max-w-36">
            @foreach (ProjectRole::cases() as $role)
                <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" icon="plus">Hinzufügen</flux:button>
    </form>
    @error('newUserId') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror
</div>
