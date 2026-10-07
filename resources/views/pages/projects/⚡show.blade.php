<?php

use App\Concerns\OpensTaskPanel;
use App\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use OpensTaskPanel;

    public Project $project;

    #[Url(as: 'status')]
    public string $statusFilter = 'open';

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

    #[Computed]
    public function tasks()
    {
        return $this->project->tasks()
            ->whereNull('parent_id')
            ->with(['assignee', 'collaborators', 'tags', 'status', 'blockers.status', 'fieldValues'])
            ->when($this->statusFilter === 'open', fn ($q) => $q->whereHas('status', fn ($status) => $status->where('is_done', false)))
            ->when(ctype_digit($this->statusFilter), fn ($q) => $q->where('status_id', (int) $this->statusFilter))
            ->when($this->assigneeFilter === 'me', fn ($q) => $q->where('assignee_id', auth()->id()))
            ->when(ctype_digit($this->assigneeFilter), fn ($q) => $q->where('assignee_id', (int) $this->assigneeFilter))
            ->when(ctype_digit($this->tagFilter), fn ($q) => $q->whereHas('tags', fn ($tags) => $tags->whereKey((int) $this->tagFilter)))
            ->tap(fn ($q) => $this->applyFieldFilters($q))
            ->tap(fn ($q) => $this->applyFieldSort($q))
            ->when($this->sortBy === 'due', fn ($q) => $q->orderByRaw('due_date is null')->orderBy('due_date', $this->sortDirection))
            ->when($this->sortBy === 'title', fn ($q) => $q->orderBy('title', $this->sortDirection))
            ->when($this->sortBy === 'status', fn ($q) => $q->orderBy(TaskStatus::select('position')->whereColumn('task_statuses.id', 'tasks.status_id'), $this->sortDirection))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
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
        match (true) {
            $key === 'assignee' => $this->assigneeFilter = '',
            $key === 'tag' => $this->tagFilter = '',
            str_starts_with($key, 'field:') => $this->fieldFilters[(int) substr($key, 6)] = '',
            default => null,
        };
    }

    public function resetFilters(): void
    {
        $this->statusFilter = 'open';
        $this->assigneeFilter = '';
        $this->tagFilter = '';
        $this->fieldFilters = [];
    }

    public function sort(string $column): void
    {
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

<div @class(['lg:pe-[39rem]' => $this->panelTask])>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

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
                <flux:table.column class="w-10"></flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'title'" :direction="$sortDirection" wire:click="sort('title')">Aufgabe</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">Status</flux:table.column>
                <flux:table.column class="{{ $this->panelTask ? 'hidden' : 'max-md:hidden' }}">Zuständig</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'due'" :direction="$sortDirection" wire:click="sort('due')">Fällig</flux:table.column>
                @foreach ($this->listFields as $field)
                    <flux:table.column wire:key="column-{{ $field->id }}" class="{{ $this->panelTask ? 'hidden' : 'max-md:hidden' }}" sortable :sorted="$sortBy === 'field:'.$field->id" :direction="$sortDirection" wire:click="sort('field:{{ $field->id }}')">{{ $field->name }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows :wire:sort="$sortBy === '' && $this->canEdit ? 'moveTask' : null">
                @foreach ($this->tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}" :wire:sort:item="$sortBy === '' && $this->canEdit ? $task->id : null">
                        <flux:table.cell>
                            <flux:checkbox :checked="$task->isDone()" :disabled="! $this->canEdit" wire:click="toggleDone({{ $task->id }})" />
                        </flux:table.cell>
                        <flux:table.cell class="min-w-44 whitespace-normal">
                            <a href="{{ route('tasks.show', $task) }}" x-on:click="if ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.button !== 0) return; $event.preventDefault(); $wire.openTask({{ $task->id }})" @class(['font-medium hover:underline', 'text-blue-600 dark:text-blue-400' => (string) $task->id === $openTaskId])>{{ $task->title }}</a>
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
