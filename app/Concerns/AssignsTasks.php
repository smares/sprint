<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\User;
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
        return $this->project->eligibleUsers()->orderBy('name')->get(['id', 'name', 'avatar_updated_at', 'timezone', 'absent_from', 'absent_until']);
    }

    public function assign(int $taskId, ?int $userId): void
    {
        Gate::authorize('edit', $this->project);

        $task = $this->project->tasks()->topLevel()->withoutSections()->findOrFail($taskId);

        $user = $userId === null ? null : $this->assignableUsers->firstWhere('id', $userId);

        abort_unless($userId === null || $user instanceof User, 422);

        $task->assignTo($user);

        $this->afterAssigning();
    }

    /**
     * Forget what the page has loaded, so it shows the new assignee.
     */
    abstract protected function afterAssigning(): void;
}
