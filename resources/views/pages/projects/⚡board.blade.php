<?php

use App\Concerns\OpensTaskPanel;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    use OpensTaskPanel;

    private const PAGE_SIZE = 30;

    /** @var array<int, int> */
    #[Locked]
    public array $columnLimits = [];

    public Project $project;

    public function mount(): void
    {
        Gate::authorize('view', $this->project);
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->project);
    }

    #[On('statuses-changed')]
    public function statusesChanged(): void
    {
        unset($this->statuses, $this->columns, $this->columnTotals);
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
        return $this->statuses
            ->mapWithKeys(fn (TaskStatus $status) => [$status->id => $this->columnQuery($status->id)
                ->with(['assignee', 'collaborators', 'tags', 'status', 'blockers.status', 'fieldValues'])
                ->limit($this->columnLimit($status->id))
                ->get()])
            ->all();
    }

    /**
     * How many tasks each column has in total, shown in the header and used to offer "Mehr laden".
     *
     * @return array<int, int>
     */
    #[Computed]
    public function columnTotals(): array
    {
        $counts = $this->project->tasks()->whereNull('parent_id')->selectRaw('status_id, count(*) as total')->groupBy('status_id')->pluck('total', 'status_id');

        return $this->statuses->mapWithKeys(fn (TaskStatus $status) => [$status->id => (int) ($counts[$status->id] ?? 0)])->all();
    }

    private function columnQuery(int $statusId): HasMany
    {
        return $this->project->tasks()
            ->whereNull('parent_id')
            ->where('status_id', $statusId)
            ->orderBy('position')
            ->orderBy('id');
    }

    private function columnLimit(int $statusId): int
    {
        return $this->columnLimits[$statusId] ?? self::PAGE_SIZE;
    }

    public function loadMoreInColumn(int $statusId): void
    {
        abort_unless($this->statuses->contains('id', $statusId), 404);

        $this->columnLimits[$statusId] = $this->columnLimit($statusId) + self::PAGE_SIZE;
    }

    /**
     * Fields shown on the cards.
     */
    #[Computed]
    public function listFields(): Collection
    {
        return $this->project->customFields()->with('options')->where('show_in_list', true)->get();
    }

    #[Computed]
    public function statuses(): Collection
    {
        return $this->project->statuses()->get();
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

            $this->project->placeRootTask($task, $this->columnQuery($status->id)
                ->whereKeyNot($task->getKey())
                ->limit($this->columnLimit($status->id))
                ->pluck('id')
                ->all(), $position);
        });

        unset($this->columns, $this->columnTotals);
    }

    public function rendering(View $view): void
    {
        $view->title($this->project->name);
    }
};
?>

<div @class(['lg:pe-[39rem]' => $this->panelTask])>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @if ($project->archived_at)
        <flux:callout class="mb-4" icon="archive-box" :heading="__('Archived')" :text="__('This project is archived and read-only.')" />
    @endif

    <div @class(['mb-6 flex flex-col gap-4', 'lg:flex-row lg:items-center lg:justify-between' => ! $this->panelTask])>
        <flux:heading size="xl">{{ $project->name }}</flux:heading>

        <div class="flex flex-wrap items-center gap-2">
            <x-project-views :project="$project" active="board" />

            @if ($this->canManage)
                <x-project-menu :project="$project" />
            @endif
        </div>
    </div>

    <flux:kanban class="items-start overflow-x-auto pb-4">
        @foreach ($this->statuses as $status)
            <flux:kanban.column wire:key="column-{{ $status->id }}" class="shrink-0">
                <flux:kanban.column.header :heading="$status->name" :count="$this->columnTotals[$status->id]" />

                <flux:kanban.column.cards
                    class="min-h-16"
                    :wire:sort="$this->canEdit ? 'moveTask' : null"
                    :wire:sort:group="$this->canEdit ? 'tasks' : null"
                    wire:sort:group-id="{{ $status->id }}"
                >
                    @foreach ($this->columns[$status->id] as $task)
                        <flux:kanban.card wire:key="task-{{ $task->id }}" :wire:sort:item="$this->canEdit ? $task->id : null">
                            <a href="{{ route('tasks.show', $task) }}" x-on:click="if ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.button !== 0) return; $event.preventDefault(); $wire.openTask({{ $task->id }})" @class(['font-medium hover:underline', 'text-blue-600 dark:text-blue-400' => (string) $task->id === $openTaskId])>{{ $task->title }}</a>
                            @if ($task->isBlocked())
                                <flux:icon.lock-closed variant="micro" class="ms-1 inline text-amber-500" title="{{ __('Blocked') }}" />
                            @endif
                            @if ($task->isRecurring())
                                <flux:icon.arrow-path variant="micro" class="ms-1 inline text-zinc-400" title="{{ __('Repeats :interval', ['interval' => $task->recurrenceLabel()]) }}" />
                            @endif

                            @if ($progress = $this->progress[$task->id] ?? null)
                                <flux:badge size="sm" icon="list-bullet" class="mt-2">{{ $progress['done'] }}/{{ $progress['total'] }}</flux:badge>
                            @endif

                            @if ($this->listFields->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($this->listFields as $field)
                                        <x-field-value :task="$task" :field="$field" :show-empty="false" :with-name="$field->type !== \App\Enums\CustomFieldType::Select" />
                                    @endforeach
                                </div>
                            @endif

                            @if ($task->tags->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($task->tags as $tag)
                                        <x-color-badge size="sm" :color="$tag->color">{{ $tag->name }}</x-color-badge>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mt-2 flex items-center justify-between gap-2">
                                <flux:text size="sm" title="{{ $task->collaborators->pluck('name')->join(', ') }}">
                                    {{ $task->assignee?->name ?? __('Nobody') }}@if ($task->collaborators->isNotEmpty()) +{{ $task->collaborators->count() }}@endif
                                </flux:text>
                                @if ($task->due_date)
                                    <flux:text size="sm" :class="$task->isOverdue() ? 'text-red-500' : ''">{{ $task->due_date->isoFormat('L') }}</flux:text>
                                @endif
                            </div>
                        </flux:kanban.card>
                    @endforeach
                </flux:kanban.column.cards>

                @if ($this->columnTotals[$status->id] > $this->columns[$status->id]->count())
                    <flux:button size="sm" variant="ghost" class="mt-2 w-full" wire:click="loadMoreInColumn({{ $status->id }})">
                        {{ __('Load more (:shown of :total)', ['shown' => $this->columns[$status->id]->count(), 'total' => $this->columnTotals[$status->id]]) }}
                    </flux:button>
                @endif
            </flux:kanban.column>
        @endforeach
    </flux:kanban>

    <x-task-panel :task="$this->panelTask" />
</div>
