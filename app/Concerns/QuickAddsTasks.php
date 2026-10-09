<?php

namespace App\Concerns;

use App\Enums\ActivityType;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;

/**
 * Adding a task by typing its title in the list or in a board column, without the dialog. The component
 * declares `public Project $project` and says what a new task gets from what is shown right now
 * (`quickAddDefaults`) and how to show it (`quickAdded`).
 */
trait QuickAddsTasks
{
    /**
     * The status, assignee and tag the shown filters ask for, so that a new task does not vanish from the view.
     *
     * @return array{status_id?: int, assignee_id?: int, tag_id?: int}
     */
    abstract protected function quickAddDefaults(): array;

    /**
     * Make the new task appear: refresh what was loaded and, if the list was shown completely, make room for it.
     */
    abstract protected function quickAdded(Task $task): void;

    public function quickAdd(string $title, int|string|null $statusId = null): void
    {
        Gate::authorize('edit', $this->project);

        $title = trim($title);

        if ($title === '') {
            return;
        }

        if (mb_strlen($title) > 255) {
            Flux::toast(variant: 'danger', text: __('The title may have at most :max characters.', ['max' => 255]));

            return;
        }

        $defaults = $this->quickAddDefaults();
        $statuses = $this->project->statuses()->pluck('id');

        abort_if($statusId !== null && ! $statuses->contains((int) $statusId), 404);

        $status = (int) ($statusId ?? $defaults['status_id'] ?? $this->project->defaultStatus()->id);
        $assigneeId = $defaults['assignee_id'] ?? null;

        $task = $this->project->createRootTask([
            'title' => $title,
            'status_id' => $statuses->contains($status) ? $status : $this->project->defaultStatus()->id,
            'assignee_id' => $assigneeId !== null && $this->project->eligibleUsers()->whereKey($assigneeId)->exists() ? $assigneeId : null,
        ]);

        if (isset($defaults['tag_id']) && ($tag = $this->project->tags()->find($defaults['tag_id']))) {
            $task->logSyncChanges(ActivityType::TagsAdded, ActivityType::TagsRemoved, $task->tags()->sync([$tag->id]), fn () => [$tag->name]);
        }

        $this->quickAdded($task);
    }
}
