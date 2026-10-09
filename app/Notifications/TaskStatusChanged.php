<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Concerns\BuildsLocalizedMail;
use App\Notifications\Concerns\PausesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskStatusChanged extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, PausesMail, Queueable;

    public function __construct(
        public Task $task,
        public string $oldStatus,
        public string $newStatus,
        public ?string $changedBy = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('task-status-changed', [
            'name' => $notifiable->name,
            'who' => $this->changedBy,
            'title' => $this->task->title,
            'project' => $this->task->project->name,
            'old' => $this->oldStatus,
            'new' => $this->newStatus,
            'url' => route('tasks.show', $this->task),
            'unsubscribeUrl' => TaskCommented::unsubscribeUrl($this->task, $notifiable),
        ]);
    }

    /**
     * @return array{task_id: int, kind: string, by: ?string, from: string, to: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'kind' => 'status_changed',
            'by' => $this->changedBy,
            'from' => $this->oldStatus,
            'to' => $this->newStatus,
        ];
    }

    /**
     * The sentence for the inbox, in the language of the reader.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        return __(':name changed the status from “:from” to “:to”', ['name' => $data['by'] ?? __('Someone'), 'from' => $data['from'] ?? '–', 'to' => $data['to'] ?? '–']);
    }
}
