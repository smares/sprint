<?php

namespace App\Concerns;

use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * What the list, board, calendar, timeline and statistics of a project share: only people who may see the project
 * get in (checked on every request), what they may do, who else is here, a fresh render after a new
 * task, and the visit for the recent projects. The component declares `public Project $project`.
 *
 * @property Project $project
 */
trait ShowsProject
{
    use ListensForRealtime;

    public function mountShowsProject(): void
    {
        Gate::authorize('view', $this->project);

        // The command palette offers the recently opened projects
        if (auth()->user()->rememberProjectVisit($this->project)) {
            $this->dispatch('project-visited');
        }
    }

    public function hydrateShowsProject(): void
    {
        Gate::authorize('view', $this->project);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('edit', $this->project);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage', $this->project);
    }

    public function presenceChannel(): string
    {
        return "project.{$this->project->getKey()}.presence";
    }

    /**
     * A task was created in the dialog of the project header; rendering again shows it, and the person is told it was saved.
     */
    #[On('task-created')]
    public function taskCreated(int $taskId = 0): void
    {
        $this->announceCreatedTask($taskId);
    }

    /**
     * The dialog closes when the task is saved, so something says it was: views that may not show the new task say so.
     */
    protected function announceCreatedTask(int $taskId): void
    {
        Flux::toast(variant: 'success', text: $this->hiddenCreatedTaskMessage($taskId) ?? __('Task created.'));
    }

    /**
     * Why the new task is not to be seen on this page (it is outside the filters, or has no date the page needs), or null.
     */
    protected function hiddenCreatedTaskMessage(int $taskId): ?string
    {
        if ($taskId <= 0 || ! method_exists($this, 'filteredTasks')) {
            return null;
        }

        if (! $this->filteredTasks()->whereKey($taskId)->exists()) {
            return __('Task created, but the current filters hide it.');
        }

        $columns = $this->datesNeededToShowTask();

        if ($columns !== [] && ! $this->project->tasks()->whereKey($taskId)->where(function ($tasks) use ($columns): void {
            foreach ($columns as $column) {
                $tasks->orWhereNotNull($column);
            }
        })->exists()) {
            return __('Task created, but without a date it does not appear here.');
        }

        return null;
    }

    /**
     * The date columns a task needs one of to appear on the page (the calendar and the timeline place tasks by their dates).
     *
     * @return list<string>
     */
    protected function datesNeededToShowTask(): array
    {
        return [];
    }

    /**
     * The check before a task in the views: done, or open again. The views compute their tasks while rendering, after this.
     */
    public function toggleDone(int $taskId): void
    {
        Gate::authorize('edit', $this->project);

        $task = $this->project->tasks()->withoutSections()->findOrFail($taskId);
        $task->setRelation('project', $this->project);
        $task->toggleDone();
    }
}
