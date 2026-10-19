<?php

use App\Concerns\ListsMyTasks;
use App\Models\PersonalColumn;
use App\Models\PersonalPlacement;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "My tasks" as a board of one's own columns: the person puts the tasks of all their projects into columns they made
 * themselves, by dragging. The columns and the places are theirs alone and kept on the server. A task nobody put in a
 * column is in "Unsorted" (key 0), ordered by its due date.
 */
new class extends Component
{
    use ListsMyTasks;

    private const PAGE_SIZE = 30;

    /** The key of the column for the tasks that stand in none of the person's columns. */
    private const UNSORTED = 0;

    /** @var array<int, int> */
    #[Locked]
    public array $columnLimits = [];

    public string $newColumnName = '';

    /**
     * Their own columns, in their order.
     *
     * @return Collection<int, PersonalColumn>
     */
    #[Computed]
    public function personalColumns(): Collection
    {
        return auth()->user()->personalColumns()->get();
    }

    /**
     * The open tasks with the column the person put each in (null: none) and the place in it.
     *
     * @return Builder<Task>
     */
    private function placedTasks(): Builder
    {
        $placement = fn (string $column) => DB::table('personal_placements')
            ->select($column)
            ->whereColumn('personal_placements.task_id', 'tasks.id')
            ->where('personal_placements.user_id', auth()->id());

        return $this->mine()
            ->select('tasks.*')
            ->selectSub($placement('personal_column_id'), 'board_column')
            ->selectSub($placement('position'), 'board_position');
    }

    /**
     * @return list<int>
     */
    private function columnKeys(): array
    {
        return [self::UNSORTED, ...$this->personalColumns->modelKeys()];
    }

    /**
     * The first tasks of every column in one query: numbered within each column (in its order, the due date for the
     * unsorted ones) and cut at what the column shows.
     *
     * @return array<int, \Illuminate\Support\Collection<int, Task>>
     */
    #[Computed]
    public function columns(): array
    {
        $ranked = Task::query()
            ->fromSub($this->placedTasks(), 'tasks')
            ->select('tasks.*')
            ->selectRaw('row_number() over (partition by coalesce(board_column, 0) order by board_position, due_date is null, due_date, id) as column_rank');

        $tasks = Task::query()
            ->fromSub($ranked, 'tasks')
            ->where(function (Builder $query) {
                foreach ($this->columnKeys() as $key) {
                    $query->orWhere(fn (Builder $column) => $column->whereRaw('coalesce(board_column, 0) = ?', [$key])->where('column_rank', '<=', $this->columnLimit($key)));
                }
            })
            ->with(['project', 'parent', 'status', 'tags', 'blockers.status'])
            ->orderBy('column_rank')
            ->get()
            ->groupBy(fn (Task $task) => (int) ($task->board_column ?? self::UNSORTED));

        return collect($this->columnKeys())
            ->mapWithKeys(fn (int $key) => [$key => $tasks->get($key, collect())->values()])
            ->all();
    }

    /**
     * How many tasks each column has in all, for its header and "Load more".
     *
     * @return array<int, int>
     */
    #[Computed]
    public function columnTotals(): array
    {
        $counts = Task::query()
            ->fromSub($this->placedTasks(), 'tasks')
            ->selectRaw('coalesce(board_column, 0) as column_key, count(*) as total')
            ->groupBy('column_key')
            ->pluck('total', 'column_key');

        return collect($this->columnKeys())->mapWithKeys(fn (int $key) => [$key => (int) ($counts[$key] ?? 0)])->all();
    }

    private function columnLimit(int $key): int
    {
        return $this->columnLimits[$key] ?? self::PAGE_SIZE;
    }

    public function loadMoreInColumn(int $key): void
    {
        abort_unless(in_array($key, $this->columnKeys(), true), 404);

        $this->columnLimits[$key] = $this->columnLimit($key) + self::PAGE_SIZE;
    }

    /**
     * Subtask progress of the shown tasks, for all their projects at once.
     *
     * @return array<int, array{done: int, total: int}>
     */
    #[Computed]
    public function progress(): array
    {
        return Task::subtaskProgress(collect($this->columns)->flatten(1)->pluck('id')->all());
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function editableProjectIds(): array
    {
        return $this->editableProjectIdsOf(collect($this->columns)->flatten(1));
    }

    protected function forgetTasks(): void
    {
        unset($this->columns, $this->columnTotals, $this->progress, $this->editableProjectIds);
    }

    /**
     * Put a task into one of the person's columns at the given place, or back into "Unsorted" (group 0).
     */
    public function moveTask(int|string $taskId, int $position, string $group): void
    {
        abort_unless(ctype_digit($group), 422);

        $task = $this->mine()->withoutSections()->findOrFail($taskId);

        if ((int) $group === self::UNSORTED) {
            PersonalPlacement::query()->where('user_id', auth()->id())->where('task_id', $task->getKey())->delete();
        } else {
            $column = auth()->user()->personalColumns()->findOrFail((int) $group);
            $visibleTaskIds = $this->mine()->whereIn('tasks.id', $column->placements()->select('task_id'))->pluck('tasks.id')->all();

            $column->place($task, $position, $visibleTaskIds);
        }

        $this->forgetTasks();
    }

    public function addColumn(): void
    {
        $name = mb_substr(trim($this->newColumnName), 0, PersonalColumn::MAX_NAME_LENGTH);
        $this->newColumnName = '';

        if ($name === '' || $this->personalColumns->count() >= PersonalColumn::MAX_PER_USER) {
            return;
        }

        auth()->user()->personalColumns()->create(['name' => $name, 'position' => PersonalColumn::nextPositionIn(auth()->user()->personalColumns())]);

        $this->columnsChanged();
    }

    public function renameColumn(int $columnId, string $name): void
    {
        $name = mb_substr(trim($name), 0, PersonalColumn::MAX_NAME_LENGTH);
        $column = auth()->user()->personalColumns()->findOrFail($columnId);

        if ($name !== '') {
            $column->update(['name' => $name]);
        }

        $this->columnsChanged();
    }

    /**
     * Deleting a column sends its tasks back to "Unsorted".
     */
    public function deleteColumn(int $columnId): void
    {
        auth()->user()->personalColumns()->findOrFail($columnId)->delete();
        unset($this->columnLimits[$columnId]);

        $this->columnsChanged();
    }

    public function moveColumn(int $columnId, string $direction): void
    {
        abort_unless(in_array($direction, ['left', 'right'], true), 422);

        $index = $this->personalColumns->search(fn (PersonalColumn $column) => $column->getKey() === $columnId);
        abort_if($index === false, 404);

        $this->personalColumns[$index]->moveTo(max(0, $index + ($direction === 'left' ? -1 : 1)));

        $this->columnsChanged();
    }

    private function columnsChanged(): void
    {
        unset($this->personalColumns);
        $this->forgetTasks();
    }

    public function rendering(View $view): void
    {
        $view->title(__('My tasks'));
    }
};
?>

<div @class(['pb-40' => $selecting]) data-wide>
    <div class="mb-6 flex items-center justify-between gap-3">
        <flux:heading level="1" size="xl">{{ __('My tasks') }}</flux:heading>
        <div class="flex items-center gap-2">
            @if (! $selecting && array_sum($this->columnTotals) > 0)
                <x-selection-toggle />
            @endif
            <x-mine-views active="board" />
        </div>
    </div>

    <x-mine-collaborating-switch />

    {{-- The columns are the person's own: "Unsorted" holds what they have not put anywhere (and cannot be reordered by hand, it
         follows the due dates), every other column keeps the order the cards were dragged into. As on the project board, a column
         can be collapsed to a narrow strip (data-collapsed, see app.css), remembered in the browser; a click on the strip opens it. --}}
    <div class="scroll-shadows" data-fills-height data-move-targets="{{ json_encode(collect([['id' => 0, 'name' => __('Unsorted')]])->concat($this->personalColumns->map(fn ($column) => ['id' => $column->id, 'name' => $column->name]))->values()->all()) }}">
        <flux:kanban class="relative items-start overflow-x-auto pb-4 max-sm:snap-x max-sm:snap-mandatory" data-scroll-edges
            x-data="{ collapsed: $persist([]).as('mine-board-collapsed'), toggle(id) { this.collapsed = this.collapsed.includes(id) ? this.collapsed.filter((other) => other !== id) : [...this.collapsed, id] } }">
            @foreach ([0 => __('Unsorted')] + $this->personalColumns->pluck('name', 'id')->all() as $key => $name)
                @php
                    $personal = $key !== 0;
                    $tasks = $this->columns[$key];
                @endphp
                {{-- On phones a column leaves the next one in sight; the empty "Unsorted" one is narrower still --}}
                <flux:kanban.column wire:key="column-{{ $key }}" class="shrink-0 border-t-3 border-(--badge) {{ $personal ? '[--badge:var(--color-accent)]' : '[--badge:var(--color-zinc-400)] dark:[--badge:var(--color-zinc-500)]' }} {{ ! $personal && $tasks->isEmpty() ? 'max-sm:w-48' : 'max-sm:w-[min(20rem,calc(100vw-6rem))]' }}"
                    x-data="{
                        renaming: false,
                        settled: false,
                        start() { this.settled = false; this.renaming = true; setTimeout(() => { this.$refs.name.focus(); this.$refs.name.select() }, 50) }, {{-- after the menu closed and gave the keyboard back to its button --}}
                        {{-- Enter and leaving the field both save (once); Escape drops the change. The keyboard goes back to the menu button. --}}
                        finish(save, name, key) {
                            if (this.settled) { return }
                            this.settled = true
                            this.renaming = false
                            const value = this.$refs.name.value.trim()
                            if (save && value !== '' && value !== name) { this.$wire.renameColumn(key, value) } else { this.$refs.name.value = name }
                        },
                    }"
                    x-bind:data-collapsed="collapsed.includes({{ $key }})"
                    x-on:click="collapsed.includes({{ $key }}) && toggle({{ $key }})">
                    <flux:kanban.column.header :count="$this->columnTotals[$key]">
                        @if ($personal)
                            <flux:heading x-show="! renaming">{{ $name }}</flux:heading>
                            <div x-show="renaming" x-cloak class="w-40">
                                <flux:input
                                    size="sm" x-ref="name" type="text" value="{{ $name }}" maxlength="{{ \App\Models\PersonalColumn::MAX_NAME_LENGTH }}"
                                    aria-label="{{ __('Column name') }}"
                                    x-on:keydown.enter.prevent="finish(true, {{ \Illuminate\Support\Js::from($name) }}, {{ $key }}); $refs.options.focus()"
                                    x-on:keydown.escape.prevent.stop="finish(false, {{ \Illuminate\Support\Js::from($name) }}, {{ $key }}); $refs.options.focus()"
                                    x-on:blur="finish(true, {{ \Illuminate\Support\Js::from($name) }}, {{ $key }})"
                                />
                            </div>
                        @else
                            <flux:heading>{{ $name }}</flux:heading>
                        @endif

                        <x-slot name="actions">
                            <x-column-collapse-button :column="$key" :name="$name" />

                            @if ($personal)
                                {{-- Not on the narrow strip of a collapsed column, which only has room for the button that opens it --}}
                                <flux:dropdown align="end" x-show="! collapsed.includes({{ $key }})">
                                    <flux:button x-ref="options" size="xs" variant="ghost" square class="max-sm:size-10!" icon="ellipsis-horizontal" aria-label="{{ __('Column options') }}" tooltip="{{ __('Column options') }}" />

                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" x-on:click="start()">{{ __('Rename') }}</flux:menu.item>
                                        @unless ($loop->iteration === 2)
                                            <flux:menu.item icon="arrow-left" wire:click="moveColumn({{ $key }}, 'left')">{{ __('Move left') }}</flux:menu.item>
                                        @endunless
                                        @unless ($loop->last)
                                            <flux:menu.item icon="arrow-right" wire:click="moveColumn({{ $key }}, 'right')">{{ __('Move right') }}</flux:menu.item>
                                        @endunless
                                        <flux:menu.separator />
                                        <flux:menu.item variant="danger" icon="trash" wire:click="deleteColumn({{ $key }})" wire:confirm="{{ __('Delete this column? Its tasks go back to Unsorted.') }}">{{ __('Delete column') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </x-slot>
                    </flux:kanban.column.header>

                    <flux:kanban.column.cards
                        wire:key="cards-{{ $key }}-{{ $selecting ? 'selecting' : 'idle' }}"
                        class="min-h-16"
                        :wire:sort="$selecting ? null : 'moveTask'"
                        :wire:sort:group="$selecting ? null : 'mine'"
                        wire:sort:group-id="{{ $key }}"
                        wire:sort:config="{{ $personal ? \App\Sortable::CONFIG : \App\Sortable::CONFIG_MOVE_ONLY }}"
                    >
                        @foreach ($tasks as $task)
                            <flux:kanban.card wire:key="task-{{ $task->id }}" data-task-id="{{ $task->id }}" data-opens-task="{{ $task->id }}" class="board-card cursor-pointer" :wire:sort:item="$selecting ? null : $task->id">
                                <div class="flex items-start gap-2">
                                    <x-task-done-button :task="$task" :editable="in_array($task->project_id, $this->editableProjectIds, true)" compact />
                                    @if ($selecting && in_array($task->project_id, $this->editableProjectIds, true))
                                        <x-task-select-box :task="$task" />
                                    @endif
                                    <div class="min-w-0 flex-1 leading-6">
                                        <x-task-title-line :task="$task" :progress="$this->progress[$task->id] ?? null" />
                                        @if ($task->parent)
                                            <flux:text size="sm" class="block">{{ __('in :title', ['title' => $task->parent->title]) }}</flux:text>
                                        @endif
                                    </div>

                                    {{-- Dragging is not the only way: the same moves from a menu (for the keyboard, switches and fingers that do not
                                         drag). Every card can be dragged, so every card has it: the place is the person's own, whatever their role in
                                         the project. The menu is in the page once (x-card-move-menu), the button only tells which card it is. --}}
                                    <flux:button
                                        size="xs" variant="ghost" square class="-me-1 -mt-0.5 max-sm:size-10!" icon="ellipsis-horizontal"
                                        aria-label="{{ __('Move task') }}" aria-haspopup="menu" aria-expanded="false"
                                        data-card-menu="{{ $task->id }}" data-column="{{ $key }}" data-index="{{ $loop->index }}" data-last="{{ $loop->last ? 1 : 0 }}"
                                        x-on:click.stop="$dispatch('open-card-menu', { anchor: $el })"
                                    />
                                </div>

                                <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    <x-project-icon :project="$task->project" size="sm" />
                                    <span class="min-w-0 truncate">{{ $task->project->name }}</span>
                                    <x-status-badge :status="$task->status" />
                                    <x-task-due :task="$task" class="ms-auto" />
                                </div>
                            </flux:kanban.card>
                        @endforeach

                        @if ($tasks->isEmpty())
                            <flux:text size="sm" class="px-2 py-3 text-center" wire:key="empty-{{ $key }}">{{ $personal ? __('Drag tasks here') : __('No tasks') }}</flux:text>
                        @endif

                        {{-- At the end of the cards, like the project board: it loads the next ones when the column is scrolled down to it --}}
                        @if ($this->columnTotals[$key] > $tasks->count())
                            <div class="px-2 py-2" wire:key="more-{{ $key }}" wire:intersect="loadMoreInColumn({{ $key }})">
                                <flux:button size="sm" variant="ghost" class="w-full" wire:click="loadMoreInColumn({{ $key }})">
                                    {{ __('Load more (:shown of :total)', ['shown' => $tasks->count(), 'total' => $this->columnTotals[$key]]) }}
                                </flux:button>
                            </div>
                        @endif
                    </flux:kanban.column.cards>
                </flux:kanban.column>
            @endforeach

            @if ($this->personalColumns->count() < \App\Models\PersonalColumn::MAX_PER_USER)
                <div class="w-72 shrink-0 self-start max-sm:w-[min(18rem,calc(100vw-6rem))] max-sm:snap-start" x-data="{ adding: false, invalid: false, close() { this.adding = false; this.invalid = false; this.$nextTick(() => this.$refs.open.focus()) } }" wire:key="add-column">
                    <flux:button x-ref="open" x-show="! adding" variant="subtle" icon="plus" class="w-full justify-start" x-on:click="adding = true; $nextTick(() => $refs.column.focus())">{{ __('Add column') }}</flux:button>

                    {{-- An empty name keeps the form open and says why; the server is asked only with a name --}}
                    <form x-show="adding" x-cloak x-on:submit.prevent="if ($refs.column.value.trim() === '') { invalid = true; $refs.column.focus(); return } $wire.addColumn(); close()" class="surface flex flex-col gap-2 p-2">
                        <flux:input
                            size="sm" x-ref="column" type="text" wire:model="newColumnName" maxlength="{{ \App\Models\PersonalColumn::MAX_NAME_LENGTH }}" placeholder="{{ __('Column name') }}" aria-label="{{ __('Column name') }}"
                            x-bind:data-invalid="invalid" x-bind:aria-invalid="invalid" aria-describedby="new-column-error"
                            x-on:input="invalid = false"
                            x-on:keydown.escape.prevent="close()"
                        />
                        <flux:text id="new-column-error" size="sm" class="text-red-600 dark:text-red-400" x-show="invalid" x-cloak role="alert">{{ __('Give the column a name.') }}</flux:text>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="primary" type="submit">{{ __('Add') }}</flux:button>
                            <flux:button size="sm" variant="ghost" x-on:click="close()">{{ __('Cancel') }}</flux:button>
                        </div>
                    </form>
                </div>
            @endif
        </flux:kanban>
    </div>

    <x-card-move-menu />

    @if ($selecting)
        <x-selection-bar :change="false" />
    @endif
</div>
