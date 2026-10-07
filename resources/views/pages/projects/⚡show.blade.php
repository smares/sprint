<?php

use App\Concerns\EditsTasksInBulk;
use App\Concerns\OpensTaskPanel;
use App\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\Task;
use App\Models\TaskStatus;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use EditsTasksInBulk;
    use OpensTaskPanel;

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

    /** @var array<int|string, string> */
    #[Url(as: 'f')]
    public array $fieldFilters = [];

    #[Url(as: 'sort')]
    public string $sortBy = '';

    #[Url(as: 'dir')]
    public string $sortDirection = 'asc';

    public string $title = '';

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

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage', $this->project);
    }

    public string $description = '';

    public string $assigneeId = '';

    public string $dueDate = '';

    public string $startDate = '';

    /**
     * Top-level tasks matching the filters, without order and limit.
     */
    protected function filteredTasks()
    {
        return $this->project->tasks()
            ->whereNull('parent_id')
            ->when($this->statusFilter === 'open', fn ($q) => $q->whereHas('status', fn ($status) => $status->where('is_done', false)))
            ->when(ctype_digit($this->statusFilter), fn ($q) => $q->where('status_id', (int) $this->statusFilter))
            ->when($this->assigneeFilter === 'me', fn ($q) => $q->where('assignee_id', auth()->id()))
            ->when(ctype_digit($this->assigneeFilter), fn ($q) => $q->where('assignee_id', (int) $this->assigneeFilter))
            ->when(ctype_digit($this->tagFilter), fn ($q) => $q->whereHas('tags', fn ($tags) => $tags->whereKey((int) $this->tagFilter)))
            ->tap(fn ($q) => $this->applyFieldFilters($q));
    }

    /**
     * The first page of the list; more is loaded when the end of the list comes into view.
     */
    #[Computed]
    public function tasks()
    {
        return $this->filteredTasks()
            ->with(['assignee', 'collaborators', 'tags', 'status', 'blockers.status', 'fieldValues'])
            ->tap(fn ($q) => $this->applyFieldSort($q))
            ->when($this->sortBy === 'due', fn ($q) => $q->orderByRaw('due_date is null')->orderBy('due_date', $this->sortDirection))
            ->when($this->sortBy === 'title', fn ($q) => $q->orderBy('title', $this->sortDirection))
            ->when($this->sortBy === 'status', fn ($q) => $q->orderBy(TaskStatus::select('position')->whereColumn('task_statuses.id', 'tasks.status_id'), $this->sortDirection))
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

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    /**
     * Any change to what is shown starts again at the first page.
     */
    public function updated(string $name): void
    {
        if (in_array(explode('.', $name)[0], ['statusFilter', 'assigneeFilter', 'tagFilter', 'fieldFilters', 'sortBy', 'sortDirection'], true)) {
            $this->limit = self::PAGE_SIZE;
        }

        if (in_array(explode('.', $name)[0], ['statusFilter', 'assigneeFilter', 'tagFilter', 'fieldFilters'], true)) {
            $this->selected = [];
        }
    }

    #[Computed]
    public function statuses()
    {
        return $this->project->statuses()->get();
    }

    /**
     * Fields shown as columns in the list.
     */
    #[Computed]
    public function listFields()
    {
        return $this->customFields->where('show_in_list', true)->values();
    }

    #[Computed]
    public function customFields()
    {
        return $this->project->customFields()->with('options')->get();
    }

    /**
     * Fields people can filter by: the single-choice ones.
     */
    #[Computed]
    public function filterableFields()
    {
        return $this->customFields->where('type', CustomFieldType::Select)->values();
    }

    private function applyFieldFilters($query): void
    {
        foreach ($this->filterableFields as $field) {
            $option = $this->fieldFilters[$field->id] ?? '';

            if (ctype_digit((string) $option) && $field->options->contains('id', (int) $option)) {
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
            ->orderBy($value, $this->sortDirection);
    }

    #[Computed]
    public function tagOptions()
    {
        return $this->project->tags()->orderBy('name')->get();
    }

    /**
     * @return array<int, array{done: int, total: int}>
     */
    #[Computed]
    public function progress(): array
    {
        return $this->project->subtaskProgress();
    }

    #[Computed]
    public function users()
    {
        return $this->project->eligibleUsers()->orderBy('name')->get(['id', 'name']);
    }

    public function createTask(): void
    {
        Gate::authorize('edit', $this->project);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assigneeId' => ['nullable', 'exists:users,id'],
            'dueDate' => ['nullable', 'date'],
            'startDate' => ['nullable', 'date', 'before_or_equal:dueDate'],
        ]);

        $this->project->tasks()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'assignee_id' => $validated['assigneeId'] ?: null,
            'due_date' => $validated['dueDate'] ?: null,
            'start_date' => $validated['startDate'] ?: null,
            'creator_id' => auth()->id(),
            'position' => $this->project->nextRootPosition(),
        ]);

        $this->reset('title', 'description', 'assigneeId', 'dueDate', 'startDate');
        unset($this->tasks);
        Flux::modal('create-task')->close();
    }

    /**
     * The filters that differ from the default, as removable chips.
     *
     * @return \Illuminate\Support\Collection<int, array{key: string, label: string}>
     */
    #[Computed]
    public function activeFilters()
    {
        $filters = collect();

        if ($this->assigneeFilter === 'me') {
            $filters->push(['key' => 'assignee', 'label' => 'Nur meine']);
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

        return $filters;
    }

    #[Computed]
    public function statusFilterLabel(): string
    {
        return match (true) {
            $this->statusFilter === 'open' => 'Offene',
            $this->statusFilter === 'all' => 'Alle Status',
            default => $this->statuses->firstWhere('id', (int) $this->statusFilter)?->name ?? 'Offene',
        };
    }

    public function clearFilter(string $key): void
    {
        $this->selected = [];

        match (true) {
            $key === 'assignee' => $this->assigneeFilter = '',
            $key === 'tag' => $this->tagFilter = '',
            str_starts_with($key, 'field:') => $this->fieldFilters[(int) substr($key, 6)] = '',
            default => null,
        };
    }

    public string $saveName = '';

    public bool $saveShared = false;

    /**
     * Saved views: the shared ones and the person's own.
     */
    #[Computed]
    public function savedFilters()
    {
        return $this->project->savedFilters()->visibleTo(auth()->user())->orderBy('name')->get();
    }

    /**
     * @return array{status: string, assignee: string, tag: string, fields: array<int|string, string>, sort: string, direction: string}
     */
    private function currentFilters(): array
    {
        return [
            'status' => $this->statusFilter,
            'assignee' => $this->assigneeFilter,
            'tag' => $this->tagFilter,
            'fields' => array_filter($this->fieldFilters, fn ($option) => $option !== '' && $option !== null),
            'sort' => $this->sortBy,
            'direction' => $this->sortDirection,
        ];
    }

    public function saveCurrentFilter(): void
    {
        $validated = $this->validate(['saveName' => ['required', 'string', 'max:80']], attributes: ['saveName' => 'Name']);

        $shared = $this->saveShared && Gate::allows('manage', $this->project);

        $this->project->savedFilters()->updateOrCreate(
            ['user_id' => $shared ? null : auth()->id(), 'name' => trim($validated['saveName'])],
            ['filters' => $this->currentFilters()],
        );

        $this->reset('saveName', 'saveShared');
        unset($this->savedFilters);
        Flux::toast(variant: 'success', text: 'Ansicht gespeichert.');
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
        $saved = $this->project->savedFilters()->visibleTo(auth()->user())->findOrFail($filterId);

        abort_unless(! $saved->isShared() || Gate::allows('manage', $this->project), 403);

        $saved->delete();
        unset($this->savedFilters);
    }

    public function resetFilters(): void
    {
        $this->selected = [];
        $this->statusFilter = 'open';
        $this->assigneeFilter = '';
        $this->tagFilter = '';
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

        $task = $this->project->tasks()->whereNull('parent_id')->findOrFail($taskId);

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

        $task = $this->project->tasks()->whereNull('parent_id')->findOrFail($taskId);

        $task->toggleDone();

        unset($this->tasks);
    }

    public function rendering($view): void
    {
        $view->title($this->project->name);
    }
};
?>

<div @class(['lg:pe-[39rem]' => $this->panelTask, 'pb-28' => $selecting])>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @if ($project->archived_at)
        <flux:callout class="mb-4" icon="archive-box" heading="Archiviert" text="Dieses Projekt ist archiviert und nur noch lesbar." />
    @endif

    <div @class(['mb-6 flex flex-col gap-4', 'lg:flex-row lg:items-center lg:justify-between' => ! $this->panelTask])>
        <div>
            <flux:heading size="xl">{{ $project->name }}</flux:heading>
            @if ($project->description)
                <flux:text class="mt-1">{{ $project->description }}</flux:text>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-project-views :project="$project" active="list" />

            @if ($this->canEdit)
                <flux:modal.trigger name="create-task">
                    <flux:button variant="primary" icon="plus">Neue Aufgabe</flux:button>
                </flux:modal.trigger>
            @endif

            @if ($this->canManage)
                <x-project-menu :project="$project" />
            @endif
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <flux:modal.trigger name="filters">
            <flux:button icon="funnel">
                Filter
                @if ($this->activeFilters->isNotEmpty())
                    <flux:badge size="sm" color="blue" inset="top bottom">{{ $this->activeFilters->count() }}</flux:badge>
                @endif
            </flux:button>
        </flux:modal.trigger>

        <flux:modal.trigger name="saved-filters">
            <flux:button icon="bookmark">Ansichten @if ($this->savedFilters->isNotEmpty()) <flux:badge size="sm" inset="top bottom">{{ $this->savedFilters->count() }}</flux:badge> @endif</flux:button>
        </flux:modal.trigger>

        @if ($this->canEdit && ! $selecting && $this->tasks->isNotEmpty())
            <flux:button icon="check-circle" wire:click="startSelecting">Auswählen</flux:button>
        @endif

        <flux:badge size="sm" color="zinc">{{ $this->statusFilterLabel }}</flux:badge>

        @foreach ($this->activeFilters as $filter)
            <flux:badge wire:key="active-{{ $filter['key'] }}" size="sm" color="blue" as="button" type="button" wire:click="clearFilter('{{ $filter['key'] }}')" title="Filter entfernen">
                {{ $filter['label'] }}
                <flux:icon.x-mark variant="micro" class="ms-1" />
            </flux:badge>
        @endforeach

        @if ($this->activeFilters->isNotEmpty())
            <flux:button size="sm" variant="ghost" wire:click="resetFilters">Zurücksetzen</flux:button>
        @endif
    </div>

    <flux:modal name="saved-filters" class="w-full max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Gespeicherte Ansichten</flux:heading>
                <flux:text class="mt-1">Eine Ansicht merkt sich Filter und Sortierung der Liste.</flux:text>
            </div>

            @forelse ($this->savedFilters as $saved)
                <div wire:key="saved-{{ $saved->id }}" class="flex items-center gap-2">
                    <flux:button variant="subtle" class="min-w-0 flex-1 justify-start" wire:click="applyFilter({{ $saved->id }})">
                        <span class="truncate">{{ $saved->name }}</span>
                    </flux:button>
                    @if ($saved->isShared())
                        <flux:badge size="sm" color="blue">Geteilt</flux:badge>
                    @endif
                    @if (! $saved->isShared() || $this->canManage)
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteFilter({{ $saved->id }})" wire:confirm="Ansicht „{{ $saved->name }}“ löschen?" aria-label="Ansicht löschen" />
                    @endif
                </div>
            @empty
                <flux:text>Noch keine Ansichten gespeichert.</flux:text>
            @endforelse

            <flux:separator />

            <form wire:submit="saveCurrentFilter" class="space-y-3">
                <flux:input wire:model="saveName" label="Aktuelle Filter speichern als" placeholder="z. B. Meine offenen Aufgaben" />
                @if ($this->canManage)
                    <flux:checkbox wire:model="saveShared" label="Für alle im Projekt sichtbar" />
                @endif
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" icon="bookmark">Speichern</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="filters" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">Filter</flux:heading>

            <flux:select variant="listbox" wire:model.live="statusFilter" label="Status">
                <flux:select.option value="open">Offen</flux:select.option>
                <flux:select.option value="all">Alle</flux:select.option>
                @foreach ($this->statuses as $status)
                    <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select variant="listbox" wire:model.live="assigneeFilter" label="Person">
                <flux:select.option value="">Alle Personen</flux:select.option>
                <flux:select.option value="me">Nur meine</flux:select.option>
                @foreach ($this->users as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @foreach ($this->filterableFields as $field)
                <flux:select wire:key="filter-{{ $field->id }}" variant="listbox" wire:model.live="fieldFilters.{{ $field->id }}" label="{{ $field->name }}">
                    <flux:select.option value="">Alle</flux:select.option>
                    @foreach ($field->options as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endforeach

            @if ($this->tagOptions->isNotEmpty())
                <flux:select variant="listbox" wire:model.live="tagFilter" label="Tag">
                    <flux:select.option value="">Alle Tags</flux:select.option>
                    @foreach ($this->tagOptions as $tag)
                        <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="resetFilters">Zurücksetzen</flux:button>
                <flux:spacer />
                <flux:modal.close><flux:button variant="primary">Fertig</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    @if ($this->tasks->isEmpty())
        <flux:callout icon="check-circle" heading="Keine Aufgaben" text="Mit diesen Filtern gibt es hier nichts zu tun." />
    @else
        <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-10">
                    @if ($selecting)
                        <flux:checkbox :checked="$this->tasks->isNotEmpty() && $this->tasks->pluck('id')->diff($this->selectedIds)->isEmpty()" wire:click="togglePage" aria-label="Alle sichtbaren Aufgaben auswählen" />
                    @endif
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'title'" :direction="$sortDirection" wire:click="sort('title')">Aufgabe</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">Status</flux:table.column>
                <flux:table.column class="{{ $this->panelTask ? 'hidden' : 'max-md:hidden' }}">Zuständig</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'due'" :direction="$sortDirection" wire:click="sort('due')">Fällig</flux:table.column>
                @foreach ($this->listFields as $field)
                    <flux:table.column wire:key="column-{{ $field->id }}" class="{{ $this->panelTask ? 'hidden' : 'max-md:hidden' }}" sortable :sorted="$sortBy === 'field:'.$field->id" :direction="$sortDirection" wire:click="sort('field:{{ $field->id }}')">{{ $field->name }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows :wire:sort="$sortBy === '' && $this->canEdit && ! $selecting ? 'moveTask' : null">
                @foreach ($this->tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}" :wire:sort:item="$sortBy === '' && $this->canEdit && ! $selecting ? $task->id : null">
                        <flux:table.cell>
                            @if ($selecting)
                                <flux:checkbox
                                    :checked="in_array((string) $task->id, array_map('strval', $selected), true)"
                                    x-on:click="$wire.selected = $wire.selected.includes('{{ $task->id }}') ? $wire.selected.filter((id) => id !== '{{ $task->id }}') : [...$wire.selected, '{{ $task->id }}']"
                                    aria-label="Aufgabe auswählen"
                                />
                            @else
                                <flux:checkbox :checked="$task->isDone()" :disabled="! $this->canEdit" wire:click="toggleDone({{ $task->id }})" />
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="min-w-44 whitespace-normal">
                            <span class="me-1.5 inline-block min-w-4 select-none text-end align-baseline text-xs tabular-nums text-zinc-300 dark:text-zinc-600" data-row-number="{{ $loop->iteration }}" title="Zeile {{ $loop->iteration }}">{{ $loop->iteration }}</span><a href="{{ route('tasks.show', $task) }}" x-on:click="if ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.button !== 0) return; $event.preventDefault(); $wire.openTask({{ $task->id }})" @class(['font-medium hover:underline', 'text-blue-600 dark:text-blue-400' => (string) $task->id === $openTaskId])>{{ $task->title }}</a>
                            @if ($task->isBlocked())
                                <flux:icon.lock-closed variant="micro" class="ms-1 inline text-amber-500" title="Blockiert" />
                            @endif
                            @if ($task->isRecurring())
                                <flux:icon.arrow-path variant="micro" class="ms-1 inline text-zinc-400" title="Wiederholt sich {{ $task->recurrenceLabel() }}" />
                            @endif
                            @if ($progress = $this->progress[$task->id] ?? null)
                                <flux:badge size="sm" icon="list-bullet" class="ms-1">{{ $progress['done'] }}/{{ $progress['total'] }}</flux:badge>
                            @endif
                            @foreach ($task->tags as $tag)
                                <x-color-badge size="sm" :color="$tag->color" class="ms-1">{{ $tag->name }}</x-color-badge>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-color-badge size="sm" :color="$task->status->color">{{ $task->status->name }}</x-color-badge>
                        </flux:table.cell>
                        <flux:table.cell class="{{ $this->panelTask ? 'hidden' : 'max-md:hidden' }}">
                            {{ $task->assignee?->name ?? '–' }}
                            @if ($task->collaborators->isNotEmpty())
                                <flux:text size="sm" class="block" title="{{ $task->collaborators->pluck('name')->join(', ') }}">+ {{ $task->collaborators->count() }} {{ $task->collaborators->count() === 1 ? 'Beteiligte:r' : 'Beteiligte' }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($task->due_date)
                                <flux:text :class="$task->isOverdue() ? 'text-red-500' : ''">{{ $task->due_date->format('d.m.Y') }}</flux:text>
                            @else
                                –
                            @endif
                        </flux:table.cell>
                        @foreach ($this->listFields as $field)
                            <flux:table.cell wire:key="cell-{{ $task->id }}-{{ $field->id }}" class="{{ $this->panelTask ? 'hidden' : 'max-md:hidden' }}">
                                <x-field-value :task="$task" :field="$field" />
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        </div>

        @if ($this->totalTasks > $this->tasks->count())
            <div wire:intersect="loadMore" class="mt-4 flex items-center justify-center gap-3">
                <flux:text size="sm">{{ $this->tasks->count() }} von {{ $this->totalTasks }} Aufgaben</flux:text>
                <flux:button size="sm" wire:click="loadMore">Mehr laden</flux:button>
            </div>
        @endif
    @endif

    @if ($selecting)
        <div class="pointer-events-none fixed inset-x-0 bottom-4 z-30 flex justify-center px-4">
            <div class="pointer-events-auto flex w-full max-w-xl flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-2 shadow-lg sm:w-auto sm:max-w-full sm:flex-row sm:items-center dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:text class="px-2 font-medium"><span x-text="$wire.selected.length">0</span> ausgewählt</flux:text>

                    @if ($this->totalTasks > $this->tasks->count())
                        <flux:button size="sm" variant="ghost" wire:click="selectAllMatching">Alle {{ min($this->totalTasks, 500) }} auswählen</flux:button>
                    @endif

                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="stopSelecting" aria-label="Auswahl beenden" class="ms-auto sm:hidden" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="sm" icon="check" wire:click="bulkComplete" x-bind:disabled="$wire.selected.length === 0">Erledigen</flux:button>

                    <flux:modal.trigger name="bulk-edit">
                        <flux:button size="sm" icon="pencil-square" x-bind:disabled="$wire.selected.length === 0">Ändern</flux:button>
                    </flux:modal.trigger>

                    <flux:button size="sm" variant="danger" icon="trash" wire:click="bulkDelete" wire:confirm="Die ausgewählten Aufgaben samt Unteraufgaben endgültig löschen?" x-bind:disabled="$wire.selected.length === 0">Löschen</flux:button>

                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="stopSelecting" aria-label="Auswahl beenden" class="max-sm:hidden" />
                </div>
            </div>
        </div>

        <flux:modal name="bulk-edit" class="w-full max-w-md">
            <form wire:submit="applyBulkChanges" class="space-y-5">
                <div>
                    <flux:heading size="lg"><span x-text="$wire.selected.length">0</span> Aufgaben ändern</flux:heading>
                    <flux:text class="mt-1">Nur was du ausfüllst, wird geändert; alles andere bleibt, wie es ist.</flux:text>
                </div>

                <flux:select variant="listbox" wire:model="bulkStatus" label="Status">
                    <flux:select.option value="">Nicht ändern</flux:select.option>
                    @foreach ($this->statuses as $status)
                        <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="bulkStatus" />

                <flux:select variant="listbox" wire:model="bulkAssignee" label="Zuständig">
                    <flux:select.option value="">Nicht ändern</flux:select.option>
                    <flux:select.option value="none">Niemand</flux:select.option>
                    @foreach ($this->users as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="space-y-2">
                    <flux:date-picker wire:model="bulkDueDate" label="Fällig am" locale="de-DE" placeholder="Nicht ändern" clearable description="Liegt der Beginn einer Aufgabe danach, rückt er auf dieses Datum." />
                    <flux:checkbox wire:model="bulkClearDueDate" label="Fälligkeit entfernen" />
                </div>

                @if ($this->tagOptions->isNotEmpty())
                    <flux:pillbox wire:model="bulkAddTags" multiple label="Tags hinzufügen" placeholder="Tags wählen …">
                        @foreach ($this->tagOptions as $tag)
                            <flux:pillbox.option wire:key="add-tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                        @endforeach
                    </flux:pillbox>

                    <flux:pillbox wire:model="bulkRemoveTags" multiple label="Tags entfernen" placeholder="Tags wählen …">
                        @foreach ($this->tagOptions as $tag)
                            <flux:pillbox.option wire:key="remove-tag-{{ $tag->id }}" value="{{ $tag->id }}">{{ $tag->name }}</flux:pillbox.option>
                        @endforeach
                    </flux:pillbox>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Abbrechen</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Übernehmen</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->canEdit)
        <flux:modal name="create-task" class="md:w-[28rem]">
            <form wire:submit="createTask" class="space-y-6">
                <flux:heading size="lg">Neue Aufgabe</flux:heading>
                <flux:input wire:model="title" label="Titel" autofocus />
                <flux:textarea wire:model="description" label="Beschreibung" rows="3" />
                <flux:select variant="listbox" wire:model="assigneeId" label="Zuständig">
                    <flux:select.option value="">Niemand</flux:select.option>
                    @foreach ($this->users as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid grid-cols-2 gap-4">
                    <flux:date-picker wire:model="startDate" label="Beginnt am" locale="de-DE" placeholder="Datum wählen" clearable />
                    <flux:date-picker wire:model="dueDate" label="Fällig am" locale="de-DE" placeholder="Datum wählen" clearable />
                </div>
                <div class="flex">
                    <flux:spacer />
                    <flux:button type="submit" variant="primary">Anlegen</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <x-task-panel :task="$this->panelTask" />
</div>
