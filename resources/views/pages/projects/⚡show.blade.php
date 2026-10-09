<?php

use App\Concerns\EditsTasksInBulk;
use App\Concerns\OpensTaskPanel;
use App\Concerns\QuickAddsTasks;
use App\Concerns\ShowsProject;
use App\Enums\CustomFieldType;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use ShowsProject;
    use EditsTasksInBulk;
    use OpensTaskPanel;
    use QuickAddsTasks;

    private const PAGE_SIZE = 50;

    public Project $project;

    #[Url(as: 'status')]
    public string $statusFilter = 'open';

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    #[Url(as: 'assignee')]
    public string $assigneeFilter = '';

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    /** '' for all tasks, 'none' for tasks without start and due date, 'dated' for tasks with one of them. */
    #[Url(as: 'dates')]
    public string $dateFilter = '';

    /** @var array<int|string, string> */
    #[Url(as: 'f')]
    public array $fieldFilters = [];

    #[Url(as: 'sort')]
    public string $sortBy = '';

    #[Url(as: 'dir')]
    public string $sortDirection = 'asc';

    public string $saveName = '';

    public bool $saveShared = false;

    /**
     * Statuses and tags are edited in modals; the list re-renders itself, and a filter on a deleted tag is dropped.
     */
    #[On('statuses-changed')]
    #[On('tags-changed')]
    public function settingsChanged(): void
    {
        if ($this->tagFilter !== '' && ! $this->project->tags()->whereKey($this->tagFilter)->exists()) {
            $this->tagFilter = '';
        }

        unset($this->statuses, $this->tagOptions);
    }

    /**
     * Tasks were imported from a CSV file in the import window.
     */
    #[On('tasks-imported')]
    #[On('task-created')]
    public function tasksImported(): void
    {
        unset($this->tasks, $this->totalTasks, $this->progress, $this->tagOptions);
    }

    /**
     * Top-level tasks matching the filters, without order and limit.
     */
    protected function filteredTasks(): HasMany
    {
        return $this->project->tasks()
            ->topLevel()
            ->when($this->statusFilter === 'open', fn ($q) => $q->open())
            ->when(ctype_digit($this->statusFilter), fn ($q) => $q->where('status_id', (int) $this->statusFilter))
            ->when($this->assigneeFilter === 'me', fn ($q) => $q->where('assignee_id', auth()->id()))
            ->when(ctype_digit($this->assigneeFilter), fn ($q) => $q->where('assignee_id', (int) $this->assigneeFilter))
            ->when(ctype_digit($this->tagFilter), fn ($q) => $q->whereHas('tags', fn ($tags) => $tags->whereKey((int) $this->tagFilter)))
            ->when($this->dateFilter === 'none', fn ($q) => $q->whereNull('start_date')->whereNull('due_date'))
            ->when($this->dateFilter === 'dated', fn ($q) => $q->where(fn ($dated) => $dated->whereNotNull('start_date')->orWhereNotNull('due_date')))
            ->tap(fn ($q) => $this->applyFieldFilters($q));
    }

    /**
     * The sort direction from the address, limited to the two valid values.
     */
    protected function direction(): string
    {
        return $this->sortDirection === 'desc' ? 'desc' : 'asc';
    }

    /**
     * The first page of the list; more is loaded when the end of the list comes into view.
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->filteredTasks()
            ->with(['assignee', 'collaborators', 'tags', 'status', 'blockers.status', 'fieldValues'])
            ->tap(fn ($q) => $this->applyFieldSort($q))
            ->when($this->sortBy === 'due', fn ($q) => $q->orderByRaw('due_date is null')->orderBy('due_date', $this->direction()))
            ->when($this->sortBy === 'title', fn ($q) => $q->orderBy('title', $this->direction()))
            ->when($this->sortBy === 'status', fn ($q) => $q->orderBy(TaskStatus::select('position')->whereColumn('task_statuses.id', 'tasks.status_id'), $this->direction()))
            ->orderBy('position')
            ->orderBy('id')
            ->limit($this->limit)
            ->get();
    }

    #[Computed]
    public function totalTasks(): int
    {
        return $this->filteredTasks()->count();
    }

    /**
     * What the shown filters ask of a task, so that a new one does not vanish from the view.
     *
     * @return array{status_id?: int, assignee_id?: int, tag_id?: int}
     */
    protected function quickAddDefaults(): array
    {
        return array_filter([
            'status_id' => ctype_digit($this->statusFilter) ? (int) $this->statusFilter : null,
            'assignee_id' => $this->assigneeFilter === 'me' ? auth()->id() : (ctype_digit($this->assigneeFilter) ? (int) $this->assigneeFilter : null),
            'tag_id' => ctype_digit($this->tagFilter) ? (int) $this->tagFilter : null,
        ]);
    }

    /**
     * The new task ends the list; a list that was shown completely makes room for it, and a task
     * the filters hide (the date filter, say) is announced rather than lost.
     */
    protected function quickAdded(Task $task): void
    {
        $total = $this->totalTasks;

        if ($total > $this->limit && $total - 1 <= $this->limit) {
            $this->limit = $total;
        }

        unset($this->tasks, $this->totalTasks, $this->progress, $this->tagOptions);

        if (! $this->filteredTasks()->whereKey($task->id)->exists()) {
            Flux::toast(variant: 'success', text: __('Task created, but the current filters hide it.'));
        }
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    /**
     * Any change to what is shown starts again at the first page.
     */
    public function updated(string $name): void
    {
        if (in_array(explode('.', $name)[0], ['statusFilter', 'assigneeFilter', 'tagFilter', 'dateFilter', 'fieldFilters', 'sortBy', 'sortDirection'], true)) {
            $this->limit = self::PAGE_SIZE;
        }

        if (in_array(explode('.', $name)[0], ['statusFilter', 'assigneeFilter', 'tagFilter', 'dateFilter', 'fieldFilters'], true)) {
            $this->selected = [];
        }
    }

    #[Computed]
    public function statuses(): Collection
    {
        return $this->project->statuses()->get();
    }

    /**
     * Fields shown as columns in the list.
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
     * Fields people can filter by: the single-choice ones.
     */
    #[Computed]
    public function filterableFields(): Collection
    {
        return $this->customFields->where('type', CustomFieldType::Select)->values();
    }

    private function applyFieldFilters($query): void
    {
        foreach ($this->filterableFields as $field) {
            $option = $this->fieldFilters[$field->id] ?? '';

            if (is_scalar($option) && ctype_digit((string) $option) && $field->options->contains('id', (int) $option)) {
                $query->whereHas('fieldValues', fn ($values) => $values
                    ->where('custom_field_id', $field->id)
                    ->where('option_id', (int) $option));
            }
        }
    }

    private function applyFieldSort($query): void
    {
        if (! str_starts_with($this->sortBy, 'field:')) {
            return;
        }

        $field = $this->customFields->firstWhere('id', (int) substr($this->sortBy, 6));

        if ($field === null) {
            return;
        }

        $value = CustomFieldValue::query()
            ->where('custom_field_values.custom_field_id', $field->id)
            ->whereColumn('custom_field_values.task_id', 'tasks.id');

        match ($field->type) {
            CustomFieldType::Select => $value->join('custom_field_options as sort_options', 'sort_options.id', '=', 'custom_field_values.option_id')->select('sort_options.position'),
            CustomFieldType::Number => $value->select(DB::raw('cast(custom_field_values.value as '.match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => 'decimal(30, 10)',
                'pgsql' => 'double precision',
                default => 'real',
            }.')')),
            default => $value->select('custom_field_values.value'),
        };

        $query->orderByRaw("({$value->toSql()}) is null", $value->getBindings())
            ->orderBy($value, $this->direction());
    }

    #[Computed]
    public function tagOptions(): Collection
    {
        return $this->project->tags()->orderBy('name')->get();
    }

    /**
     * @return array<int, array{done: int, total: int}>
     */
    #[Computed]
    public function progress(): array
    {
        return $this->project->subtaskProgress($this->tasks->pluck('id')->all());
    }

    #[Computed]
    public function users(): Collection
    {
        return $this->project->eligibleUsers()->orderBy('name')->get(['id', 'name', 'absent_from', 'absent_until']);
    }

    /**
     * The filters that differ from the default, as removable chips.
     *
     * @return \Illuminate\Support\Collection<int, array{key: string, label: string}>
     */
    #[Computed]
    public function activeFilters(): SupportCollection
    {
        $filters = collect();

        if ($this->assigneeFilter === 'me') {
            $filters->push(['key' => 'assignee', 'label' => __('Only mine')]);
        } elseif (ctype_digit($this->assigneeFilter) && ($user = $this->users->firstWhere('id', (int) $this->assigneeFilter))) {
            $filters->push(['key' => 'assignee', 'label' => $user->name]);
        }

        foreach ($this->filterableFields as $field) {
            $option = $field->options->firstWhere('id', (int) ($this->fieldFilters[$field->id] ?? 0));

            if ($option !== null) {
                $filters->push(['key' => 'field:'.$field->id, 'label' => $field->name.': '.$option->name]);
            }
        }

        if (ctype_digit($this->tagFilter) && ($tag = $this->tagOptions->firstWhere('id', (int) $this->tagFilter))) {
            $filters->push(['key' => 'tag', 'label' => $tag->name]);
        }

        if (in_array($this->dateFilter, ['none', 'dated'], true)) {
            $filters->push(['key' => 'dates', 'label' => $this->dateFilter === 'none' ? __('Without date') : __('With date')]);
        }

        return $filters;
    }

    #[Computed]
    public function statusFilterLabel(): string
    {
        return match (true) {
            $this->statusFilter === 'open' => __('Open tasks'),
            $this->statusFilter === 'all' => __('All statuses'),
            default => $this->statuses->firstWhere('id', (int) $this->statusFilter)?->name ?? __('Open tasks'),
        };
    }

    public function clearFilter(string $key): void
    {
        $this->selected = [];

        match (true) {
            $key === 'assignee' => $this->assigneeFilter = '',
            $key === 'tag' => $this->tagFilter = '',
            $key === 'dates' => $this->dateFilter = '',
            str_starts_with($key, 'field:') => $this->fieldFilters[(int) substr($key, 6)] = '',
            default => null,
        };
    }

    /**
     * Saved views: the shared ones and the person's own.
     */
    #[Computed]
    public function savedFilters(): Collection
    {
        return $this->project->savedFilters()->visibleTo(auth()->user())->orderBy('name')->get()
            ->each(fn ($saved) => $saved->setRelation('project', $this->project));
    }

    /**
     * @return array{status: string, assignee: string, tag: string, dates: string, fields: array<int|string, string>, sort: string, direction: string}
     */
    private function currentFilters(): array
    {
        return [
            'status' => $this->statusFilter,
            'assignee' => $this->assigneeFilter,
            'tag' => $this->tagFilter,
            'dates' => $this->dateFilter,
            'fields' => array_filter($this->fieldFilters, fn ($option) => $option !== '' && $option !== null),
            'sort' => $this->sortBy,
            'direction' => $this->sortDirection,
        ];
    }

    public function saveCurrentFilter(): void
    {
        $validated = $this->validate(['saveName' => ['required', 'string', 'max:80']], attributes: ['saveName' => __('Name')]);

        $shared = $this->saveShared && Gate::allows('manage', $this->project);

        $this->project->savedFilters()->updateOrCreate(
            ['user_id' => $shared ? null : auth()->id(), 'name' => trim($validated['saveName'])],
            ['filters' => $this->currentFilters()],
        );

        $this->reset('saveName', 'saveShared');
        unset($this->savedFilters);
        Flux::toast(variant: 'success', text: __('View saved.'));
    }

    /**
     * Apply a saved view; entries that no longer exist (deleted tags, statuses, people) are skipped.
     */
    public function applyFilter(int $filterId): void
    {
        $saved = $this->project->savedFilters()->visibleTo(auth()->user())->findOrFail($filterId);
        $this->selected = [];
        $filters = $saved->filters;

        $status = (string) ($filters['status'] ?? 'open');
        $this->statusFilter = in_array($status, ['open', 'all'], true) || ($this->statuses->contains('id', (int) $status) && ctype_digit($status)) ? $status : 'open';

        $assignee = (string) ($filters['assignee'] ?? '');
        $this->assigneeFilter = $assignee === 'me' || ($assignee !== '' && ctype_digit($assignee) && $this->users->contains('id', (int) $assignee)) ? $assignee : '';

        $tag = (string) ($filters['tag'] ?? '');
        $this->tagFilter = $tag !== '' && ctype_digit($tag) && $this->tagOptions->contains('id', (int) $tag) ? $tag : '';
        $this->dateFilter = in_array($filters['dates'] ?? '', ['none', 'dated'], true) ? $filters['dates'] : '';

        $this->fieldFilters = [];

        foreach ((array) ($filters['fields'] ?? []) as $fieldId => $optionId) {
            $field = $this->filterableFields->firstWhere('id', (int) $fieldId);

            if ($field !== null && $field->options->contains('id', (int) $optionId)) {
                $this->fieldFilters[$field->id] = (string) $optionId;
            }
        }

        $sort = (string) ($filters['sort'] ?? '');
        $validSort = in_array($sort, ['due', 'title', 'status'], true) || (str_starts_with($sort, 'field:') && $this->customFields->contains('id', (int) substr($sort, 6)));
        $this->sortBy = $validSort ? $sort : '';
        $this->sortDirection = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $this->limit = self::PAGE_SIZE;
        unset($this->tasks, $this->totalTasks, $this->activeFilters);
        Flux::modal('saved-filters')->close();
    }

    public function deleteFilter(int $filterId): void
    {
        $saved = $this->project->savedFilters()->visibleTo(auth()->user())->findOrFail($filterId)->setRelation('project', $this->project);

        Gate::authorize('delete', $saved);

        $saved->delete();
        unset($this->savedFilters);
    }

    public function resetFilters(): void
    {
        $this->selected = [];
        $this->statusFilter = 'open';
        $this->assigneeFilter = '';
        $this->tagFilter = '';
        $this->dateFilter = '';
        $this->fieldFilters = [];
        $this->limit = self::PAGE_SIZE;
    }

    public function sort(string $column): void
    {
        $this->limit = self::PAGE_SIZE;

        $isField = str_starts_with($column, 'field:') && $this->customFields->contains('id', (int) substr($column, 6));

        if (! $isField && ! in_array($column, ['due', 'title', 'status'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            if ($this->sortDirection === 'asc') {
                $this->sortDirection = 'desc';
            } else {
                $this->reset('sortBy', 'sortDirection');
            }
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        unset($this->tasks);
    }

    public function moveTask(int|string $taskId, int $position): void
    {
        Gate::authorize('edit', $this->project);

        abort_unless($this->sortBy === '', 422);

        $task = $this->project->tasks()->topLevel()->findOrFail($taskId);

        $this->project->placeRootTask(
            $task,
            $this->tasks->pluck('id')->reject(fn ($id) => $id === $task->id)->values()->all(),
            $position,
        );

        unset($this->tasks);
    }

    public function toggleDone(int $taskId): void
    {
        Gate::authorize('edit', $this->project);

        $task = $this->project->tasks()->topLevel()->findOrFail($taskId);

        $task->toggleDone();

        unset($this->tasks);
    }

    public function rendering(View $view): void
    {
        $view->title($this->project->name);
    }
};
?>

<div @class(['pb-28' => $selecting])>
    <x-project-header :project="$project" active="list" :presence="$this->presenceChannel()" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <flux:modal.trigger name="filters">
            <flux:button icon="funnel">
                {{ __('Filter') }}
                @if ($this->activeFilters->isNotEmpty())
                    <flux:badge size="sm" color="blue" inset="top bottom">{{ $this->activeFilters->count() }}</flux:badge>
                @endif
            </flux:button>
        </flux:modal.trigger>

        <flux:modal.trigger name="saved-filters">
            <flux:button icon="bookmark">{{ __('Views') }} @if ($this->savedFilters->isNotEmpty()) <flux:badge size="sm" inset="top bottom">{{ $this->savedFilters->count() }}</flux:badge> @endif</flux:button>
        </flux:modal.trigger>

        <flux:badge size="sm" color="zinc">{{ $this->statusFilterLabel }}</flux:badge>

        @foreach ($this->activeFilters as $filter)
            <flux:badge wire:key="active-{{ $filter['key'] }}" size="sm" color="blue" as="button" type="button" wire:click="clearFilter('{{ $filter['key'] }}')" title="{{ __('Remove filter') }}">
                {{ $filter['label'] }}
                <flux:icon.x-mark variant="micro" class="ms-1" />
            </flux:badge>
        @endforeach

        @if ($this->activeFilters->isNotEmpty())
            <flux:button size="sm" variant="ghost" wire:click="resetFilters">{{ __('Reset') }}</flux:button>
        @endif

        <flux:dropdown align="end" class="ms-auto">
            <flux:button icon="ellipsis-horizontal" aria-label="{{ __('More actions') }}" tooltip="{{ __('More actions') }}" />

            <flux:menu>
                <flux:menu.item icon="arrow-down-tray" href="{{ route('projects.export', $project) }}">{{ __('Export as CSV') }}</flux:menu.item>
                <flux:menu.item icon="arrow-down-tray" href="{{ route('projects.export', [$project, 'delimiter' => 'semicolon']) }}">{{ __('Export as CSV for Excel') }}</flux:menu.item>
                @if ($this->canEdit)
                    <flux:menu.separator />
                    <flux:menu.item icon="arrow-up-tray" x-on:click="$flux.modal('project-import').show()">{{ __('Import CSV …') }}</flux:menu.item>
                @endif
            </flux:menu>
        </flux:dropdown>
    </div>

    @if ($this->canEdit)
        <livewire:project-import :project="$project" defer.bundle />
    @endif

    <flux:modal name="saved-filters" class="w-full max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Saved views') }}</flux:heading>
                <flux:text class="mt-1">{{ __('A view remembers the filters and sorting of the list.') }}</flux:text>
            </div>

            @forelse ($this->savedFilters as $saved)
                <div wire:key="saved-{{ $saved->id }}" class="flex items-center gap-2">
                    <flux:button variant="subtle" class="min-w-0 flex-1 justify-start" wire:click="applyFilter({{ $saved->id }})">
                        <span class="truncate">{{ $saved->name }}</span>
                    </flux:button>
                    @if ($saved->isShared())
                        <flux:badge size="sm" color="blue">{{ __('Shared') }}</flux:badge>
                    @endif
                    @can('delete', $saved)
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteFilter({{ $saved->id }})" wire:confirm="{{ __('Delete view “:name”?', ['name' => $saved->name]) }}" aria-label="{{ __('Delete view') }}" />
                    @endcan
                </div>
            @empty
                <flux:text>{{ __('No views saved yet.') }}</flux:text>
            @endforelse

            <flux:separator />

            <form wire:submit="saveCurrentFilter" class="space-y-3">
                <flux:input wire:model="saveName" :label="__('Save current filters as')" :placeholder="__('e.g. My open tasks')" />
                @if ($this->canManage)
                    <flux:checkbox wire:model="saveShared" :label="__('Visible to everyone in the project')" />
                @endif
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" icon="bookmark">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="filters" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Filter') }}</flux:heading>

            <flux:select variant="listbox" wire:model.live="statusFilter" :label="__('Status')">
                <flux:select.option value="open">{{ __('Open') }}</flux:select.option>
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                @foreach ($this->statuses as $status)
                    <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select variant="listbox" wire:model.live="assigneeFilter" :label="__('Person')">
                <flux:select.option value="">{{ __('All people') }}</flux:select.option>
                <flux:select.option value="me">{{ __('Only mine') }}</flux:select.option>
                @foreach ($this->users as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->labelledName() }}</flux:select.option>
                @endforeach
            </flux:select>

            @foreach ($this->filterableFields as $field)
                <flux:select wire:key="filter-{{ $field->id }}" variant="listbox" wire:model.live="fieldFilters.{{ $field->id }}" label="{{ $field->name }}">
                    <flux:select.option value="">{{ __('All') }}</flux:select.option>
                    @foreach ($field->options as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endforeach

            <flux:select variant="listbox" wire:model.live="dateFilter" :label="__('Dates')">
                <flux:select.option value="">{{ __('With and without date') }}</flux:select.option>
                <flux:select.option value="dated">{{ __('With date') }}</flux:select.option>
                <flux:select.option value="none">{{ __('Without date') }}</flux:select.option>
            </flux:select>

            @if ($this->tagOptions->isNotEmpty())
                <flux:select variant="listbox" wire:model.live="tagFilter" :label="__('Tag')">
                    <flux:select.option value="">{{ __('All tags') }}</flux:select.option>
                    @foreach ($this->tagOptions as $tag)
                        <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="resetFilters">{{ __('Reset') }}</flux:button>
                <flux:spacer />
                <flux:modal.close><flux:button variant="primary">{{ __('Finish') }}</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    @if ($this->tasks->isEmpty())
        <flux:callout icon="check-circle" :heading="__('No tasks')" :text="__('Nothing to do here with these filters.')" />
    @else
        <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                {{-- Done button first, then the selection checkbox: two things, so they get one column and some room between them --}}
                <flux:table.column class="w-20 max-sm:w-24">
                    <div class="flex items-center gap-3">
                        <span class="w-6 shrink-0 max-sm:w-10"><span class="sr-only">{{ __('Done') }}</span></span>
                        @if ($this->canEdit)
                            <flux:checkbox :checked="$selecting && $this->tasks->isNotEmpty() && $this->tasks->pluck('id')->diff($this->selectedIds)->isEmpty()" wire:click="togglePage" aria-label="{{ __('Select all visible tasks') }}" />
                        @endif
                    </div>
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'title'" :direction="$sortDirection" wire:click="sort('title')">{{ __('Task') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">{{ __('Status') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Assignee') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden" sortable :sorted="$sortBy === 'due'" :direction="$sortDirection" wire:click="sort('due')">{{ __('Due') }}</flux:table.column>
                @foreach ($this->listFields as $field)
                    <flux:table.column wire:key="column-{{ $field->id }}" class="max-md:hidden" sortable :sorted="$sortBy === 'field:'.$field->id" :direction="$sortDirection" wire:click="sort('field:{{ $field->id }}')">{{ $field->name }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows :wire:sort="$sortBy === '' && $this->canEdit && ! $selecting ? 'moveTask' : null" wire:sort:config="{ delay: 250, delayOnTouchOnly: true, touchStartThreshold: 12 }">
                @foreach ($this->tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}" data-task-id="{{ $task->id }}" :wire:sort:item="$sortBy === '' && $this->canEdit && ! $selecting ? $task->id : null">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="check-circle"
                                    icon:variant="{{ $task->isDone() ? 'solid' : 'outline' }}"
                                    :class="'shrink-0 max-sm:size-10! '.($task->isDone() ? 'text-green-600! dark:text-green-500!' : 'text-zinc-400! hover:text-green-600! dark:text-zinc-500! dark:hover:text-green-500!')"
                                    :disabled="! $this->canEdit"
                                    wire:click="toggleDone({{ $task->id }})"
                                    aria-label="{{ $task->isDone() ? __('Reopen task') : __('Mark as done') }}"
                                    tooltip="{{ $task->isDone() ? __('Reopen task') : __('Mark as done') }}"
                                />
                                @if ($this->canEdit)
                                    @if ($selecting)
                                        <flux:checkbox
                                            :checked="in_array((string) $task->id, array_map('strval', $selected), true)"
                                            x-on:click="$wire.selected = $wire.selected.includes('{{ $task->id }}') ? $wire.selected.filter((id) => id !== '{{ $task->id }}') : [...$wire.selected, '{{ $task->id }}']; if ($wire.selected.length === 0) { $wire.stopSelecting() }"
                                            aria-label="{{ __('Select task') }}"
                                        />
                                    @else
                                        <flux:checkbox :checked="false" wire:click="selectTask({{ $task->id }})" aria-label="{{ __('Select task') }}" />
                                    @endif
                                @endif
                            </div>
                        </flux:table.cell>
                        {{-- Badges are separated by plain spaces, not margins: a space at the start of a wrapped line disappears, so tags
                             that wrap begin flush with the title. --}}
                        <flux:table.cell class="min-w-44 whitespace-normal max-sm:min-w-0">
                            <span class="me-1.5 inline-block min-w-4 select-none max-sm:hidden text-end align-baseline text-xs tabular-nums text-zinc-300 dark:text-zinc-600" data-row-number="{{ $loop->iteration }}" title="{{ __('Row :number', ['number' => $loop->iteration]) }}">{{ $loop->iteration }}</span><x-task-title-link :task="$task" :open="(string) $task->id === $openTaskId" />
                            @if ($progress = $this->progress[$task->id] ?? null)
                                <flux:badge size="sm" icon="list-bullet">{{ $progress['done'] }}/{{ $progress['total'] }}</flux:badge>
                            @endif
                            @foreach ($task->tags as $tag)
                                <x-color-badge size="sm" :color="$tag->color">{{ $tag->name }}</x-color-badge>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-color-badge size="sm" :color="$task->status->color">{{ $task->status->name }}</x-color-badge>
                        </flux:table.cell>
                        <flux:table.cell class="max-md:hidden">
                            {{ $task->assignee?->labelledName() }}
                            @if ($task->collaborators->isNotEmpty())
                                <flux:text size="sm" class="block" title="{{ $task->collaborators->pluck('name')->join(', ') }}">{{ trans_choice('{1} + :count collaborator|[2,*] + :count collaborators', $task->collaborators->count()) }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">
                            @if ($task->due_date)
                                <flux:text :class="$task->isOverdue() ? 'text-red-500' : ''">{{ $task->due_date->isoFormat('L') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        @foreach ($this->listFields as $field)
                            <flux:table.cell wire:key="cell-{{ $task->id }}-{{ $field->id }}" class="max-md:hidden">
                                <x-field-value :task="$task" :field="$field" :show-empty="false" />
                            </flux:table.cell>
                        @endforeach
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

    @if ($this->canEdit && ! $selecting)
        <x-quick-add class="mt-2" />
    @endif

    @if ($selecting)
        <div class="pointer-events-none fixed inset-x-0 bottom-4 z-30 flex justify-center px-4">
            <div class="pointer-events-auto flex w-full max-w-xl flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-2 shadow-lg sm:w-auto sm:max-w-full sm:flex-row sm:items-center dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:text class="px-2 font-medium">{!! __(':count selected', ['count' => '<span x-text="$wire.selected.length">0</span>']) !!}</flux:text>

                    @if ($this->totalTasks > $this->tasks->count())
                        <flux:button size="sm" variant="ghost" wire:click="selectAllMatching">{{ __('Select all :count', ['count' => min($this->totalTasks, $this::MAX_SELECTION)]) }}</flux:button>
                    @endif

                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="stopSelecting" aria-label="{{ __('End selection') }}" tooltip="{{ __('End selection') }}" class="ms-auto sm:hidden" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="sm" icon="check" wire:click="bulkComplete" x-bind:disabled="$wire.selected.length === 0">{{ __('Mark as done') }}</flux:button>

                    <flux:modal.trigger name="bulk-edit">
                        <flux:button size="sm" icon="pencil-square" x-bind:disabled="$wire.selected.length === 0">{{ __('Change') }}</flux:button>
                    </flux:modal.trigger>

                    <flux:button size="sm" variant="danger" icon="trash" wire:click="bulkDelete" wire:confirm="{{ __('Permanently delete the selected tasks including their subtasks?') }}" x-bind:disabled="$wire.selected.length === 0">{{ __('Delete') }}</flux:button>

                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="stopSelecting" aria-label="{{ __('End selection') }}" tooltip="{{ __('End selection') }}" class="max-sm:hidden" />
                </div>
            </div>
        </div>

        <flux:modal name="bulk-edit" class="w-full max-w-md">
            <form wire:submit="applyBulkChanges" class="space-y-5">
                <div>
                    <flux:heading size="lg">{!! __('Change :count tasks', ['count' => '<span x-text="$wire.selected.length">0</span>']) !!}</flux:heading>
                    <flux:text class="mt-1">{{ __('Only what you fill in is changed; everything else stays as it is.') }}</flux:text>
                </div>

                <flux:select variant="listbox" wire:model="bulkStatus" :label="__('Status')">
                    <flux:select.option value="">{{ __('Leave unchanged') }}</flux:select.option>
                    @foreach ($this->statuses as $status)
                        <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="bulkStatus" />

                <flux:select variant="listbox" wire:model="bulkAssignee" :label="__('Assignee')">
                    <flux:select.option value="">{{ __('Leave unchanged') }}</flux:select.option>
                    <flux:select.option value="none">{{ __('Nobody') }}</flux:select.option>
                    @foreach ($this->users as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->labelledName() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="space-y-2">
                    <flux:date-picker wire:model="bulkDueDate" :label="__('Due on')" locale="{{ app()->getLocale() }}" :placeholder="__('Leave unchanged')" clearable :description="__('If a task starts after that date, its start moves to this date.')" />
                    <flux:checkbox wire:model="bulkClearDueDate" :label="__('Remove due date')" />
                </div>

                @if ($this->tagOptions->isNotEmpty())
                    <flux:pillbox wire:model="bulkAddTags" multiple :label="__('Add tags')" :placeholder="__('Select tags …')">
                        @foreach ($this->tagOptions as $tag)
                            <flux:pillbox.option wire:key="add-tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                        @endforeach
                    </flux:pillbox>

                    <flux:pillbox wire:model="bulkRemoveTags" multiple :label="__('Remove tags')" :placeholder="__('Select tags …')">
                        @foreach ($this->tagOptions as $tag)
                            <flux:pillbox.option wire:key="remove-tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                        @endforeach
                    </flux:pillbox>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->canEdit)
    @endif

    <x-task-panel :task="$this->panelTask" />
</div>
