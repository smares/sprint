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
 * A task was finished or opened again by somebody else. What is stored is what happened, not the words, so the inbox and the mail
 * follow the language of the reader.
 */
#[DeleteWhenMissingModels]
class TaskCompletionChanged extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, PausesMail, Queueable;

    public function __construct(
        public Task $task,
        public bool $completed,
        public ?string $changedBy = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('task-completion-changed', [
            'name' => $notifiable->name,
            'who' => $this->changedBy,
            'completed' => $this->completed,
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
            'kind' => $this->completed ? 'completed' : 'reopened',
            'by' => $this->changedBy,
        ];
    }

    /**
     * The sentence for the inbox, in the language of the reader.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        $name = $data['by'] ?? __('Someone');

        return ($data['kind'] ?? 'completed') === 'reopened' ? __(':name reopened the task', ['name' => $name]) : __(':name completed the task', ['name' => $name]);
    }
}
