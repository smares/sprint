<?php

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Enums\ProjectRole;
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

    public string $newTeamId = '';

    public string $newTeamRole = 'editor';

    /** @var array<int|string, string> */
    public array $roles = [];

    /** @var array<int|string, string> */
    public array $teamRoles = [];

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
            ->active()
            ->whereDoesntHave('projects', fn ($projects) => $projects->whereKey($this->project->getKey()))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    #[Computed]
    public function teams()
    {
        return $this->project->teams()->withCount('users')->get();
    }

    #[Computed]
    public function teamCandidates()
    {
        return Team::query()
            ->whereDoesntHave('projects', fn ($projects) => $projects->whereKey($this->project->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function fillRoles(): void
    {
        $this->roles = $this->members->mapWithKeys(fn (User $member) => [$member->id => $member->pivot->role])->all();
        $this->teamRoles = $this->teams->mapWithKeys(fn (Team $team) => [$team->id => $team->pivot->role])->all();
    }

    private function refresh(): void
    {
        unset($this->members, $this->candidates, $this->teams, $this->teamCandidates);
        $this->fillRoles();
    }

    /**
     * A project always keeps somebody who may manage it: a member or a team with that role.
     */
    private function keepsAManager(?int $exceptUserId = null, ?int $exceptTeamId = null): bool
    {
        $member = $this->project->members()
            ->wherePivot('role', ProjectRole::Admin->value)
            ->when($exceptUserId, fn ($members) => $members->where('users.id', '!=', $exceptUserId))
            ->exists();

        return $member || $this->project->teams()
            ->wherePivot('role', ProjectRole::Admin->value)
            ->has('users')
            ->when($exceptTeamId, fn ($teams) => $teams->where('teams.id', '!=', $exceptTeamId))
            ->exists();
    }

    public function add(): void
    {
        $validated = $this->validate([
            'newUserId' => ['required', Rule::in($this->candidates->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'newRole' => ['required', Rule::enum(ProjectRole::class)],
        ], attributes: ['newUserId' => __('Person'), 'newRole' => __('Role')]);

        $this->project->setRole(User::findOrFail($validated['newUserId']), ProjectRole::from($validated['newRole']));

        $this->reset('newUserId');
        $this->refresh();
    }

    public function updatedRoles(string $value, string $userId): void
    {
        $member = $this->project->members()->where('users.id', $userId)->firstOrFail();
        $role = ProjectRole::tryFrom($value);

        if ($role === null || ($member->pivot->role === ProjectRole::Admin->value && $role !== ProjectRole::Admin && ! $this->keepsAManager(exceptUserId: $member->id))) {
            $this->roles[$userId] = $member->pivot->role;

            if ($role !== null) {
                Flux::toast(variant: 'danger', text: __('A project needs at least one member who can manage it.'));
            }

            return;
        }

        $this->project->setRole($member, $role);
        $this->refresh();
    }

    public function remove(int $userId): void
    {
        $member = $this->project->members()->where('users.id', $userId)->firstOrFail();

        if ($member->pivot->role === ProjectRole::Admin->value && ! $this->keepsAManager(exceptUserId: $member->id)) {
            Flux::toast(variant: 'danger', text: __('A project needs at least one member who can manage it.'));

            return;
        }

        $this->project->members()->detach($member->id);
        $this->refresh();
    }

    public function addTeam(): void
    {
        $validated = $this->validate([
            'newTeamId' => ['required', Rule::in($this->teamCandidates->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'newTeamRole' => ['required', Rule::enum(ProjectRole::class)],
        ], attributes: ['newTeamId' => __('Team'), 'newTeamRole' => __('Role')]);

        $this->project->setTeamRole(Team::findOrFail($validated['newTeamId']), ProjectRole::from($validated['newTeamRole']));

        $this->reset('newTeamId');
        $this->refresh();
    }

    public function updatedTeamRoles(string $value, string $teamId): void
    {
        $team = $this->project->teams()->where('teams.id', $teamId)->firstOrFail();
        $role = ProjectRole::tryFrom($value);

        if ($role === null || ($team->pivot->role === ProjectRole::Admin->value && $role !== ProjectRole::Admin && ! $this->keepsAManager(exceptTeamId: $team->id))) {
            $this->teamRoles[$teamId] = $team->pivot->role;

            if ($role !== null) {
                Flux::toast(variant: 'danger', text: __('A project needs at least one member who can manage it.'));
            }

            return;
        }

        $this->project->setTeamRole($team, $role);
        $this->refresh();
    }

    public function removeTeam(int $teamId): void
    {
        $team = $this->project->teams()->where('teams.id', $teamId)->firstOrFail();

        if ($team->pivot->role === ProjectRole::Admin->value && ! $this->keepsAManager(exceptTeamId: $team->id)) {
            Flux::toast(variant: 'danger', text: __('A project needs at least one member who can manage it.'));

            return;
        }

        $this->project->teams()->detach($team->id);
        $this->refresh();
    }

    public function rendering($view): void
    {
        $view->title(__('Members – :project', ['project' => $this->project->name]));
    }
};
?>

<div class="max-w-2xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item href="{{ route('projects.show', $project) }}" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Members') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" class="mb-1">{{ __('Members') }}</flux:heading>
    <flux:text class="mb-6">{!! __('Only members can see this project. <strong>View</strong> can read, <strong>Edit</strong> can change and comment on tasks, <strong>Manage</strong> can also change members and statuses. Application administrators always have access.') !!}</flux:text>

    <ul class="space-y-2">
        @foreach ($this->members as $member)
            <li wire:key="member-{{ $member->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:avatar size="sm" :name="$member->name" />
                <div class="min-w-0 flex-1">
                    <flux:heading class="truncate">{{ $member->name }} @unless ($member->isActive()) <flux:badge size="sm" color="zinc">{{ __('Deactivated') }}</flux:badge> @endunless</flux:heading>
                    <flux:text size="sm" class="truncate">{{ $member->email }}</flux:text>
                </div>
                <flux:select size="sm" variant="listbox" wire:model.live="roles.{{ $member->id }}" aria-label="{{ __('Role') }}" class="max-w-36">
                    @foreach (ProjectRole::cases() as $role)
                        <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $member->id }})" aria-label="{{ __('Remove') }}" />
            </li>
        @endforeach
    </ul>

    <form wire:submit="add" class="mt-6 flex items-end gap-2">
        <flux:select variant="listbox" searchable wire:model="newUserId" :label="__('Add person')" placeholder="{{ __('Choose person …') }}" class="min-w-0 flex-1">
            @foreach ($this->candidates as $candidate)
                <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select variant="listbox" wire:model="newRole" :label="__('Role')" class="max-w-36">
            @foreach (ProjectRole::cases() as $role)
                <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" icon="plus">{{ __('Add') }}</flux:button>
    </form>
    @error('newUserId') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror

    <flux:heading size="lg" class="mb-1 mt-10">{{ __('Teams') }}</flux:heading>
    <flux:text class="mb-4">{{ __('Everyone in a team gets the role chosen here. Anyone who is also a direct member keeps the higher role.') }}</flux:text>

    <ul class="space-y-2">
        @forelse ($this->teams as $team)
            <li wire:key="team-{{ $team->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <flux:icon.user-group class="shrink-0 text-zinc-400" />
                <div class="min-w-0 flex-1">
                    <flux:heading class="truncate">{{ $team->name }}</flux:heading>
                    <flux:text size="sm">{{ trans_choice('{0} :count people|{1} :count person|[2,*] :count people', $team->users_count) }}</flux:text>
                </div>
                <flux:select size="sm" variant="listbox" wire:model.live="teamRoles.{{ $team->id }}" aria-label="{{ __('Role') }}" class="max-w-36">
                    @foreach (ProjectRole::cases() as $role)
                        <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeTeam({{ $team->id }})" aria-label="{{ __('Remove team') }}" />
            </li>
        @empty
            <flux:text>{{ __('No team has access yet.') }}</flux:text>
        @endforelse
    </ul>

    @if ($this->teamCandidates->isNotEmpty())
        <form wire:submit="addTeam" class="mt-4 flex items-end gap-2">
            <flux:select variant="listbox" wire:model="newTeamId" :label="__('Add team')" placeholder="{{ __('Choose team …') }}" class="min-w-0 flex-1">
                @foreach ($this->teamCandidates as $candidate)
                    <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select variant="listbox" wire:model="newTeamRole" :label="__('Role')" class="max-w-36">
                @foreach (ProjectRole::cases() as $role)
                    <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" icon="plus">{{ __('Add') }}</flux:button>
        </form>
        @error('newTeamId') <flux:text class="mt-1 text-red-500">{{ $message }}</flux:text> @enderror
    @endif
</div>
