<?php

use App\Concerns\OpensTaskPanel;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use OpensTaskPanel;

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
            ->with(['project', 'parent', 'status', 'tags'])
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
     * Subtask progress of the listed tasks, worked out per project like in the project list.
     *
     * @return array<int, array{done: int, total: int}>
     */
    #[Computed]
    public function progress(): array
    {
        $progress = [];

        foreach ($this->tasks->groupBy('project_id') as $tasks) {
            $progress += $tasks->first()->project->subtaskProgress($tasks->modelKeys());
        }

        return $progress;
    }

    /**
     * The projects of the listed tasks in which one may tick tasks off.
     *
     * @return list<int>
     */
    #[Computed]
    public function editableProjectIds(): array
    {
        return $this->tasks->pluck('project')->unique('id')
            ->filter(fn (Project $project) => Gate::allows('edit', $project))
            ->pluck('id')->values()->all();
    }

    public function toggleDone(int $taskId): void
    {
        $task = Task::query()->visibleTo(auth()->user())->where('is_section', false)->findOrFail($taskId);
        Gate::authorize('edit', $task->project);

        $task->toggleDone();

        unset($this->tasks, $this->totalTasks, $this->progress);
    }

    /**
     * The flyout opens any task one may see, whatever its project.
     *
     * @return Builder<Task>
     */
    protected function tasksForPanel(): Builder
    {
        return Task::query()->visibleTo(auth()->user());
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
        {{-- Every column shows on every screen and the table scrolls sideways under the title, which stays in place.
             The rows are built from the same pieces as the project list: done button, title with progress and tags, due date. --}}
        <flux:table>
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
                                <div class="min-w-0">
                                    <x-task-title-line :task="$task" :open="(string) $task->id === $openTaskId" :progress="$this->progress[$task->id] ?? null" />
                                    @if ($task->parent)
                                        <flux:text size="sm" class="block">{{ __('in :title', ['title' => $task->parent->title]) }}</flux:text>
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell><flux:link variant="subtle" :href="route('projects.show', $task->project)" wire:navigate>{{ $task->project->name }}</flux:link></flux:table.cell>
                        <flux:table.cell>
                            <x-color-badge size="sm" :color="$task->status->color">{{ $task->status->name }}</x-color-badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-task-due :task="$task" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if ($this->totalTasks > $this->tasks->count())
            <div wire:intersect="loadMore" class="mt-4 flex items-center justify-center gap-3">
                <flux:text size="sm">{{ __(':shown of :total tasks', ['shown' => $this->tasks->count(), 'total' => $this->totalTasks]) }}</flux:text>
                <flux:button size="sm" wire:click="loadMore">{{ __('Load more') }}</flux:button>
            </div>
        @endif
    @endif

    <x-task-panel :task="$this->panelTask" />
</div>
