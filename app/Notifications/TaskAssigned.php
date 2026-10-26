<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Concerns\BuildsLocalizedMail;
use App\Notifications\Concerns\PausesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * The task is yours now: created for you or handed to you by somebody else.
 */
#[DeleteWhenMissingModels]
class TaskAssigned extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, PausesMail, Queueable;

    public function __construct(public Task $task, public ?string $assignedBy = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('task-assigned', [
            'name' => $notifiable->name,
            'who' => $this->assignedBy,
            'title' => $this->task->title,
            'project' => $this->task->project->name,
            'url' => route('tasks.show', $this->task),
            'unsubscribeUrl' => TaskCommented::unsubscribeUrl($this->task, $notifiable),
        ]);
    }

    /**
     * @return array{task_id: int, kind: string, by: ?string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'kind' => 'assigned',
            'by' => $this->assignedBy,
        ];
    }

    /**
     * The sentence for the inbox, in the language of the reader.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        return __(':name assigned this task to you', ['name' => $data['by'] ?? __('Someone')]);
    }
}
