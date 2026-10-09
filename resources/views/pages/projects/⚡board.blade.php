<?php

use App\Concerns\OpensTaskPanel;
use App\Concerns\QuickAddsTasks;
use App\Concerns\ShowsProject;
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
    use ShowsProject;
    use OpensTaskPanel;
    use QuickAddsTasks;

    private const PAGE_SIZE = 30;

    /** @var array<int, int> */
    #[Locked]
    public array $columnLimits = [];

    public Project $project;

    #[On('statuses-changed')]
    public function statusesChanged(): void
    {
        unset($this->statuses, $this->columns, $this->columnTotals);
    }

    /**
     * @return array<int, \Illuminate\Support\Collection<int, Task>>
     */
    #[Computed]
    public function columns(): array
    {
        // One query for all columns: number the tasks within each status and keep the first n per column.
        $ranked = $this->project->tasks()
            ->topLevel()
            ->select('tasks.*')
            ->selectRaw('row_number() over (partition by status_id order by position, id) as column_rank');

        $tasks = Task::query()
            ->fromSub($ranked, 'tasks')
            ->where(function ($query) {
                foreach ($this->statuses as $status) {
                    $query->orWhere(fn ($column) => $column->where('status_id', $status->id)->where('column_rank', '<=', $this->columnLimit($status->id)));
                }
            })
            ->with(['assignee', 'collaborators', 'tags', 'status', 'blockers.status', 'fieldValues'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('status_id');

        return $this->statuses
            ->mapWithKeys(fn (TaskStatus $status) => [$status->id => $tasks->get($status->id, collect())->values()])
            ->all();
    }

    /**
     * How many tasks each column has in total, shown in the header and used to offer "Load more".
     *
     * @return array<int, int>
     */
    #[Computed]
    public function columnTotals(): array
    {
        $counts = $this->project->tasks()->topLevel()->selectRaw('status_id, count(*) as total')->groupBy('status_id')->pluck('total', 'status_id');

        return $this->statuses->mapWithKeys(fn (TaskStatus $status) => [$status->id => (int) ($counts[$status->id] ?? 0)])->all();
    }

    private function columnQuery(int $statusId): HasMany
    {
        return $this->project->tasks()
            ->topLevel()
            ->where('status_id', $statusId)
            ->orderBy('position')
            ->orderBy('id');
    }

    private function columnLimit(int $statusId): int
    {
        return $this->columnLimits[$statusId] ?? self::PAGE_SIZE;
    }

    /**
     * The board has no filters: a new task gets the status of its column.
     *
     * @return array{}
     */
    protected function quickAddDefaults(): array
    {
        return [];
    }

    /**
     * The new task ends its column; a column that was shown completely makes room for it.
     */
    protected function quickAdded(Task $task): void
    {
        $total = $this->columnTotals[$task->status_id] ?? 0;

        if ($total > $this->columnLimit($task->status_id) && $total - 1 <= $this->columnLimit($task->status_id)) {
            $this->columnLimits[$task->status_id] = $total;
        }

        unset($this->columns, $this->columnTotals);
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
        return $this->project->subtaskProgress(collect($this->columns)->flatten(1)->pluck('id')->all());
    }

    public function moveTask(int|string $taskId, int $position, string $group): void
    {
        Gate::authorize('edit', $this->project);

        $status = ctype_digit($group) ? $this->project->statuses()->find((int) $group) : null;
        abort_if($status === null, 422);

        $task = $this->project->tasks()->topLevel()->findOrFail($taskId);

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

<div>
    <x-project-header :project="$project" active="board" :presence="$this->presenceChannel()" />

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
                        <flux:kanban.card wire:key="task-{{ $task->id }}" data-task-id="{{ $task->id }}" :wire:sort:item="$this->canEdit ? $task->id : null">
                            <x-task-title-link :task="$task" :open="(string) $task->id === $openTaskId" />

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

                            @if ($task->assignee || $task->collaborators->isNotEmpty() || $task->due_date)
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-1.5" title="{{ collect([$task->assignee?->name, ...$task->collaborators->pluck('name')])->filter()->join(', ') }}">
                                        @if ($task->assignee)
                                            <x-user-avatar size="xs" :user="$task->assignee" />
                                            <flux:text size="sm" class="truncate">{{ $task->assignee->name }}</flux:text>
                                        @endif
                                        @if ($task->collaborators->isNotEmpty())
                                            <flux:text size="sm">+{{ $task->collaborators->count() }}</flux:text>
                                        @endif
                                    </div>
                                    @if ($task->due_date)
                                        <flux:text size="sm" @class(['shrink-0', 'text-red-500' => $task->isOverdue()])>{{ $task->due_date->isoFormat('L') }}</flux:text>
                                    @endif
                                </div>
                            @endif
                        </flux:kanban.card>
                    @endforeach

                    @if ($this->columns[$status->id]->isEmpty())
                        <flux:text size="sm" class="px-2 py-3 text-center" wire:key="empty-{{ $status->id }}">{{ __('No tasks') }}</flux:text>
                    @endif
                </flux:kanban.column.cards>

                @if ($this->canEdit)
                    <x-quick-add :status-id="$status->id" class="mt-1 px-2 pb-2" />
                @endif

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
