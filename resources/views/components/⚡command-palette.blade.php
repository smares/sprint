<?php

use App\Models\Project;
use App\Services\TaskSearchService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $query = '';

    /**
     * Projects the person can open.
     */
    #[Computed]
    public function projects()
    {
        return Project::visibleTo(auth()->user())->whereNull('archived_at')->orderBy('name')->get(['id', 'name']);
    }

    /**
     * A handful of matching tasks once at least two characters were typed.
     */
    #[Computed]
    public function tasks()
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

<div>
    <flux:modal name="command-palette" variant="bare" class="my-[12vh] max-h-screen w-full max-w-[32rem] overflow-y-hidden" x-on:close="$wire.set('query', '')">
        <flux:command class="inline-flex max-h-[76vh] flex-col border-none shadow-lg [&_ui-option-empty]:hidden">
            <flux:command.input wire:model.live.debounce.200ms="query" :placeholder="__('Jump to or search for tasks…')" closable autofocus />

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
                            <flux:command.item wire:key="project-{{ $project->id }}" icon="folder-open" x-on:click="Livewire.navigate('{{ route('projects.show', $project) }}')">{{ $project->name }}</flux:command.item>
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
