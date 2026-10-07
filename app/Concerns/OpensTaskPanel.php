<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Task;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * For project views that show a task in a side panel while the list or board stays visible.
 * The open task lives in the URL (`?task=5`), so it survives reloads and can be shared.
 *
 * @property Project $project
 */
trait OpensTaskPanel
{
    #[Url(as: 'task')]
    public string $openTaskId = '';

    #[On('open-task')]
    public function openTask(int|string $id): void
    {
        if ($this->project->tasks()->where('is_section', false)->whereKey($id)->exists()) {
            $this->openTaskId = (string) $id;
        }
    }

    #[On('close-task')]
    public function closeTask(): void
    {
        $this->openTaskId = '';
    }

    /**
     * Changes made in the panel arrive as events; handling them re-renders the list or board behind it.
     */
    #[On('task-changed')]
    public function taskChanged(): void {}

    /**
     * Name, description or archiving changed in the settings window.
     */
    #[On('project-updated')]
    public function projectUpdated(): void {}

    #[On('task-deleted')]
    public function taskDeleted(): void
    {
        $this->openTaskId = '';
    }

    #[Computed]
    public function panelTask(): ?Task
    {
        if (! ctype_digit($this->openTaskId)) {
            return null;
        }

        return $this->project->tasks()->where('is_section', false)->find((int) $this->openTaskId);
    }
}
