<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Hand a task of the list or board to someone else (or to nobody) with a click on its faces, see x-assignee-picker.
 *
 * @property Project $project
 */
trait AssignsTasks
{
    /**
     * Who can be picked: members and admins that are not deactivated.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignableUsers(): Collection
    {
        return $this->project->assignableUsers();
    }

    /**
     * Hand a top-level task of the project to a person who can be assigned, or (null) to nobody.
     */
    public function assign(int $taskId, ?int $userId): void
    {
        Gate::authorize('edit', $this->project);

        $task = $this->project->tasks()->topLevel()->withoutSections()->findOrFail($taskId);

        $user = $userId === null ? null : $this->assignableUsers->firstWhere('id', $userId);

        // The menu keeps the people it was opened with, so someone removed from the project meanwhile can still be picked
        if ($userId !== null && ! $user instanceof User) {
            Flux::toast(variant: 'warning', text: __('This person can no longer be assigned to tasks of this project.'));

            return;
        }

        $task->assignTo($user);

        $this->afterAssigning();
    }

    /**
     * Forget what the page has loaded, so it shows the new assignee.
     */
    abstract protected function afterAssigning(): void;
}
