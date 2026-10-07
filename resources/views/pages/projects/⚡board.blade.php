<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Project $project;

    public function mount(): void
    {
        Gate::authorize('view', $this->project);
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->project);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('edit', $this->project);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage', $this->project);
    }

    /**
     * @return array<int, \Illuminate\Support\Collection<int, Task>>
     */
    #[Computed]
    public function columns(): array
    {
        $tasks = $this->project->tasks()
            ->whereNull('parent_id')
            ->with(['assignee', 'collaborators', 'tags', 'status', 'blockers.status'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('status_id');

        return $this->statuses
            ->mapWithKeys(fn (TaskStatus $status) => [$status->id => $tasks->get($status->id, collect())])
            ->all();
    }

    #[Computed]
    public function statuses()
    {
        return $this->project->statuses;
    }

    /**
     * @return array<int, array{done: int, total: int}>
     */
    #[Computed]
    public function progress(): array
    {
        return $this->project->subtaskProgress();
    }

    public function moveTask(int|string $taskId, int $position, string $group): void
    {
        Gate::authorize('edit', $this->project);

        $status = ctype_digit($group) ? $this->project->statuses()->find((int) $group) : null;
        abort_if($status === null, 422);

        $task = $this->project->tasks()->whereNull('parent_id')->findOrFail($taskId);

        DB::transaction(function () use ($task, $status, $position) {
            $task->update(['status_id' => $status->id]);

            $this->project->placeRootTask($task, $this->project->tasks()
                ->whereNull('parent_id')
                ->where('status_id', $status->id)
                ->whereKeyNot($task->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->all(), $position);
        });

        unset($this->columns);
    }

    public function rendering($view): void
    {
        $view->title($this->project->name);
    }
};
?>

<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex items-center justify-between">
        <flux:heading size="xl">{{ $project->name }}</flux:heading>

        <flux:button.group>
            <flux:button icon="list-bullet" href="{{ route('projects.show', $project) }}" wire:navigate>Liste</flux:button>
            <flux:button icon="view-columns" disabled>Board</flux:button>
        </flux:button.group>

            @if ($this->canManage)
                <flux:button icon="cog-6-tooth" href="{{ route('projects.statuses', $project) }}" wire:navigate>Status</flux:button>
                <flux:button icon="users" href="{{ route('projects.members', $project) }}" wire:navigate>Mitglieder</flux:button>
            @endif
    </div>

    <flux:kanban class="items-start overflow-x-auto pb-4">
        @foreach ($this->statuses as $status)
            <flux:kanban.column wire:key="column-{{ $status->id }}" class="shrink-0">
                <flux:kanban.column.header :heading="$status->name" :count="$this->columns[$status->id]->count()" />

                <flux:kanban.column.cards
                    class="min-h-16"
                    :wire:sort="$this->canEdit ? 'moveTask' : null"
                    :wire:sort:group="$this->canEdit ? 'tasks' : null"
                    wire:sort:group-id="{{ $status->id }}"
                >
                    @foreach ($this->columns[$status->id] as $task)
                        <flux:kanban.card wire:key="task-{{ $task->id }}" :wire:sort:item="$this->canEdit ? $task->id : null">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>
                            @if ($task->isBlocked())
                                <flux:icon.lock-closed variant="micro" class="ms-1 inline text-amber-500" title="Blockiert" />
                            @endif

                            @if ($progress = $this->progress[$task->id] ?? null)
                                <flux:badge size="sm" icon="list-bullet" class="mt-2">{{ $progress['done'] }}/{{ $progress['total'] }}</flux:badge>
                            @endif

                            @if ($task->tags->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($task->tags as $tag)
                                        <flux:badge size="sm" :color="$tag->color">{{ $tag->name }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mt-2 flex items-center justify-between gap-2">
                                <flux:text size="sm" title="{{ $task->collaborators->pluck('name')->join(', ') }}">
                                    {{ $task->assignee?->name ?? 'Niemand' }}@if ($task->collaborators->isNotEmpty()) +{{ $task->collaborators->count() }}@endif
                                </flux:text>
                                @if ($task->due_date)
                                    <flux:text size="sm" :class="$task->isOverdue() ? 'text-red-500' : ''">{{ $task->due_date->format('d.m.Y') }}</flux:text>
                                @endif
                            </div>
                        </flux:kanban.card>
                    @endforeach
                </flux:kanban.column.cards>
            </flux:kanban.column>
        @endforeach
    </flux:kanban>
</div>
