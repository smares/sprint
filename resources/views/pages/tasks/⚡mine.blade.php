<?php

use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    private const PAGE_SIZE = 50;

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    private function mine(): Builder
    {
        return Task::query()
            ->visibleTo(auth()->user())
            ->involving(auth()->user())
            ->open();
    }

    #[Computed]
    public function tasks(): Collection
    {
        return $this->mine()
            ->with(['project', 'parent', 'status'])
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

    public function rendering(View $view): void
    {
        $view->title(__('My tasks'));
    }
};
?>

<div>
    <flux:heading size="xl" class="mb-6">{{ __('My tasks') }}</flux:heading>

    @if ($this->tasks->isEmpty())
        <flux:callout icon="check-circle" :heading="__('All done')" :text="__('You have no open tasks assigned or none you are involved in.')" />
    @else
        <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Task') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Project') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Due') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}">
                        <flux:table.cell class="min-w-44 whitespace-normal">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>
                            @if ($task->parent)
                                <flux:text size="sm" class="block">{{ __('in :title', ['title' => $task->parent->title]) }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="max-md:hidden">{{ $task->project->name }}</flux:table.cell>
                        <flux:table.cell>
                            <x-color-badge size="sm" :color="$task->status->color">{{ $task->status->name }}</x-color-badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($task->due_date)
                                <flux:text :class="$task->isOverdue() ? 'text-red-500' : ''">{{ $task->due_date->isoFormat('L') }}</flux:text>
                            @else
                                –
                            @endif
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
</div>
