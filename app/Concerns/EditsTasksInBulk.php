<?php

namespace App\Concerns;

use App\Enums\ActivityType;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * Select several top-level tasks of a project view (the list, the board, the calendar, the timeline) and change or delete
 * them together. Only tasks that `filteredTasks()` returns count as selected, so nothing the view does not offer is touched
 * by accident; the list narrows that to its filters and clears the selection when they change.
 *
 * @property Project $project
 */
trait EditsTasksInBulk
{
    use SelectsTasks;

    public string $bulkStatus = '';

    public string $bulkAssignee = '';

    public string $bulkDueDate = '';

    public bool $bulkClearDueDate = false;

    /** @var list<int|string> */
    public array $bulkAddTags = [];

    /** @var list<int|string> */
    public array $bulkRemoveTags = [];

    /**
     * The values for the custom fields, by field id: empty leaves a field as it is; for selection fields an option id,
     * or `none` to remove the value.
     *
     * @var array<int|string, string|null>
     */
    public array $bulkFields = [];

    /**
     * Fields (text, number, date) whose value is removed, by field id; that wins over a value entered for them.
     *
     * @var array<int|string, bool>
     */
    public array $bulkClearFields = [];

    /** Whether the fields the list does not show are unfolded in the dialog; they unfold on their own when one of them has an error. */
    public bool $bulkMoreFields = false;

    /**
     * Top-level tasks matching the filters, without order and limit.
     *
     * @return Builder<Task>|HasMany<Task, Project>
     */
    abstract protected function filteredTasks();

    /**
     * The selection starts on the server: from here on the tasks show a box to select them with (the browser shows them at
     * once, see x-selection-toggle), and the bar stays until the selection is ended, also while nothing is selected.
     */
    public function startSelecting(): void
    {
        Gate::authorize('edit', $this->project);

        $this->selecting = true;
        $this->selected = [];
        unset($this->selectedIds);
    }

    /**
     * What the dialog offers: the statuses, the people who can be assigned and the tags of the project, as id => name. Kept for
     * a minute and until the selection ends, not asked for with every render while tasks are selected.
     *
     * The custom fields come in their order, with whether the list shows them (the dialog shows those first, the others
     * folded away) and, for selection fields, their options.
     *
     * @return array{statuses: array<int, string>, users: array<int, string>, tags: array<int, string>, fields: list<array{id: int, name: string, type: string, in_list: bool, options: array<int, string>}>}
     */
    #[Computed(persist: true, seconds: 60)]
    public function bulkChoices(): array
    {
        return [
            'statuses' => $this->project->statuses()->pluck('name', 'id')->all(),
            'users' => $this->project->assignableUsers()->mapWithKeys(fn (User $user) => [$user->id => $user->labelledName()])->all(),
            'tags' => $this->project->tags()->orderBy('name')->pluck('name', 'id')->all(),
            'fields' => $this->project->customFields()->with('options')->get()->map(fn (CustomField $field) => [
                'id' => $field->id,
                'name' => $field->name,
                'type' => $field->type->value,
                'in_list' => $field->show_in_list,
                'options' => $field->options->mapWithKeys(fn (CustomFieldOption $option) => [$option->id => $option->name])->all(),
            ])->values()->all(),
        ];
    }

    /**
     * The dialog for changing the selected tasks opens empty: what was entered before, or the errors of an earlier try, stay behind.
     * The browser starts and ends a selection on its own, so this is where the server sees the selection for the first time.
     */
    public function openBulkEdit(): void
    {
        Gate::authorize('edit', $this->project);

        $this->reset('bulkStatus', 'bulkAssignee', 'bulkDueDate', 'bulkClearDueDate', 'bulkAddTags', 'bulkRemoveTags', 'bulkFields', 'bulkClearFields', 'bulkMoreFields');
        $this->resetErrorBag();
        unset($this->bulkChoices);

        // Every field starts empty ("Leave unchanged"), so a selection field shows that choice and not a blank button
        $this->bulkFields = collect($this->bulkChoices['fields'])->mapWithKeys(fn (array $field) => [$field['id'] => ''])->all();
    }

    protected function selectionEnded(): void
    {
        unset($this->bulkChoices);
        $this->reset('bulkStatus', 'bulkAssignee', 'bulkDueDate', 'bulkClearDueDate', 'bulkAddTags', 'bulkRemoveTags', 'bulkFields', 'bulkClearFields', 'bulkMoreFields');
        unset($this->selectedIds);
    }

    /**
     * The ids that are selected and still shown by the filters.
     *
     * @return list<int>
     */
    #[Computed]
    public function selectedIds(): array
    {
        if (! $this->selecting || $this->selected === []) {
            return [];
        }

        return $this->filteredTasks()
            ->withoutSections()
            ->whereIn('id', collect($this->selected)->map(fn ($id) => (int) $id)->take(self::MAX_SELECTION))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    protected function selectedTasks(): EloquentCollection
    {
        Gate::authorize('edit', $this->project);

        $ids = $this->selectedIds;

        if ($ids === []) {
            throw ValidationException::withMessages(['selected' => __('Select tasks first.')]);
        }

        return $this->project->tasks()->topLevel()->withoutSections()->whereKey($ids)->with(['project.statuses', 'status', 'assignee', 'collaborators', 'notificationMutes'])->get();
    }

    public function applyBulkChanges(): void
    {
        Gate::authorize('edit', $this->project);

        $fields = $this->project->customFields()->with('options')->get();

        $validated = $this->withValidator(fn ($validator) => $validator->after(function ($validator) use ($fields) {
            $this->bulkMoreFields = $this->bulkMoreFields || $fields->contains(fn (CustomField $field) => ! $field->show_in_list && $validator->errors()->has("bulkFields.{$field->id}"));
        }))->validate([
            'bulkStatus' => ['nullable', Rule::in($this->project->statuses()->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'bulkAssignee' => ['nullable', Rule::in(['none', ...$this->project->eligibleUsers()->pluck('id')->map(fn ($id) => (string) $id)->all()])],
            'bulkDueDate' => ['nullable', 'date'],
            'bulkClearDueDate' => ['boolean'],
            'bulkAddTags' => ['array'],
            'bulkAddTags.*' => ['integer', Rule::exists('tags', 'id')->where('project_id', $this->project->getKey())],
            'bulkRemoveTags' => ['array'],
            'bulkRemoveTags.*' => ['integer', Rule::exists('tags', 'id')->where('project_id', $this->project->getKey())],
            'bulkFields' => ['array'],
            'bulkClearFields' => ['array'],
            ...$fields->mapWithKeys(fn (CustomField $field) => ["bulkFields.{$field->id}" => match ($field->type) {
                CustomFieldType::Select => ['nullable', Rule::in(['none', ...$field->options->pluck('id')->map(fn ($id) => (string) $id)->all()])],
                CustomFieldType::Text => ['nullable', 'string', 'max:500'],
                CustomFieldType::Number => ['nullable', 'numeric'],
                CustomFieldType::Date => ['nullable', 'date_format:Y-m-d'],
            }])->all(),
            ...$fields->mapWithKeys(fn (CustomField $field) => ["bulkClearFields.{$field->id}" => ['boolean']])->all(),
        ], attributes: [
            'bulkStatus' => __('Status'), 'bulkAssignee' => __('Assignee'), 'bulkDueDate' => __('Due date'), 'bulkAddTags.*' => __('Add tags'), 'bulkRemoveTags.*' => __('Remove tags'),
            ...$fields->mapWithKeys(fn (CustomField $field) => ["bulkFields.{$field->id}" => $field->name])->all(),
        ]);

        $due = $validated['bulkClearDueDate'] ? false : ($validated['bulkDueDate'] ?: null);
        $fieldChanges = $this->bulkFieldChanges($fields, $validated['bulkFields'] ?? [], $validated['bulkClearFields'] ?? []);

        if (($validated['bulkStatus'] ?? '') === '' && ($validated['bulkAssignee'] ?? '') === '' && $due === null && $validated['bulkAddTags'] === [] && $validated['bulkRemoveTags'] === [] && $fieldChanges === []) {
            throw ValidationException::withMessages(['bulkStatus' => __('Select at least one change.')]);
        }

        $tasks = $this->selectedTasks();
        $tagNames = $this->project->tags()->pluck('name', 'id');

        Task::bundlingStatusNotifications(fn () => DB::transaction(function () use ($tasks, $validated, $due, $tagNames, $fieldChanges) {
            foreach ($tasks as $task) {
                $changes = [];

                if (($validated['bulkStatus'] ?? '') !== '') {
                    $changes['status_id'] = (int) $validated['bulkStatus'];
                }

                if (($validated['bulkAssignee'] ?? '') !== '') {
                    $changes['assignee_id'] = $validated['bulkAssignee'] === 'none' ? null : (int) $validated['bulkAssignee'];
                }

                if ($due === false) {
                    $changes['due_date'] = null;
                } elseif ($due !== null) {
                    $changes['due_date'] = $due;

                    if ($task->start_date !== null && $task->start_date->toDateString() > $due) {
                        $changes['start_date'] = $due;
                    }
                }

                if ($changes !== []) {
                    $task->updateAssigning($changes);
                }

                $this->syncBulkTags($task, $validated['bulkAddTags'], $validated['bulkRemoveTags'], $tagNames);

                foreach ($fieldChanges as $change) {
                    $task->setFieldValue($change['field'], $change['attributes']);
                }
            }
        }));

        $this->finishBulk(trans_choice('{1} One task changed.|[0,*] :count tasks changed.', $tasks->count()));
    }

    /**
     * What the dialog sets on the custom fields: for each field that was filled in or is to be emptied, the field and what
     * to store (null removes the value). Fields of other projects and fields left empty are not among them.
     *
     * @param  Collection<int, CustomField>|EloquentCollection<int, CustomField>  $fields
     * @param  array<int|string, string|null>  $values
     * @param  array<int|string, bool>  $clear
     * @return list<array{field: CustomField, attributes: array{option_id: ?int, value: ?string}|null}>
     */
    private function bulkFieldChanges(Collection|EloquentCollection $fields, array $values, array $clear): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $value = trim((string) ($values[$field->id] ?? ''));

            if ($field->type === CustomFieldType::Select) {
                if ($value !== '') {
                    $changes[] = ['field' => $field, 'attributes' => $value === 'none' ? null : ['option_id' => (int) $value, 'value' => null]];
                }
            } elseif ((bool) ($clear[$field->id] ?? false)) {
                $changes[] = ['field' => $field, 'attributes' => null];
            } elseif ($value !== '') {
                $changes[] = ['field' => $field, 'attributes' => ['option_id' => null, 'value' => $value]];
            }
        }

        return $changes;
    }

    /**
     * @param  list<int|string>  $add
     * @param  list<int|string>  $remove
     * @param  Collection<int, string>  $names
     */
    private function syncBulkTags(Task $task, array $add, array $remove, Collection $names): void
    {
        $changes = [
            'attached' => $add === [] ? [] : $task->tags()->syncWithoutDetaching(array_map(intval(...), $add))['attached'],
            'detached' => $remove === [] ? [] : $task->tags()->whereKey(array_map(intval(...), $remove))->pluck('tags.id')->all(),
        ];

        if ($changes['detached'] !== []) {
            $task->tags()->detach($changes['detached']);
        }

        $task->logSyncChanges(ActivityType::TagsAdded, ActivityType::TagsRemoved, $changes, fn (array $ids) => collect($ids)->map(fn (int $id) => $names[$id])->sort()->values()->all());
    }
}
