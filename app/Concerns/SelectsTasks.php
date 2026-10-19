<?php

namespace App\Concerns;

use App\Models\Task;
use App\Services\RealtimeService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What every page with a selection of tasks shares (the project views, see EditsTasksInBulk, and "My tasks", see
 * ListsMyTasks): the selection kept in the browser (`selected`, boxes in the rows), ending it, and marking the selected
 * tasks as done or deleting them. The page says which tasks the ids may stand for (`selectedTasks()`).
 */
trait SelectsTasks
{
    public const MAX_SELECTION = 500;

    public bool $selecting = false;

    /** @var list<int|string> */
    public array $selected = [];

    /** How many of the selected tasks the last `selectedTasks()` left out, because the person may not change them. */
    protected int $leftOut = 0;

    /**
     * The selected tasks (with their project and its statuses) that the person may change.
     *
     * @return Collection<int, Task>
     */
    abstract protected function selectedTasks(): Collection;

    /**
     * Forget what the page has loaded of the tasks, so it draws them as they are after the change.
     */
    abstract protected function forgetTasksAfterBulk(): void;

    /**
     * Whatever else the page keeps for the selection goes with it.
     */
    protected function selectionEnded(): void {}

    public function stopSelecting(): void
    {
        $this->reset('selecting', 'selected');
        $this->resetErrorBag();
        $this->selectionEnded();
    }

    private function finishBulk(string $message): void
    {
        $this->stopSelecting();
        $this->forgetTasksAfterBulk();
        Flux::modal('bulk-edit')->close();
        Flux::toast(variant: 'success', text: $message);

        if ($this->leftOut > 0) {
            Flux::toast(variant: 'warning', text: trans_choice('{1} One task was left out: you may not change tasks of its project.|[2,*] :count tasks were left out: you may not change tasks of their projects.', $this->leftOut));
        }
    }

    public function bulkComplete(): void
    {
        $tasks = $this->selectedTasks();

        Task::bundlingStatusNotifications(function () use ($tasks) {
            foreach ($tasks as $task) {
                // From the statuses loaded with the project, not one query per task
                $done = ($task->project->statuses->firstWhere('is_done', true) ?? $task->project->doneStatus())->id;

                if ($task->status_id !== $done) {
                    $task->update(['status_id' => $done]);
                }
            }
        });

        $this->finishBulk(trans_choice('{1} One task completed.|[0,*] :count tasks completed.', $tasks->count()));
    }

    public function bulkDelete(): void
    {
        $tasks = $this->selectedTasks();

        // A loop and not $tasks->each->delete(): each() stops at the first closure that returns false, and delete() returns false when it is cancelled
        app(RealtimeService::class)->bundling(fn () => DB::transaction(function () use ($tasks): void {
            foreach ($tasks as $task) {
                $task->delete();
            }
        }));

        // The flyout closes when its task was one of them
        $this->dispatch('tasks-deleted');

        $this->finishBulk(trans_choice('{1} One task deleted.|[0,*] :count tasks deleted.', $tasks->count()));
    }
}
