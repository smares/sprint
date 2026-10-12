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
            ->loadCount(['comments', 'attachments'])
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

    {{-- Columns can be collapsed to a narrow strip with the status upright (data-collapsed, see app.css), remembered per
         project in the browser; a click on the strip opens the column again. --}}
    <div class="scroll-shadows" data-fills-height>
        <flux:kanban class="items-start overflow-x-auto pb-4" data-scroll-edges
            x-data="{ collapsed: $persist([]).as('board-collapsed-{{ $project->id }}'), toggle(id) { this.collapsed = this.collapsed.includes(id) ? this.collapsed.filter((other) => other !== id) : [...this.collapsed, id] } }">
            @foreach ($this->statuses as $status)
                {{-- The status color as a line along the top of the column and a dot before its name --}}
                <flux:kanban.column wire:key="column-{{ $status->id }}" class="shrink-0 border-t-3 border-(--badge)" style="--badge: {{ \App\Color::normalize($status->color) }}"
                    x-bind:data-collapsed="collapsed.includes({{ $status->id }})"
                    x-on:click="collapsed.includes({{ $status->id }}) && toggle({{ $status->id }})">
                    <flux:kanban.column.header :heading="$status->name" :count="$this->columnTotals[$status->id]">
                        <span class="size-2.5 shrink-0 rounded-full bg-(--badge)" aria-hidden="true"></span>

                        <x-slot name="actions">
                            <flux:button size="xs" variant="ghost" square x-on:click.stop="toggle({{ $status->id }})"
                                x-bind:aria-expanded="! collapsed.includes({{ $status->id }})"
                                x-bind:aria-label="collapsed.includes({{ $status->id }}) ? {{ \Illuminate\Support\Js::from(__('Expand column')) }} : {{ \Illuminate\Support\Js::from(__('Collapse column')) }}"
                                x-bind:title="collapsed.includes({{ $status->id }}) ? {{ \Illuminate\Support\Js::from(__('Expand column')) }} : {{ \Illuminate\Support\Js::from(__('Collapse column')) }}">
                                <flux:icon.chevron-double-left variant="micro" x-show="! collapsed.includes({{ $status->id }})" />
                                <flux:icon.chevron-double-right variant="micro" x-show="collapsed.includes({{ $status->id }})" x-cloak />
                            </flux:button>
                        </x-slot>
                    </flux:kanban.column.header>

                    <flux:kanban.column.cards
                        class="min-h-16"
                        :wire:sort="$this->canEdit ? 'moveTask' : null"
                        :wire:sort:group="$this->canEdit ? 'tasks' : null"
                        wire:sort:group-id="{{ $status->id }}"
                        wire:sort:config="{{ \App\Sortable::CONFIG }}"
                    >
                        @foreach ($this->columns[$status->id] as $task)
                            <flux:kanban.card wire:key="task-{{ $task->id }}" data-task-id="{{ $task->id }}" data-opens-task="{{ $task->id }}" class="board-card cursor-pointer" :wire:sort:item="$this->canEdit ? $task->id : null">
                                <x-task-title-link :task="$task" :open="(string) $task->id === $openTaskId" />

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

                                {{-- One line of facts: who (stacked faces), when, and how much is attached to it --}}
                                @php
                                    $progress = $this->progress[$task->id] ?? null;
                                    $people = collect([$task->assignee, ...$task->collaborators])->filter()->unique('id')->values();
                                @endphp
                                @if ($people->isNotEmpty() || $task->due_date || $progress || $task->comments_count || $task->attachments_count)
                                    <div class="mt-3 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        <x-avatar-stack :users="$people" />
                                        @if ($progress)
                                            <span class="inline-flex items-center gap-0.5 tabular-nums" title="{{ __('Subtasks') }}"><flux:icon.list-bullet variant="micro" />{{ $progress['done'] }}/{{ $progress['total'] }}</span>
                                        @endif
                                        @if ($task->comments_count)
                                            <span class="inline-flex items-center gap-0.5 tabular-nums" title="{{ __('Comments') }}"><flux:icon.chat-bubble-left variant="micro" />{{ $task->comments_count }}</span>
                                        @endif
                                        @if ($task->attachments_count)
                                            <span class="inline-flex items-center gap-0.5 tabular-nums" title="{{ __('Attachments') }}"><flux:icon.paper-clip variant="micro" />{{ $task->attachments_count }}</span>
                                        @endif
                                        <x-task-due :task="$task" class="ms-auto" />
                                    </div>
                                @endif
                            </flux:kanban.card>
                        @endforeach

                        @if ($this->columns[$status->id]->isEmpty())
                            <flux:text size="sm" class="px-2 py-3 text-center" wire:key="empty-{{ $status->id }}">{{ __('No tasks') }}</flux:text>
                        @endif
                    </flux:kanban.column.cards>

                    @if ($this->columnTotals[$status->id] > $this->columns[$status->id]->count())
                        <flux:button size="sm" variant="ghost" class="w-full" wire:click="loadMoreInColumn({{ $status->id }})">
                            {{ __('Load more (:shown of :total)', ['shown' => $this->columns[$status->id]->count(), 'total' => $this->columnTotals[$status->id]]) }}
                        </flux:button>
                    @endif

                    @if ($this->canEdit)
                        <x-quick-add :status-id="$status->id" class="mt-1 px-2 pb-2" />
                    @endif
                </flux:kanban.column>
            @endforeach
        </flux:kanban>
    </div>

    <x-task-panel :task="$this->panelTask" />
</div>
