<?php

use App\Concerns\AssignsTasks;
use App\Concerns\EditsTasksInBulk;
use App\Concerns\ManagesSavedViews;
use App\Concerns\RefreshesOnTaskChanges;
use App\Concerns\QuickAddsTasks;
use App\Concerns\ShowsProject;
use App\Concerns\SortsTasks;
use App\Models\Project;
use App\Models\SavedFilter;
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
    use AssignsTasks;
    use EditsTasksInBulk;
    use RefreshesOnTaskChanges;
    use ManagesSavedViews;
    use QuickAddsTasks;
    use SortsTasks;

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
        // The chosen order (if any) comes before the manual one, which settles ties
        $terms = $this->sortTerms();
        $order = implode(', ', [...array_column($terms, 0), 'tasks.position', 'tasks.id']);
        $bindings = array_merge(...array_column($terms, 1));

        $ranked = $this->project->tasks()
            ->topLevel()
            ->withoutSections()
            ->select('tasks.*')
            ->selectRaw("row_number() over (partition by status_id order by {$order}) as column_rank", $bindings);

        $tasks = Task::query()
            ->fromSub($ranked, 'tasks')
            ->where(function ($query) {
                foreach ($this->statuses as $status) {
                    $query->orWhere(fn ($column) => $column->where('status_id', $status->id)->where('column_rank', '<=', $this->columnLimit($status->id)));
                }
            })
            ->with(['assignee', 'collaborators', 'tags', 'status', 'blockers.status', 'fieldValues'])
            ->orderBy('column_rank')
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
        $counts = $this->project->tasks()->topLevel()->withoutSections()->selectRaw('status_id, count(*) as total')->groupBy('status_id')->pluck('total', 'status_id');

        return $this->statuses->mapWithKeys(fn (TaskStatus $status) => [$status->id => (int) ($counts[$status->id] ?? 0)])->all();
    }

    private function columnQuery(int $statusId): HasMany
    {
        return $this->project->tasks()
            ->topLevel()
            ->withoutSections()
            ->where('status_id', $statusId)
            ->orderBy('position')
            ->orderBy('id');
    }

    private function columnLimit(int $statusId): int
    {
        return $this->columnLimits[$statusId] ?? self::PAGE_SIZE;
    }

    /**
     * The board has no filters: every top-level task of the project can be selected.
     */
    protected function filteredTasks(): HasMany
    {
        return $this->project->tasks()->topLevel();
    }

    protected function forgetTasksAfterBulk(): void
    {
        unset($this->columns, $this->columnTotals, $this->progress);
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

    protected function afterAssigning(): void
    {
        unset($this->columns);
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
        return $this->customFields->where('show_in_list', true)->values();
    }

    #[Computed]
    public function customFields(): Collection
    {
        return $this->project->customFields()->with('options')->get();
    }

    /**
     * A column of the board has one status, so that is nothing to sort it by.
     *
     * @return list<string>
     */
    protected function sortableColumns(): array
    {
        return ['due', 'title'];
    }

    protected function sorted(): void
    {
        $this->columnLimits = [];

        unset($this->columns, $this->columnTotals);
    }

    protected function savedViewKind(): string
    {
        return SavedFilter::BOARD;
    }

    /**
     * @return array<string, string>
     */
    protected function savedViewQueryProperties(): array
    {
        return ['sort' => 'sortBy', 'dir' => 'sortDirection'];
    }

    /**
     * The board has no filters: a saved view is a sort order.
     *
     * @return array{sort: string, direction: string}
     */
    protected function currentViewSettings(): array
    {
        return ['sort' => $this->isSorted() ? $this->sortBy : '', 'direction' => $this->direction()];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function applyViewSettings(array $settings): void
    {
        $this->applySavedSort($settings);
        $this->sorted();
    }

    /**
     * What the sort menu shows for the order in force.
     */
    #[Computed]
    public function sortLabel(): string
    {
        return match (true) {
            ! $this->isSorted() => __('Manual order'),
            $this->sortBy === 'due' => __('Due'),
            $this->sortBy === 'title' => __('Title'),
            default => (string) $this->customFields->firstWhere('id', (int) substr($this->sortBy, 6))?->name,
        };
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

            // A sort order puts the card where it belongs on the screen, so the place it was dropped at means nothing; in the
            // manual order, which shows again when the sort is reset, it goes first in its column
            $position = $this->isSorted() ? 0 : $position;

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

<div @class(['pb-40' => $selecting]) x-bind:class="{ 'pb-40': $wire.selecting }" data-wide>
    <x-project-header :project="$project" active="board" :presence="$this->presenceChannel()" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <flux:dropdown align="start">
            <flux:button icon="arrows-up-down">{{ $this->sortActive ? $this->sortLabel : __('Sort') }}</flux:button>

            {{-- What to sort by; picking one orders the cards at once --}}
            <flux:menu>
                <flux:menu.radio.group wire:model.live="sortBy">
                    <flux:menu.radio value="">{{ __('Manual order') }}</flux:menu.radio>
                    <flux:menu.radio value="due">{{ __('Due') }}</flux:menu.radio>
                    <flux:menu.radio value="title">{{ __('Title') }}</flux:menu.radio>
                    @foreach ($this->customFields->where('show_in_list', true) as $field)
                        <flux:menu.radio wire:key="sort-field-{{ $field->id }}" value="field:{{ $field->id }}">{{ $field->name }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>

        {{-- The direction is one click on its own: the arrow shows the order in force and turns it --}}
        @if ($this->sortActive)
            <flux:button :icon="$sortDirection === 'desc' ? 'arrow-down' : 'arrow-up'" wire:click="reverseSort" class="max-sm:size-10!" aria-label="{{ $sortDirection === 'desc' ? __('Descending') : __('Ascending') }}, {{ __('Reverse order') }}" tooltip="{{ $sortDirection === 'desc' ? __('Descending') : __('Ascending') }}: {{ __('Reverse order') }}" />
            <flux:button icon="x-mark" wire:click="resetSort" class="max-sm:size-10!" aria-label="{{ __('Reset') }}" tooltip="{{ __('Reset') }}" />
        @endif

        <flux:modal.trigger name="saved-filters">
            <flux:button icon="bookmark">{{ __('Views') }} @if ($this->savedFilters->isNotEmpty()) <flux:badge size="sm" inset="top bottom">{{ $this->savedFilters->count() }}</flux:badge> @endif</flux:button>
        </flux:modal.trigger>

        @if ($this->canEdit && ! $selecting && array_sum($this->columnTotals) > 0)
            <x-selection-toggle instant class="ms-auto" x-show="! $wire.selecting" />
        @endif
    </div>

    <x-saved-views-modal :views="$this->savedFilters" :defaults="$this->savedViewDefaults" :can-manage="$this->canManage"
        :description="__('A view remembers the sorting of the board.')" :save-label="__('Save current sorting as')" :placeholder="__('e.g. By due date')" />

    {{-- Columns can be collapsed to a narrow strip with the status upright (data-collapsed, see app.css), remembered per
         project for the person; a click on the strip opens the column again. --}}
    <div class="scroll-shadows" data-fills-height>
        <flux:kanban class="relative items-start overflow-x-auto pb-4 max-sm:snap-x max-sm:snap-mandatory" data-scroll-edges
            x-data="{ collapsed: window.sprintInterface?.board_collapsed?.[{{ $project->id }}] ?? {{ json_encode(auth()->user()->collapsedColumns($project->id)) }}, toggle(id) { this.collapsed = this.collapsed.includes(id) ? this.collapsed.filter((other) => other !== id) : [...this.collapsed, id]; saveInterface({ board_collapsed: { {{ $project->id }}: { [id]: this.collapsed.includes(id) } } }) } }">
            @foreach ($this->statuses as $status)
                {{-- The status color as a line along the top of the column and a dot before its name --}}
                <flux:kanban.column wire:key="column-{{ $status->id }}" class="shrink-0 border-t-3 border-(--badge) max-sm:w-[min(20rem,calc(100vw-6rem))]" style="--badge: {{ \App\Color::normalize($status->color) }}"
                    x-bind:data-collapsed="collapsed.includes({{ $status->id }})"
                    x-on:click="collapsed.includes({{ $status->id }}) && toggle({{ $status->id }})">
                    <flux:kanban.column.header :heading="$status->name" :count="$this->columnTotals[$status->id]">
                        <span class="size-2.5 shrink-0 rounded-full bg-(--badge)" aria-hidden="true"></span>

                        <x-slot name="actions">
                            <x-column-collapse-button :column="$status->id" :name="$status->name" />
                        </x-slot>
                    </flux:kanban.column.header>

                    {{-- wire:sort is set up once per element: keyed by the mode, the list is built anew and dragging within a column
                         really stops while the cards are sorted --}}
                    <flux:kanban.column.cards
                        wire:key="cards-{{ $status->id }}-{{ $this->sortActive ? 'sorted' : 'manual' }}"
                        class="min-h-16"
                        :wire:sort="$this->canEdit ? 'moveTask' : null"
                        :wire:sort:group="$this->canEdit ? 'tasks' : null"
                        wire:sort:group-id="{{ $status->id }}"
                        wire:sort:config="{{ \App\Sortable::unlessSelecting($this->sortActive ? \App\Sortable::CONFIG_MOVE_ONLY : \App\Sortable::CONFIG) }}"
                    >
                        @foreach ($this->columns[$status->id] as $task)
                            <flux:kanban.card wire:key="task-{{ $task->id }}" data-task-id="{{ $task->id }}" data-opens-task="{{ $task->id }}" class="board-card cursor-pointer" :wire:sort:item="$this->canEdit ? $task->id : null">
                                <div class="flex items-start gap-2">
                                    <x-task-done-button :task="$task" :editable="$this->canEdit" compact />
                                    {{-- Drawn for every card but made only while selecting (in the browser, at once, before the server answers) --}}
                                    @if ($this->canEdit)
                                        <template x-if="$wire.selecting"><x-task-select-box :task="$task" /></template>
                                    @endif
                                    <div class="min-w-0 flex-1 leading-6">
                                        <x-task-title-link :task="$task" />
                                    </div>
                                </div>

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
                                @endphp
                                @if ($this->canEdit || $task->assignee_id || $task->collaborators->isNotEmpty() || $task->due_date || $progress || $task->comments_count || $task->attachments_count)
                                    <div class="mt-3 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        <x-assignee-picker :task="$task" :editable="$this->canEdit" />
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

                        {{-- At the end of the cards, like the list: it comes into sight (and loads the next cards) only when the column is
                             scrolled down to it --}}
                        @if ($this->columnTotals[$status->id] > $this->columns[$status->id]->count())
                            <div class="px-2 py-2" wire:key="more-{{ $status->id }}" wire:intersect="loadMoreInColumn({{ $status->id }})">
                                <flux:button size="sm" variant="ghost" class="w-full" wire:click="loadMoreInColumn({{ $status->id }})">
                                    {{ __('Load more (:shown of :total)', ['shown' => $this->columns[$status->id]->count(), 'total' => $this->columnTotals[$status->id]]) }}
                                </flux:button>
                            </div>
                        @endif
                    </flux:kanban.column.cards>

                    @if ($this->canEdit && ! $selecting)
                        <x-quick-add :status-id="$status->id" class="mt-1 px-2 pb-2" x-show="! $wire.selecting" />
                    @endif
                </flux:kanban.column>
            @endforeach
        </flux:kanban>
    </div>

    {{-- The bar is always there for who may edit, shown as soon as the browser selects; the dialog needs what the server knows --}}
    @if ($this->canEdit)
        <x-selection-bar x-show="$wire.selecting" x-cloak />
    @endif

    @if ($selecting && $this->canEdit)
        <x-bulk-edit-modal :choices="$this->bulkChoices" />
    @endif

    @if ($this->canEdit)
        <x-assignee-menu :users="$this->assignableUsers" />
    @endif

</div>
