<?php

namespace App\Concerns;

use App\Services\RealtimeService;

/**
 * Live updates for the pages of a project: when somebody else changes something, the page renders again
 * with fresh data; optionally it also shows who else is looking at it. Does nothing unless Reverb is on.
 */
trait ListensForRealtime
{
    /**
     * Other people looking at the same page right now, keyed by id.
     *
     * @var array<int, array{id: int, name: string, initials: string}>
     */
    public array $presentUsers = [];

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if (! app(RealtimeService::class)->enabled()) {
            return [];
        }

        $listeners = ["echo-private:project.{$this->realtimeProjectId()},.TaskChanged" => 'projectChangedElsewhere'];

        if (($presence = $this->presenceChannel()) !== null) {
            $listeners["echo-presence:{$presence},here"] = 'presenceHere';
            $listeners["echo-presence:{$presence},joining"] = 'presenceJoining';
            $listeners["echo-presence:{$presence},leaving"] = 'presenceLeaving';
        }

        return $listeners;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function projectChangedElsewhere(array $event = []): void
    {
        // Receiving the event renders the component again; the data is read fresh from the database.
    }

    /**
     * @param  list<array{id: int, name: string, initials: string}>  $users
     */
    public function presenceHere(array $users = []): void
    {
        $this->presentUsers = [];

        foreach ($users as $user) {
            $this->presenceJoining($user);
        }
    }

    /**
     * @param  array{id?: int, name?: string, initials?: string}  $user
     */
    public function presenceJoining(array $user = []): void
    {
        if (isset($user['id']) && $user['id'] !== auth()->id()) {
            $this->presentUsers[$user['id']] = ['id' => $user['id'], 'name' => (string) ($user['name'] ?? ''), 'initials' => (string) ($user['initials'] ?? '')];
        }
    }

    /**
     * @param  array{id?: int}  $user
     */
    public function presenceLeaving(array $user = []): void
    {
        unset($this->presentUsers[$user['id'] ?? 0]);
    }

    protected function realtimeProjectId(): int
    {
        return property_exists($this, 'task') ? $this->task->project_id : $this->project->getKey();
    }

    /**
     * The presence channel to join, without the `presence-` prefix; null for pages that show no presence.
     */
    protected function presenceChannel(): ?string
    {
        return null;
    }
}
