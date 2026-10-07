<?php

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

<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item href="{{ route('projects.index') }}" wire:navigate>Projekte</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <flux:heading size="xl">{{ $project->name }}</flux:heading>
            @if ($project->description)
                <flux:text class="mt-1">{{ $project->description }}</flux:text>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-project-views :project="$project" active="list" />

            @if ($this->canManage)
                <livewire:project-statuses :project="$project" />
                <livewire:project-tags :project="$project" />
                <flux:button icon="adjustments-horizontal" href="{{ route('projects.fields', $project) }}" wire:navigate>Felder</flux:button>
                <flux:button icon="users" href="{{ route('projects.members', $project) }}" wire:navigate>Mitglieder</flux:button>
            @endif

            @if ($this->canEdit)
                <flux:modal.trigger name="create-task">
                    <flux:button variant="primary" icon="plus">Neue Aufgabe</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:flex sm:flex-wrap">
        <flux:select variant="listbox" wire:model.live="statusFilter" class="max-w-40">
            <flux:select.option value="open">Offen</flux:select.option>
            <flux:select.option value="all">Alle</flux:select.option>
            @foreach ($this->statuses as $status)
                <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select variant="listbox" wire:model.live="assigneeFilter" class="max-w-48">
            <flux:select.option value="">Alle Personen</flux:select.option>
            <flux:select.option value="me">Nur meine</flux:select.option>
            @foreach ($this->users as $user)
                <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
            @endforeach
        </flux:select>

        @foreach ($this->filterableFields as $field)
            <flux:select wire:key="filter-{{ $field->id }}" variant="listbox" wire:model.live="fieldFilters.{{ $field->id }}" class="max-w-48">
                <flux:select.option value="">Alle: {{ $field->name }}</flux:select.option>
                @foreach ($field->options as $option)
                    <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endforeach

        @if ($this->tagOptions->isNotEmpty())
            <flux:select variant="listbox" wire:model.live="tagFilter" class="max-w-48">
                <flux:select.option value="">Alle Tags</flux:select.option>
                @foreach ($this->tagOptions as $tag)
                    <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($this->tasks->isEmpty())
        <flux:callout icon="check-circle" heading="Keine Aufgaben" text="Mit diesen Filtern gibt es hier nichts zu tun." />
    @else
        <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-10"></flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'title'" :direction="$sortDirection" wire:click="sort('title')">Aufgabe</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">Status</flux:table.column>
                <flux:table.column class="max-md:hidden">Zuständig</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'due'" :direction="$sortDirection" wire:click="sort('due')">Fällig</flux:table.column>
                @foreach ($this->listFields as $field)
                    <flux:table.column wire:key="column-{{ $field->id }}" class="max-md:hidden" sortable :sorted="$sortBy === 'field:'.$field->id" :direction="$sortDirection" wire:click="sort('field:{{ $field->id }}')">{{ $field->name }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows :wire:sort="$sortBy === '' && $this->canEdit ? 'moveTask' : null">
                @foreach ($this->tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}" :wire:sort:item="$sortBy === '' && $this->canEdit ? $task->id : null">
                        <flux:table.cell>
                            <flux:checkbox :checked="$task->isDone()" :disabled="! $this->canEdit" wire:click="toggleDone({{ $task->id }})" />
                        </flux:table.cell>
                        <flux:table.cell class="min-w-44 whitespace-normal">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="font-medium hover:underline">{{ $task->title }}</a>
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
                                <flux:badge size="sm" :color="$tag->color" class="ms-1">{{ $tag->name }}</flux:badge>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$task->status->color">{{ $task->status->name }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="max-md:hidden">
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
                            <flux:table.cell wire:key="cell-{{ $task->id }}-{{ $field->id }}" class="max-md:hidden">
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
</div>
