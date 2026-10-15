<?php

use App\Models\Task;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The flyout with the open task, one for every page (list, board, calendar, timeline, statistics, "My tasks", workload, search).
 * It is a component of its own so that opening, closing and walking through tasks asks the server for the flyout only and
 * does not render the list or board behind it again.
 *
 * The open task lives in the URL (`?task=5`), so it survives reloads and can be shared. Each change is a new browser
 * history entry, so the browser's back button closes the flyout (or goes back to the task before) instead of leaving the page.
 */
new class extends Component
{
    #[Url(as: 'task', history: true)]
    public string $openTaskId = '';

    /**
     * Any task one may see opens, whatever page asks for it.
     */
    #[On('open-task')]
    public function openTask(int|string $id): void
    {
        if (Task::query()->visibleTo(auth()->user())->withoutSections()->whereKey($id)->exists()) {
            $this->openTaskId = (string) $id;
        }
    }

    #[On('close-task')]
    public function closeTask(): void
    {
        $this->openTaskId = '';
    }

    /**
     * A task was deleted in the flyout, or some tasks were deleted on the page behind it: the flyout closes when its task is gone.
     */
    #[On('task-deleted')]
    #[On('tasks-deleted')]
    public function taskDeleted(): void
    {
        unset($this->panelTask);

        if ($this->panelTask === null) {
            $this->openTaskId = '';
        }
    }

    #[Computed]
    public function panelTask(): ?Task
    {
        if (! ctype_digit($this->openTaskId)) {
            return null;
        }

        return Task::query()->visibleTo(auth()->user())->withoutSections()->find((int) $this->openTaskId);
    }
};
?>

{{-- The title links of the pages light up for the open task (the store is read in components/task-title-link) --}}
<div x-data x-init="$store.taskFlyout.id = $wire.openTaskId; $wire.$watch('openTaskId', (id) => $store.taskFlyout.id = id)">
    <x-task-panel :task="$this->panelTask" />
</div>
