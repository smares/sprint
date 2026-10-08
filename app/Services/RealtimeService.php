<?php

namespace App\Services;

use App\Events\TaskChanged;
use App\Models\Task;
use Closure;

/**
 * Live updates through Laravel Reverb. They are off unless the broadcast connection is a real one
 * (`BROADCAST_CONNECTION=reverb`); without it the app works as before and queues no events.
 */
class RealtimeService
{
    /** @var array<int, true> */
    private array $touchedProjects = [];

    private int $bundleDepth = 0;

    public function enabled(): bool
    {
        return in_array(config('broadcasting.default'), ['reverb', 'pusher', 'ably'], true);
    }

    /**
     * What the browser needs to open the WebSocket connection; the secret never leaves the server.
     *
     * @return array{key: string, host: string, port: int, scheme: string}|null
     */
    public function clientConfig(): ?array
    {
        if (! $this->enabled() || config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $options = config('broadcasting.connections.reverb.options');

        return [
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'host' => (string) ($options['host'] ?: request()->getHost()),
            'port' => (int) $options['port'],
            'scheme' => (string) $options['scheme'],
        ];
    }

    /**
     * Tell the other people looking at the project that something in it changed (only the ids, never the content).
     */
    public function taskChanged(int $projectId, ?int $taskId, string $kind): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->bundleDepth > 0) {
            $this->touchedProjects[$projectId] = true;

            return;
        }

        broadcast(new TaskChanged($projectId, $taskId, $kind))->toOthers();
    }

    /**
     * The same for a comment or an attachment, which know their task but not the project.
     */
    public function taskContentChanged(int $taskId, string $kind): void
    {
        if (! $this->enabled()) {
            return;
        }

        $projectId = Task::whereKey($taskId)->value('project_id');

        if ($projectId !== null) {
            $this->taskChanged($projectId, $taskId, $kind);
        }
    }

    /**
     * Change many tasks at once and send one message per project instead of one per task.
     */
    public function bundling(Closure $changes): mixed
    {
        $this->bundleDepth++;

        try {
            return $changes();
        } finally {
            $this->bundleDepth--;

            if ($this->bundleDepth === 0) {
                $touched = array_keys($this->touchedProjects);
                $this->touchedProjects = [];

                foreach ($touched as $projectId) {
                    $this->taskChanged($projectId, null, 'bulk');
                }
            }
        }
    }
}
