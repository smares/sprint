<?php

use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
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
    public function teams(): Collection
    {
        return Team::query()->with('users')->withCount('projects')->orderBy('name')->get();
    }

    #[Computed]
    public function users(): Collection
    {
        return User::query()->active()->orderBy('name')->get(['id', 'name', 'email']);
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
        ], attributes: ['newName' => __('Name')]);

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
            Flux::toast(variant: 'danger', text: __('The name is empty, too long or already taken.'));

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
        ], attributes: ["newMembers.$teamId" => __('Person')]);

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

    public function rendering(View $view): void
    {
        $view->title(__('Teams'));
    }
};
?>

<div class="max-w-3xl">
    <flux:heading size="xl" class="mb-1">{{ __('Teams') }}</flux:heading>
    <flux:text class="mb-6">{!! __('A team is a group of people. In a project under <em>Members</em> you give a whole team access at once.') !!}</flux:text>

    <div class="space-y-4">
        @forelse ($this->teams as $team)
            <flux:card wire:key="team-{{ $team->id }}" class="space-y-3">
                <div class="flex items-center gap-3">
                    <flux:input size="sm" wire:model.blur="names.{{ $team->id }}" aria-label="{{ __('Team name') }}" class="max-w-xs font-semibold" />
                    <flux:text size="sm" class="flex-1">{{ trans_choice('{0} :count projects|{1} :count project|[2,*] :count projects', $team->projects_count) }}</flux:text>
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $team->id }})" aria-label="{{ __('Delete team') }}" />
                </div>

                <div class="flex flex-wrap gap-2">
                    @forelse ($team->users as $member)
                        <flux:badge wire:key="team-{{ $team->id }}-user-{{ $member->id }}" size="lg">
                            {{ $member->name }}
                            <flux:badge.close wire:click="removeMember({{ $team->id }}, {{ $member->id }})" />
                        </flux:badge>
                    @empty
                        <flux:text size="sm">{{ __('No people yet.') }}</flux:text>
                    @endforelse
                </div>

                <form wire:submit="addMember({{ $team->id }})" class="flex items-end gap-2">
                    <flux:select size="sm" variant="listbox" searchable wire:model="newMembers.{{ $team->id }}" placeholder="{{ __('Add person …') }}" class="max-w-xs">
                        @foreach ($this->users->reject(fn ($user) => $team->users->contains('id', $user->id)) as $candidate)
                            <flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" type="submit" icon="plus">{{ __('Add') }}</flux:button>
                </form>
                <flux:error :name="'newMembers.'.$team->id" />
            </flux:card>
        @empty
            <flux:text>{{ __('There are no teams yet.') }}</flux:text>
        @endforelse
    </div>

    <form wire:submit="create" class="mt-8 flex items-end gap-2">
        <flux:input wire:model="newName" :label="__('New team')" placeholder="{{ __('e.g. Engineering') }}" class="max-w-xs" />
        <flux:button type="submit" icon="plus">{{ __('Create team') }}</flux:button>
    </form>
    <flux:error name="newName" class="mt-1" />

    <flux:modal name="delete-team" class="min-w-[22rem]">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete team?') }}</flux:heading>
            <flux:text>{{ __('The team and its access to projects will be removed. The people themselves remain.') }}</flux:text>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
