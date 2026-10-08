<?php

namespace App\Concerns;

use App\Services\RealtimeService;

/**
 * Live updates for the pages of a project: when somebody else changes something, the page renders again
 * with fresh data. Who else is looking at the page is shown by the x-presence component in the browser alone.
 * Does nothing unless Reverb is on.
 */
trait ListensForRealtime
{
    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if (! app(RealtimeService::class)->enabled()) {
            return [];
        }

        return ["echo-private:project.{$this->realtimeProjectId()},.TaskChanged" => 'projectChangedElsewhere'];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function projectChangedElsewhere(array $event = []): void
    {
        // Receiving the event renders the component again; the data is read fresh from the database.
    }

    protected function realtimeProjectId(): int
    {
        return property_exists($this, 'task') ? $this->task->project_id : $this->project->getKey();
    }

    /**
     * The presence channel that shows who else is here (see the x-presence component), without the `presence-` prefix.
     */
    abstract public function presenceChannel(): string;
}
