<?php

use App\Concerns\AssignsTasks;
use App\Concerns\EditsTasksInBulk;
use App\Concerns\ManagesSavedViews;
use App\Concerns\RefreshesOnTaskChanges;
use App\Concerns\QuickAddsTasks;
use App\Concerns\ShowsProject;
use App\Concerns\SortsTasks;
use App\Enums\CustomFieldType;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Services\DateService;
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
    use AssignsTasks;
    use EditsTasksInBulk;
    use RefreshesOnTaskChanges;
    use ManagesSavedViews;
    use QuickAddsTasks;
    use SortsTasks;

    private const int PAGE_SIZE = 50;

    /** The list's columns before the custom fields: check, task, status, assignee, due date. */
    public const int FIXED_COLUMNS = 5;

    public Project $project;

    #[Url(as: 'status')]
    public string $statusFilter = 'open';

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    #[Url(as: 'assignee')]
    public string $assigneeFilter = '';

    /** Tasks on which at least one of these people collaborates: user ids, or 'me'. @var list<string> */
    #[Url(as: 'with')]
    public array $collaboratorFilter = [];

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    /** '' for all tasks, 'none' for tasks without start and due date, 'dated' for tasks with one of them, 'overdue' and 'soon' (due this week) for open tasks by due date. */
    #[Url(as: 'dates')]
    public string $dateFilter = '';

    /** @var array<int|string, string> */
    #[Url(as: 'f')]
    public array $fieldFilters = [];

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
    public function tasksImported(): void
    {
        unset($this->tasks, $this->totalTasks, $this->progress, $this->tagOptions);
    }

    /**
     * A task was created in the dialog: the list shows it, or says that the filters hide it.
     */
    protected function announceCreatedTask(int $taskId): void
    {
        $task = $taskId > 0 ? $this->project->tasks()->withoutSections()->find($taskId) : null;

        if ($task === null) {
            $this->tasksImported();
            Flux::toast(variant: 'success', text: __('Task created.'));

            return;
        }

        Flux::toast(variant: 'success', text: $this->showAdded($task) ? __('Task created.') : __('Task created, but the current filters hide it.'));
    }

    /**
     * The box in the header of the list: all tasks shown, or none of them.
     */
    public function togglePage(): void
    {
        Gate::authorize('edit', $this->project);

        $this->selecting = true;

        $page = $this->tasks->pluck('id')->map(fn ($id) => (string) $id);
        $current = collect($this->selected)->map(fn ($id) => (string) $id);

        $this->selected = $page->diff($current)->isEmpty()
            ? $current->diff($page)->values()->all()
            : $current->concat($page)->unique()->values()->all();

        // Unticking the last ones ends the selection, as the bar has nothing left to act on
        if ($this->selected === []) {
            $this->stopSelecting();

            return;
        }

        unset($this->selectedIds);
    }

    public function selectAllMatching(): void
    {
        Gate::authorize('edit', $this->project);

        $this->selected = $this->filteredTasks()->limit(self::MAX_SELECTION)->pluck('id')->map(fn ($id) => (string) $id)->all();
        unset($this->selectedIds);

        if ($this->totalTasks > self::MAX_SELECTION) {
            Flux::toast(variant: 'warning', text: __('At most :count tasks are selected at once.', ['count' => self::MAX_SELECTION]));
        }
    }

    protected function forgetTasksAfterBulk(): void
    {
        unset($this->tasks, $this->totalTasks, $this->progress);
    }

    /**
     * Top-level tasks matching the filters, without order and limit.
     */
    protected function filteredTasks(): HasMany
    {
        return $this->project->tasks()
            ->topLevel()
            ->unless($this->showsHeadings(), fn ($q) => $q->withoutSections())
            ->when($this->statusFilter === 'open', fn ($q) => $q->open())
            ->when(ctype_digit($this->statusFilter), fn ($q) => $q->where('status_id', (int) $this->statusFilter))
            ->when($this->assigneeFilter === 'me', fn ($q) => $q->where('assignee_id', auth()->id()))
            ->when(ctype_digit($this->assigneeFilter), fn ($q) => $q->where('assignee_id', (int) $this->assigneeFilter))
            ->when($this->collaboratorFilterUsers !== [], fn ($q) => $q->whereHas('collaborators', fn ($people) => $people->whereKey(array_keys($this->collaboratorFilterUsers))))
            ->when(ctype_digit($this->tagFilter), fn ($q) => $q->whereHas('tags', fn ($tags) => $tags->whereKey((int) $this->tagFilter)))
            ->when($this->dateFilter === 'none', fn ($q) => $q->whereNull('start_date')->whereNull('due_date'))
            ->when($this->dateFilter === 'dated', fn ($q) => $q->where(fn ($dated) => $dated->whereNotNull('start_date')->orWhereNotNull('due_date')))
            ->when($this->dateFilter === 'overdue', fn ($q) => $q->overdue(DateService::today(auth()->user())))
            ->when($this->dateFilter === 'soon', fn ($q) => $q->dueSoon(DateService::today(auth()->user())))
            ->tap(fn ($q) => $this->applyFieldFilters($q));
    }

    /**
     * What a collaborator filter can hold: 'me' and the ids of people one can choose in this project. Anything else, from an old
     * link or a saved view of somebody who has left, is dropped, so that a filter nobody can see never hides tasks.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    protected function validCollaboratorValues(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => $value === 'me' || (is_scalar($value) && ctype_digit((string) $value) && $this->assignableUsers->contains('id', (int) $value)))
            ->map(fn ($value) => (string) $value)
            ->unique()->values()->all();
    }

    /**
     * The people of the collaborator filter by user id, with their names.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function collaboratorFilterUsers(): array
    {
        $users = [];

        foreach ($this->validCollaboratorValues($this->collaboratorFilter) as $value) {
            $user = $value === 'me' ? auth()->user() : $this->assignableUsers->firstWhere('id', (int) $value);
            $users[$user->id] = $user->name;
        }

        return $users;
    }

    /**
     * Headings (imported with `--sections=headings`) stand among the tasks in the plain list. A filter or a sort order
     * would only scatter them, as they have no status, person or date of their own, so those views leave them out.
     */
    protected function showsHeadings(): bool
    {
        return ! $this->sortActive && $this->activeFilters->isEmpty() && in_array($this->statusFilter, ['open', 'all'], true);
    }

    /**
     * The date filters besides "with and without date", value => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function dateFilterLabels(): array
    {
        return [
            'dated' => __('With date'),
            'none' => __('Without date'),
            'overdue' => __('Overdue'),
            'soon' => __('Due this week'),
        ];
    }

    /**
     * The first page of the list; more is loaded when the end of the list comes into view.
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->filteredTasks()
            ->with(['assignee', 'collaborators', 'tags', 'status', 'blockers.status', 'fieldValues'])
            ->tap(function ($q) {
                foreach ($this->sortTerms() as [$sql, $bindings]) {
                    $q->orderByRaw($sql, $bindings);
                }
            })
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
        if (! $this->showAdded($task)) {
            Flux::toast(variant: 'success', text: __('Task created, but the current filters hide it.'));
        }
    }

    /**
     * Makes room for the new task in the list (it counts as one more of the page) and tells whether the filters let it through.
     */
    private function showAdded(Task $task): bool
    {
        $total = $this->totalTasks;

        if ($total > $this->limit && $total - 1 <= $this->limit) {
            $this->limit = $total;
        }

        unset($this->tasks, $this->totalTasks, $this->progress, $this->tagOptions);

        return $this->filteredTasks()->whereKey($task->id)->exists();
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
        if (in_array(explode('.', $name)[0], ['statusFilter', 'assigneeFilter', 'collaboratorFilter', 'tagFilter', 'dateFilter', 'fieldFilters', 'sortBy', 'sortDirection'], true)) {
            $this->limit = self::PAGE_SIZE;
        }

        if (in_array(explode('.', $name)[0], ['statusFilter', 'assigneeFilter', 'collaboratorFilter', 'tagFilter', 'dateFilter', 'fieldFilters'], true)) {
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

    protected function afterAssigning(): void
    {
        unset($this->tasks);
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
            $filters->push(['key' => 'assignee', 'label' => __('Assigned to me')]);
        } elseif (ctype_digit($this->assigneeFilter) && ($user = $this->assignableUsers->firstWhere('id', (int) $this->assigneeFilter))) {
            $filters->push(['key' => 'assignee', 'label' => $user->name]);
        }

        if ($this->collaboratorFilterUsers !== []) {
            $filters->push(['key' => 'collaborators', 'label' => __('Collaborators').': '.implode(', ', $this->collaboratorFilterUsers)]);
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

        if (array_key_exists($this->dateFilter, $this->dateFilterLabels)) {
            $filters->push(['key' => 'dates', 'label' => $this->dateFilterLabels[$this->dateFilter]]);
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
            $key === 'collaborators' => $this->collaboratorFilter = [],
            $key === 'tag' => $this->tagFilter = '',
            $key === 'dates' => $this->dateFilter = '',
            str_starts_with($key, 'field:') => $this->fieldFilters[(int) substr($key, 6)] = '',
            default => null,
        };
    }

    protected function savedViewKind(): string
    {
        return SavedFilter::LIST;
    }

    /**
     * @return array<string, string>
     */
    protected function savedViewQueryProperties(): array
    {
        return [
            'status' => 'statusFilter', 'assignee' => 'assigneeFilter', 'with' => 'collaboratorFilter', 'tag' => 'tagFilter',
            'dates' => 'dateFilter', 'f' => 'fieldFilters', 'sort' => 'sortBy', 'dir' => 'sortDirection',
        ];
    }

    /**
     * @return array{status: string, assignee: string, collaborators: list<string>, tag: string, dates: string, fields: array<int|string, string>, sort: string, direction: string}
     */
    protected function currentViewSettings(): array
    {
        return [
            'status' => $this->statusFilter,
            'assignee' => $this->assigneeFilter,
            'collaborators' => $this->validCollaboratorValues($this->collaboratorFilter),
            'tag' => $this->tagFilter,
            'dates' => $this->dateFilter,
            'fields' => array_filter($this->fieldFilters, fn ($option) => is_scalar($option) && $option !== ''),
            'sort' => $this->isSorted() ? $this->sortBy : '',
            'direction' => $this->direction(),
        ];
    }

    /**
     * Entries that no longer exist (deleted tags, statuses, people) are skipped.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function applyViewSettings(array $settings): void
    {
        $this->selected = [];

        $status = (string) ($settings['status'] ?? 'open');
        $this->statusFilter = in_array($status, ['open', 'all'], true) || ($this->statuses->contains('id', (int) $status) && ctype_digit($status)) ? $status : 'open';

        $assignee = (string) ($settings['assignee'] ?? '');
        $this->assigneeFilter = $assignee === 'me' || ($assignee !== '' && ctype_digit($assignee) && $this->assignableUsers->contains('id', (int) $assignee)) ? $assignee : '';

        $this->collaboratorFilter = $this->validCollaboratorValues((array) ($settings['collaborators'] ?? []));

        $tag = (string) ($settings['tag'] ?? '');
        $this->tagFilter = $tag !== '' && ctype_digit($tag) && $this->tagOptions->contains('id', (int) $tag) ? $tag : '';
        $this->dateFilter = is_string($settings['dates'] ?? null) && array_key_exists($settings['dates'], $this->dateFilterLabels) ? $settings['dates'] : '';

        $this->fieldFilters = [];

        foreach ((array) ($settings['fields'] ?? []) as $fieldId => $optionId) {
            $field = $this->filterableFields->firstWhere('id', (int) $fieldId);

            if ($field !== null && is_scalar($optionId) && $field->options->contains('id', (int) $optionId)) {
                $this->fieldFilters[$field->id] = (string) $optionId;
            }
        }

        $this->applySavedSort($settings);

        $this->limit = self::PAGE_SIZE;
        unset($this->tasks, $this->totalTasks, $this->activeFilters, $this->collaboratorFilterUsers);
    }

    public function resetFilters(): void
    {
        $this->selected = [];
        $this->statusFilter = 'open';
        $this->assigneeFilter = '';
        $this->collaboratorFilter = [];
        $this->tagFilter = '';
        $this->dateFilter = '';
        $this->fieldFilters = [];
        $this->limit = self::PAGE_SIZE;
    }

    protected function sorted(): void
    {
        $this->limit = self::PAGE_SIZE;

        unset($this->tasks);
    }

    public function moveTask(int|string $taskId, int $position): void
    {
        Gate::authorize('edit', $this->project);

        abort_if($this->sortActive, 422);

        $task = $this->project->tasks()->topLevel()->findOrFail($taskId);

        $this->project->placeRootTask(
            $task,
            $this->tasks->pluck('id')->reject(fn ($id) => $id === $task->id)->values()->all(),
            $position,
        );

        unset($this->tasks);
    }

    public function rendering(View $view): void
    {
        $view->title($this->project->name);
    }
};
?>

<div @class(['pb-40' => $selecting]) x-bind:class="{ 'pb-40': $wire.selecting }" data-wide>
    <x-project-header :project="$project" active="list" :presence="$this->presenceChannel()" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <flux:modal.trigger name="filters">
            <flux:button icon="funnel">
                {{ __('Filter') }}
                @if ($this->activeFilters->isNotEmpty())
                    <flux:badge size="sm" color="orange" inset="top bottom">{{ $this->activeFilters->count() }}</flux:badge>
                @endif
            </flux:button>
        </flux:modal.trigger>

        <flux:modal.trigger name="saved-views">
            <flux:button icon="bookmark">{{ __('Views') }} @if ($this->savedViews->isNotEmpty()) <flux:badge size="sm" inset="top bottom">{{ $this->savedViews->count() }}</flux:badge> @endif</flux:button>
        </flux:modal.trigger>

        <flux:badge size="sm" color="zinc">{{ $this->statusFilterLabel }}</flux:badge>

        @foreach ($this->activeFilters as $filter)
            {{-- A long label (the names of many collaborators) wraps inside the chip; on one line it would widen the page on a phone --}}
            <flux:badge wire:key="active-{{ $filter['key'] }}" size="sm" color="orange" as="button" type="button" wire:click="clearFilter('{{ $filter['key'] }}')" title="{{ __('Remove filter') }}" class="max-w-full text-start">
                <span class="min-w-0 whitespace-normal [overflow-wrap:anywhere]">{{ $filter['label'] }}</span>
                <flux:icon.x-mark variant="micro" class="ms-1 shrink-0" />
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

    <x-saved-views-modal :views="$this->savedViews" :defaults="$this->savedViewDefaults" :can-manage="$this->canManage"
        :description="__('A view remembers the filters and sorting of the list.')" :save-label="__('Save current filters as')" :placeholder="__('e.g. My open tasks')" />

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
                <flux:select.option value="me">{{ __('Assigned to me') }}</flux:select.option>
                @foreach ($this->assignableUsers as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->labelledName() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:pillbox wire:model.live="collaboratorFilter" multiple :label="__('Collaborators')" :placeholder="__('All people')">
                <flux:pillbox.option value="me">{{ __('Me') }}</flux:pillbox.option>
                @foreach ($this->assignableUsers as $user)
                    <flux:pillbox.option wire:key="collaborator-filter-{{ $user->id }}" value="{{ $user->id }}">{{ $user->labelledName() }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>

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
                @foreach ($this->dateFilterLabels as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
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
        {{-- Why the list is empty decides what helps: a new project, filters that match nothing, or everything done --}}
        @if ($this->activeFilters->isEmpty() && $statusFilter === 'open' && ! $project->tasks()->exists())
            <x-empty-state icon="clipboard-document-list" :heading="__('No tasks yet')" :text="$this->canEdit ? __('Create the first task with “New task” or right below.') : null" />
        @elseif ($this->activeFilters->isEmpty() && $statusFilter === 'open')
            <x-empty-state emoji="🎉" :heading="__('All done')" :text="__('Every task in this project is done.')">
                <flux:button wire:click="$set('statusFilter', 'all')">{{ __('Show done tasks') }}</flux:button>
            </x-empty-state>
        @else
            <x-empty-state icon="funnel" :heading="__('No tasks')" :text="__('Nothing to do here with these filters.')">
                <flux:button wire:click="resetFilters">{{ __('Reset filters') }}</flux:button>
            </x-empty-state>
        @endif
    @else
        {{-- Every column shows on every screen and the table scrolls sideways. The title stays in place (from sm with the done column
             before it; on phones only the title, so it leaves room for the rest); sticky cells need a background to cover what passes underneath. --}}
        @php
            $pinnedDone = 'sm:sticky sm:start-0 sm:z-10 sm:bg-white sm:dark:bg-zinc-800';
            $pinnedTitle = 'pinned-column sm:start-20';
        @endphp
        <div class="surface px-3 [--flux-bleed:0.75rem]" data-fills-height>
            <flux:table bleed container:class="scroll-shadows rounded-[inherit]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    {{-- Done button first, then the selection checkbox: two things, so they get one column and some room between them --}}
                    <flux:table.column :class="'w-20 min-w-20 max-sm:w-24 max-sm:min-w-24 '.$pinnedDone">
                        <div class="flex items-center gap-3">
                            <span class="w-6 shrink-0 max-sm:w-10"><span class="sr-only">{{ __('Done') }}</span></span>
                            @if ($this->canEdit)
                                {{-- In a box as high as a line, like the boxes of the rows (x-task-select-box), so both stand in one line --}}
                                <span class="flex size-6 shrink-0 items-center justify-center">
                                    <flux:checkbox wire:key="select-page-{{ $selecting ? 'on' : 'off' }}" :checked="$selecting && $this->tasks->isNotEmpty() && $this->tasks->pluck('id')->diff($this->selectedIds)->isEmpty()" wire:click="togglePage" aria-label="{{ __('Select all visible tasks') }}" />
                                </span>
                            @endif
                        </div>
                    </flux:table.column>
                    <flux:table.column :class="$pinnedTitle" sortable :sorted="$sortBy === 'title'" :direction="$sortDirection" wire:click="sort('title')">{{ __('Task') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Assignee') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'due'" :direction="$sortDirection" wire:click="sort('due')">{{ __('Due') }}</flux:table.column>
                    @foreach ($this->listFields as $field)
                        <flux:table.column wire:key="column-{{ $field->id }}" sortable :sorted="$sortBy === 'field:'.$field->id" :direction="$sortDirection" wire:click="sort('field:{{ $field->id }}')">{{ $field->name }}</flux:table.column>
                    @endforeach
                </flux:table.columns>
                {{-- wire:sort is set up once per element: keyed by the mode, the rows are built anew and dragging really stops while sorted; while
                     tasks are selected the list stands still without that (the filter, see Sortable::unlessSelecting) --}}
                @php($draggable = ! $this->sortActive && $this->canEdit)
                <flux:table.rows wire:key="rows-{{ $draggable ? 'draggable' : 'fixed' }}" :wire:sort="$draggable ? 'moveTask' : null" wire:sort:config="{{ \App\Sortable::unlessSelecting(\App\Sortable::CONFIG) }}">
                    @php($number = 0)
                    @foreach ($this->tasks as $task)
                        @if ($task->is_section)
                            <flux:table.row wire:key="task-{{ $task->id }}" :wire:sort:item="$draggable ? $task->id : null">
                                <flux:table.cell colspan="{{ $this::FIXED_COLUMNS + $this->listFields->count() }}" class="bg-zinc-50 dark:bg-zinc-900/40">
                                    <flux:heading size="sm">{{ $task->title }}</flux:heading>
                                </flux:table.cell>
                            </flux:table.row>
                            @continue
                        @endif
                        @php($number++)
                        <flux:table.row wire:key="task-{{ $task->id }}" data-task-id="{{ $task->id }}" data-opens-task="{{ $task->id }}" class="cursor-pointer" :wire:sort:item="$draggable ? $task->id : null">
                            <flux:table.cell :class="$pinnedDone">
                                <div class="flex items-center gap-3">
                                    <x-task-done-button :task="$task" :editable="$this->canEdit" />
                                    @if ($this->canEdit)
                                        <x-task-select-box :task="$task" ends-when-empty instant />
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell :class="'min-w-56 whitespace-normal max-sm:min-w-[45vw] '.$pinnedTitle">
                                <x-task-title-line :task="$task" :progress="$this->progress[$task->id] ?? null" :number="$number" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-status-badge :status="$task->status" />
                            </flux:table.cell>
                            {{-- Assignee first, then collaborators, as on the board; names and absences show on hover --}}
                            <flux:table.cell>
                                <x-assignee-picker :task="$task" :editable="$this->canEdit && ! $selecting" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-task-due :task="$task" />
                            </flux:table.cell>
                            @foreach ($this->listFields as $field)
                                <flux:table.cell wire:key="cell-{{ $task->id }}-{{ $field->id }}">
                                    <x-field-value :task="$task" :field="$field" :show-empty="false" />
                                </flux:table.cell>
                            @endforeach
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>

                {{-- Inside the table, so it only comes into sight (and loads more) at the end of the list, also while the list
                     scrolls within the window's height --}}
                @if ($this->totalTasks > $this->tasks->count())
                    <tbody>
                        <tr>
                            <td colspan="{{ $this::FIXED_COLUMNS + $this->listFields->count() }}">
                                <div wire:intersect="loadMore" class="sticky start-0 flex w-[100cqw] items-center justify-center gap-3 py-4">
                                    <flux:text size="sm">{{ __(':shown of :total tasks', ['shown' => $this->tasks->count(), 'total' => $this->totalTasks]) }}</flux:text>
                                    <flux:button size="sm" wire:click="loadMore">{{ __('Load more') }}</flux:button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @endif
            </flux:table>
        </div>
    @endif

    @if ($this->canEdit)
        <x-quick-add class="mt-2" x-show="! $wire.selecting" />
    @endif

    {{-- The bar is always there for who may edit, shown as soon as the browser selects; the dialog needs what the server knows --}}
    @if ($this->canEdit)
        <x-selection-bar x-show="$wire.selecting" x-cloak>
            <x-slot name="extra">
                @if ($this->totalTasks > $this->tasks->count())
                    @php($all = min($this->totalTasks, $this::MAX_SELECTION))
                    {{-- Gone as soon as everything the filters match is selected (the browser counts, no request needed) --}}
                    <flux:button size="sm" variant="ghost" wire:click="selectAllMatching" x-show="$wire.selected.length < {{ $all }}">{{ __('Select all :count', ['count' => $all]) }}</flux:button>
                @endif
            </x-slot>
        </x-selection-bar>
    @endif

    @if ($selecting && $this->canEdit)
        <x-bulk-edit-modal :choices="$this->bulkChoices" />
    @endif

    @if ($this->canEdit)
        <x-assignee-menu :users="$this->assignableUsers" />
    @endif

</div>
