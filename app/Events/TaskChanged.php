<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something in a project changed: a task, a comment or an attachment. It carries only ids so nothing
 * confidential is sent over the socket; the open pages fetch what they show themselves.
 */
class TaskChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $projectId, public ?int $taskId, public string $kind) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("project.{$this->projectId}")];
    }

    public function broadcastAs(): string
    {
        return 'TaskChanged';
    }

    /**
     * @return array{project_id: int, task_id: int|null, kind: string}
     */
    public function broadcastWith(): array
    {
        return ['project_id' => $this->projectId, 'task_id' => $this->taskId, 'kind' => $this->kind];
    }
}
