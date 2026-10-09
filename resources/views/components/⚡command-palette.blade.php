<?php

use App\Models\Project;
use App\Services\TaskSearchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $query = '';

    /**
     * Projects the person can open; while typing only those whose name contains the text, so that
     * every keystroke renders a handful of entries instead of all projects. Names that start with the
     * text come before names that only contain it, and within each the person's favourites lead in
     * their own order, followed by the rest alphabetically.
     */
    #[Computed]
    public function projects(): Collection
    {
        $query = mb_strtolower(trim($this->query));
        $favoritePositions = array_flip($this->favoriteIds);
        $startsWithQuery = fn (Project $project): bool => str_starts_with(mb_strtolower($project->name), $query);
        $favoritePosition = fn (Project $project): int => $favoritePositions[$project->id] ?? PHP_INT_MAX;

        return Project::visibleTo(auth()->user())
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->when($query !== '', fn (Collection $projects) => $projects->filter(fn (Project $project) => str_contains(mb_strtolower($project->name), $query)))
            ->sortBy([
                fn (Project $a, Project $b) => $startsWithQuery($b) <=> $startsWithQuery($a),
                fn (Project $a, Project $b) => $favoritePosition($a) <=> $favoritePosition($b),
            ])
            ->values();
    }

    /**
     * Ids of the person's favourite projects in their own order.
     *
     * @return list<int>
     */
    #[Computed]
    public function favoriteIds(): array
    {
        return auth()->user()->favoriteProjects()->pluck('projects.id')->all();
    }

    /**
     * A handful of matching tasks once at least two characters were typed.
     */
    #[Computed]
    public function tasks(): SupportCollection
    {
        if (mb_strlen(trim($this->query)) < 2) {
            return collect();
        }

        return app(TaskSearchService::class)
            ->search(auth()->user(), $this->query)
            ->with('project:id,name')
            ->limit(8)
            ->get();
    }
};
?>

{{-- The component is kept across page changes (@persist in the layout), so the dialog must be closed by hand: when an entry is chosen
     and on every navigation, or it would stay open on the next page. --}}
<div x-on:livewire:navigate.window="$flux.modal('command-palette').close()">
    <flux:modal name="command-palette" variant="bare" class="my-[12vh] max-h-screen w-full max-w-[32rem] overflow-y-hidden" x-on:close="$wire.set('query', '')">
        <flux:command class="inline-flex max-h-[76vh] flex-col border-none shadow-lg [&_ui-option-empty]:hidden" x-on:click="if ($event.target.closest('[data-flux-command-item]')) { $flux.modal('command-palette').close() }">
            <flux:command.input wire:model.live.debounce.250ms="query" :placeholder="__('Jump to or search for tasks…')" closable autofocus />

            <flux:command.items>
                @if ($this->tasks->isNotEmpty())
                    <div class="[&:not(:has([data-flux-command-item]:not([data-hidden])))]:hidden">
                        <div class="px-2 pb-1 pt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Tasks') }}</div>
                        @foreach ($this->tasks as $task)
                            <flux:command.item wire:key="task-{{ $task->id }}" icon="check-circle" x-on:click="Livewire.navigate('{{ route('projects.show', ['project' => $task->project_id, 'task' => $task->id]) }}')">
                                <span class="truncate">{{ $task->title }}</span>
                                <span class="ms-2 shrink-0 text-xs text-zinc-400">{{ $task->project->name }}</span>
                            </flux:command.item>
                        @endforeach
                    </div>
                @endif

                <div class="[&:not(:has([data-flux-command-item]:not([data-hidden])))]:hidden">
                    <div class="px-2 pb-1 pt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Go to') }}</div>
                    <flux:command.item icon="folder" x-on:click="Livewire.navigate('{{ route('projects.index') }}')">{{ __('Projects') }}</flux:command.item>
                    <flux:command.item icon="check-circle" x-on:click="Livewire.navigate('{{ route('tasks.mine') }}')">{{ __('My tasks') }}</flux:command.item>
                    <flux:command.item icon="bell" x-on:click="Livewire.navigate('{{ route('inbox') }}')">{{ __('Inbox') }}</flux:command.item>
                    <flux:command.item icon="magnifying-glass" x-on:click="Livewire.navigate('{{ route('search') }}')">{{ __('Search') }}</flux:command.item>
                    <flux:command.item icon="user" x-on:click="Livewire.navigate('{{ route('profile') }}')">{{ __('Profile') }}</flux:command.item>
                    @can('administer')
                        <flux:command.item icon="user-group" x-on:click="Livewire.navigate('{{ route('admin.teams') }}')">{{ __('Teams') }}</flux:command.item>
                        <flux:command.item icon="users" x-on:click="Livewire.navigate('{{ route('admin.users') }}')">{{ __('Users') }}</flux:command.item>
                    @endcan
                </div>

                @if ($this->projects->isNotEmpty())
                    <div class="[&:not(:has([data-flux-command-item]:not([data-hidden])))]:hidden">
                        <div class="px-2 pb-1 pt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Projects') }}</div>
                        @foreach ($this->projects as $project)
                            <flux:command.item wire:key="project-{{ $project->id }}" :icon="in_array($project->id, $this->favoriteIds, true) ? 'star' : 'folder-open'" x-on:click="Livewire.navigate('{{ route('projects.show', $project) }}')">{{ $project->name }}</flux:command.item>
                        @endforeach
                    </div>
                @endif

                <div class="[&:not(:has([data-flux-command-item]:not([data-hidden])))]:hidden">
                    <div class="px-2 pb-1 pt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Appearance') }}</div>
                    <flux:command.item icon="sun" x-on:click="$flux.appearance = 'light'">{{ __('Light') }}</flux:command.item>
                    <flux:command.item icon="moon" x-on:click="$flux.appearance = 'dark'">{{ __('Dark') }}</flux:command.item>
                    <flux:command.item icon="computer-desktop" x-on:click="$flux.appearance = 'system'">{{ __('System') }}</flux:command.item>
                </div>

                @if (trim($query) !== '')
                    <flux:command.item wire:key="all-results" icon="magnifying-glass" x-on:click="Livewire.navigate('{{ route('search') }}?q=' + encodeURIComponent($wire.query))">
                        {{ __('Show all results for “:query”', ['query' => trim($query)]) }}
                    </flux:command.item>
                @endif
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
