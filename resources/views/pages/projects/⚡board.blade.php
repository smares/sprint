<?php

use App\Models\Project;
use App\Models\Task;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Project $project;

    /**
     * @return array<string, \Illuminate\Support\Collection<int, Task>>
     */
    #[Computed]
    public function columns(): array
    {
        $tasks = $this->project->tasks()
            ->with('assignee')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Task $task) => $task->status->value);

        return collect(TaskStatus::cases())
            ->mapWithKeys(fn (TaskStatus $status) => [$status->value => $tasks->get($status->value, collect())])
            ->all();
    }

    public function moveTask(int|string $taskId, int $position, string $group): void
    {
        $status = TaskStatus::tryFrom($group);
        abort_if($status === null, 422);

        $task = $this->project->tasks()->findOrFail($taskId);

        DB::transaction(function () use ($task, $status, $position) {
            $orderedIds = $this->project->tasks()
                ->where('status', $status)
                ->whereKeyNot($task->getKey())
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            array_splice($orderedIds, max(0, min($position, count($orderedIds))), 0, [$task->getKey()]);

            $task->update(['status' => $status]);

            foreach ($orderedIds as $index => $id) {
                $this->project->tasks()->whereKey($id)->update(['position' => $index]);
            }
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
    </div>

    <flux:kanban class="items-start overflow-x-auto pb-4">
        @foreach (TaskStatus::cases() as $status)
            <flux:kanban.column wire:key="column-{{ $status->value }}" class="shrink-0">
                <flux:kanban.column.header :heading="$status->label()" :count="$this->columns[$status->value]->count()" />

                <flux:kanban.column.cards
                    class="min-h-16"
                    wire:sort="moveTask"
                    wire:sort:group="tasks"
                    wire:sort:group-id="{{ $status->value }}"
                >
                    @foreach ($this->columns[$status->value] as $task)
                        <flux:kanban.card wire:key="task-{{ $task->id }}" wire:sort:item="{{ $task->id }}">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>

                            <div class="mt-2 flex items-center justify-between gap-2">
                                <flux:text size="sm">{{ $task->assignee?->name ?? 'Niemand' }}</flux:text>
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
