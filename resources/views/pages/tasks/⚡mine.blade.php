<?php

use App\Concerns\ListsMyTasks;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use ListsMyTasks;

    private const PAGE_SIZE = 50;

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    #[Computed]
    public function tasks(): Collection
    {
        return $this->mine()
            ->with(['project', 'parent', 'status', 'tags', 'blockers.status'])
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit($this->limit)
            ->get();
    }

    #[Computed]
    public function totalTasks(): int
    {
        return $this->mine()->count();
    }

    /**
     * Subtask progress of the listed tasks, for all their projects at once.
     *
     * @return array<int, array{done: int, total: int}>
     */
    #[Computed]
    public function progress(): array
    {
        return Task::subtaskProgress($this->tasks->modelKeys());
    }

    /**
     * The projects of the listed tasks in which one may tick tasks off.
     *
     * @return list<int>
     */
    #[Computed]
    public function editableProjectIds(): array
    {
        return $this->editableProjectIdsOf($this->tasks);
    }

    protected function forgetTasks(): void
    {
        unset($this->tasks, $this->totalTasks, $this->progress);
    }

    public function rendering(View $view): void
    {
        $view->title(__('My tasks'));
    }
};
?>

<div @class(['pb-40' => $selecting]) x-bind:class="{ 'pb-40': $wire.selecting }" data-wide>
    <div class="mb-6 flex items-center justify-between gap-3">
        <flux:heading level="1" size="xl">{{ __('My tasks') }}</flux:heading>
        @if ($this->tasks->isNotEmpty())
            <x-selection-toggle instant x-show="! $wire.selecting" class="max-sm:hidden" />
        @endif
    </div>

    {{-- Below lg the views stand in their own row like in a project, the selection button on phones at its right end --}}
    <x-mine-views active="list" class="-mt-2 mb-6">
        @if ($this->tasks->isNotEmpty())
            <x-selection-toggle instant x-show="! $wire.selecting" class="sm:hidden" />
        @endif
    </x-mine-views>

    <x-mine-collaborating-switch />

    @if ($this->tasks->isEmpty())
        <x-empty-state emoji="🎉" :heading="__('All done')" :text="$includeCollaborating ? __('You have no open tasks assigned to you or that you collaborate on.') : __('You have no open tasks assigned to you.')" />
    @else
        {{-- Every column shows on every screen and the table scrolls sideways under the title, which stays in place.
             The rows are built from the same pieces as the project list: done button, title with progress and tags, due date. --}}
        <div class="surface px-3 [--flux-bleed:0.75rem]">
            <flux:table bleed container:class="scroll-shadows rounded-[inherit]">
                <flux:table.columns>
                    <flux:table.column class="pinned-column">{{ __('Task') }}</flux:table.column>
                    <flux:table.column>{{ __('Project') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Due') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->tasks as $task)
                        <flux:table.row wire:key="task-{{ $task->id }}" data-task-id="{{ $task->id }}" data-opens-task="{{ $task->id }}" class="cursor-pointer">
                            <flux:table.cell class="pinned-column min-w-56 whitespace-normal max-sm:min-w-[45vw]">
                                <div class="flex items-center gap-3">
                                    <x-task-done-button :task="$task" :editable="in_array($task->project_id, $this->editableProjectIds, true)" />
                                    {{-- Drawn for every task but made only while selecting (in the browser, at once, before the server answers) --}}
                                    @if (in_array($task->project_id, $this->editableProjectIds, true))
                                        <template x-if="$wire.selecting"><x-task-select-box :task="$task" /></template>
                                    @endif
                                    <div class="min-w-0">
                                        <x-task-title-line :task="$task" :progress="$this->progress[$task->id] ?? null" />
                                        @if ($task->parent)
                                            <flux:text size="sm" class="block">{{ __('in :title', ['title' => $task->parent->title]) }}</flux:text>
                                        @endif
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <x-project-icon :project="$task->project" size="sm" />
                                    <flux:link variant="subtle" :href="route('projects.show', $task->project)" wire:navigate>{{ $task->project->name }}</flux:link>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-status-badge :status="$task->status" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-task-due :task="$task" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($this->totalTasks > $this->tasks->count())
            <div wire:intersect="loadMore" class="mt-4 flex items-center justify-center gap-3">
                <flux:text size="sm">{{ __(':shown of :total tasks', ['shown' => $this->tasks->count(), 'total' => $this->totalTasks]) }}</flux:text>
                <flux:button size="sm" wire:click="loadMore">{{ __('Load more') }}</flux:button>
            </div>
        @endif
    @endif

    {{-- Always there, shown as soon as the browser selects --}}
    <x-selection-bar :change="false" x-show="$wire.selecting" x-cloak />
</div>
