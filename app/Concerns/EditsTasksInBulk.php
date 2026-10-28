<?php

namespace App\Concerns;

use App\Enums\ActivityType;
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
     * Top-level tasks matching the filters, without order and limit.
     *
     * @return Builder<Task>|HasMany<Task, Project>
     */
    abstract protected function filteredTasks();

    /**
     * The button of the views without checkboxes in their rows (board, calendar, timeline): from here on the tasks show
     * a box to select them with, and the bar stays until the selection is ended, also while nothing is selected.
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
     * @return array{statuses: array<int, string>, users: array<int, string>, tags: array<int, string>}
     */
    #[Computed(persist: true, seconds: 60)]
    public function bulkChoices(): array
    {
        return [
            'statuses' => $this->project->statuses()->pluck('name', 'id')->all(),
            'users' => $this->project->assignableUsers()->mapWithKeys(fn (User $user) => [$user->id => $user->labelledName()])->all(),
            'tags' => $this->project->tags()->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    protected function selectionEnded(): void
    {
        unset($this->bulkChoices);
        $this->reset('bulkStatus', 'bulkAssignee', 'bulkDueDate', 'bulkClearDueDate', 'bulkAddTags', 'bulkRemoveTags');
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

        $validated = $this->validate([
            'bulkStatus' => ['nullable', Rule::in($this->project->statuses()->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'bulkAssignee' => ['nullable', Rule::in(['none', ...$this->project->eligibleUsers()->pluck('id')->map(fn ($id) => (string) $id)->all()])],
            'bulkDueDate' => ['nullable', 'date'],
            'bulkClearDueDate' => ['boolean'],
            'bulkAddTags' => ['array'],
            'bulkAddTags.*' => ['integer', Rule::exists('tags', 'id')->where('project_id', $this->project->getKey())],
            'bulkRemoveTags' => ['array'],
            'bulkRemoveTags.*' => ['integer', Rule::exists('tags', 'id')->where('project_id', $this->project->getKey())],
        ], attributes: ['bulkStatus' => __('Status'), 'bulkAssignee' => __('Assignee'), 'bulkDueDate' => __('Due date'), 'bulkAddTags.*' => __('Add tags'), 'bulkRemoveTags.*' => __('Remove tags')]);

        $due = $validated['bulkClearDueDate'] ? false : ($validated['bulkDueDate'] ?: null);

        if (($validated['bulkStatus'] ?? '') === '' && ($validated['bulkAssignee'] ?? '') === '' && $due === null && $validated['bulkAddTags'] === [] && $validated['bulkRemoveTags'] === []) {
            throw ValidationException::withMessages(['bulkStatus' => __('Select at least one change.')]);
        }

        $tasks = $this->selectedTasks();
        $tagNames = $this->project->tags()->pluck('name', 'id');

        Task::bundlingStatusNotifications(fn () => DB::transaction(function () use ($tasks, $validated, $due, $tagNames) {
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
            }
        }));

        $this->finishBulk(trans_choice('{1} One task changed.|[0,*] :count tasks changed.', $tasks->count()));
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
