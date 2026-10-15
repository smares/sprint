<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What the list and the board of "My tasks" have in common: the open tasks one is involved in (assigned or following,
 * in any project one can see), ticking one off, and opening any of them in the flyout.
 */
trait ListsMyTasks
{
    use OpensTaskPanel;

    /**
     * Drop the loaded tasks (and what is counted from them) after one of them changed.
     */
    abstract protected function forgetTasks(): void;

    /**
     * @return Builder<Task>
     */
    protected function mine(): Builder
    {
        return Task::query()
            ->visibleTo(auth()->user())
            ->involving(auth()->user())
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

    /**
     * The flyout opens any task one may see, whatever its project.
     *
     * @return Builder<Task>
     */
    protected function tasksForPanel(): Builder
    {
        return Task::query()->visibleTo(auth()->user());
    }
}
