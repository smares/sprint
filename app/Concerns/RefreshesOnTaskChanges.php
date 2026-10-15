<?php

namespace App\Concerns;

use Livewire\Attributes\On;

/**
 * For views that list tasks and show one in the flyout (the component task-flyout, one for every page): what the flyout
 * reports arrives here as events, and handling them draws the list or board again.
 */
trait RefreshesOnTaskChanges
{
    /**
     * Changes made in the flyout arrive as events; handling them re-renders the list or board behind it.
     */
    #[On('task-changed')]
    public function taskChanged(): void {}

    /**
     * Name, description or archiving changed in the settings window.
     */
    #[On('project-updated')]
    public function projectUpdated(): void {}

    /**
     * A task was deleted in the flyout.
     */
    #[On('task-deleted')]
    public function taskDeleted(): void {}
}
