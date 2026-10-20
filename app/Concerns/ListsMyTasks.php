<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Session;

/**
 * What the list and the board of "My tasks" have in common: the open tasks assigned to one (in any project one
 * can see; on request also those one collaborates on), ticking one off, selecting several to mark them as done or delete
 * them together, and opening any of them in the flyout.
 */
trait ListsMyTasks
{
    use RefreshesOnTaskChanges;
    use SelectsTasks;

    /**
     * Drop the loaded tasks (and what is counted from them) after one of them changed.
     */
    abstract protected function forgetTasks(): void;

    /**
     * "My tasks" opens in the view one used last, on every device. Only a change of view inside the app counts: a link in
     * an e-mail or a bookmark opens a view without making it the one used last.
     */
    public function mountListsMyTasks(): void
    {
        $view = request()->routeIs('tasks.mine.board') ? 'board' : 'list';

        if (request()->hasHeader('X-Livewire-Navigate') && (auth()->user()->interfaceState()['mine_view'] ?? null) !== $view) {
            auth()->user()->rememberInterface(['mine_view' => $view]);
        }
    }

    /**
     * Whether the tasks one only collaborates on are listed too. Kept for the session, for the list and the board alike.
     */
    #[Session(key: 'my-tasks-include-collaborating')]
    public bool $includeCollaborating = false;

    public function updatedIncludeCollaborating(): void
    {
        // The tasks one only collaborates on may be among the selected ones, and are not listed any more
        $this->selected = [];
        $this->forgetTasks();
    }

    /**
     * @return Builder<Task>
     */
    protected function mine(): Builder
    {
        return Task::query()
            ->visibleTo(auth()->user())
            ->when(
                $this->includeCollaborating,
                fn (Builder $tasks) => $tasks->assignedToOrCollaboratingOn(auth()->user()),
                fn (Builder $tasks) => $tasks->assignedTo(auth()->user()),
            )
            ->open();
    }

    /**
     * The projects of the given tasks in which one may tick tasks off.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @return list<int>
     */
    protected function editableProjectIdsOf(\Illuminate\Support\Collection $tasks): array
    {
        $projects = new Collection($tasks->pluck('project')->unique('id')->values()->all());
        Project::rememberRolesFor(auth()->user(), $projects);

        return $projects
            ->filter(fn (Project $project) => Gate::allows('edit', $project))
            ->pluck('id')->values()->all();
    }

    public function toggleDone(int $taskId): void
    {
        $task = Task::query()->visibleTo(auth()->user())->withoutSections()->findOrFail($taskId);
        Gate::authorize('edit', $task->project);

        $task->toggleDone();

        $this->forgetTasks();
    }

    public function startSelecting(): void
    {
        $this->selecting = true;
        $this->selected = [];
    }

    /**
     * The selected tasks that are among the listed ones and in projects one may edit.
     *
     * @return Collection<int, Task>
     */
    protected function selectedTasks(): Collection
    {
        $ids = array_slice(array_map(intval(...), $this->selected), 0, self::MAX_SELECTION);

        if ($ids === []) {
            throw ValidationException::withMessages(['selected' => __('Select tasks first.')]);
        }

        $tasks = $this->mine()->whereKey($ids)->with(['project.statuses', 'status', 'assignee', 'collaborators', 'notificationMutes'])->get();
        $editable = $this->editableProjectIdsOf($tasks);
        $allowed = $tasks->filter(fn (Task $task) => in_array($task->project_id, $editable, true))->values();

        if ($allowed->isEmpty()) {
            throw ValidationException::withMessages(['selected' => __('You may not change these tasks.')]);
        }

        $this->leftOut = $tasks->count() - $allowed->count();

        return new Collection($allowed->all());
    }

    protected function forgetTasksAfterBulk(): void
    {
        $this->forgetTasks();
    }
}
