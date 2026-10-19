<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Task;
use App\Services\RealtimeService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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

    /**
     * Drop the loaded tasks (and what is counted from them) after one of them changed.
     */
    abstract protected function forgetTasks(): void;

    /**
     * Whether the tasks one only collaborates on are listed too. Kept for the session, for the list and the board alike.
     */
    #[Session(key: 'my-tasks-include-collaborating')]
    public bool $includeCollaborating = false;

    public function updatedIncludeCollaborating(): void
    {
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

    public const MAX_SELECTION = 500;

    public bool $selecting = false;

    /** @var list<int|string> */
    public array $selected = [];

    public function startSelecting(): void
    {
        $this->selecting = true;
        $this->selected = [];
    }

    public function stopSelecting(): void
    {
        $this->reset('selecting', 'selected');
    }

    /**
     * The selected tasks that are among the listed ones and in projects one may edit, and how many were left out.
     *
     * @return array{0: Collection<int, Task>, 1: int}
     */
    private function selectedTasks(): array
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

        return [new Collection($allowed->all()), $tasks->count() - $allowed->count()];
    }

    private function finishBulk(string $message, int $skipped): void
    {
        $this->stopSelecting();
        $this->forgetTasks();
        Flux::toast(variant: 'success', text: $message);

        if ($skipped > 0) {
            Flux::toast(variant: 'warning', text: trans_choice('{1} One task was left out: you may not change tasks of its project.|[2,*] :count tasks were left out: you may not change tasks of their projects.', $skipped));
        }
    }

    public function bulkComplete(): void
    {
        [$tasks, $skipped] = $this->selectedTasks();

        Task::bundlingStatusNotifications(function () use ($tasks) {
            foreach ($tasks as $task) {
                $done = $task->project->doneStatus()->id;

                if ($task->status_id !== $done) {
                    $task->update(['status_id' => $done]);
                }
            }
        });

        $this->finishBulk(trans_choice('{1} One task completed.|[0,*] :count tasks completed.', $tasks->count()), $skipped);
    }

    public function bulkDelete(): void
    {
        [$tasks, $skipped] = $this->selectedTasks();

        // A loop and not $tasks->each->delete(): each() stops at the first closure that returns false, and delete() returns false when it is cancelled
        app(RealtimeService::class)->bundling(fn () => DB::transaction(function () use ($tasks): void {
            foreach ($tasks as $task) {
                $task->delete();
            }
        }));

        // The flyout closes when its task was one of them
        $this->dispatch('tasks-deleted');

        $this->finishBulk(trans_choice('{1} One task deleted.|[0,*] :count tasks deleted.', $tasks->count()), $skipped);
    }
}
