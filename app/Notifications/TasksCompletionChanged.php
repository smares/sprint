<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsLocalizedMail;
use App\Notifications\Concerns\PausesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One message for many tasks finished or opened again at once, sent when somebody changes several tasks together.
 */
class TasksCompletionChanged extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, PausesMail, Queueable;

    /** How many tasks the mail lists before it says "and n more". */
    public const LISTED = 25;

    /**
     * @param  list<array{id: int, title: string, project: string}>  $tasks
     */
    public function __construct(public array $tasks, public bool $completed, public ?string $changedBy = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('tasks-completion-changed', [
            'name' => $notifiable->name,
            'who' => $this->changedBy,
            'completed' => $this->completed,
            'count' => count($this->tasks),
            'tasks' => array_slice($this->tasks, 0, self::LISTED),
            'more' => max(0, count($this->tasks) - self::LISTED),
            'url' => route($notifiable->mineTasksRoute()),
        ]);
    }

    /**
     * @return array{task_id: int, kind: string, by: ?string, count: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->tasks[0]['id'],
            'kind' => $this->completed ? 'completed_many' : 'reopened_many',
            'by' => $this->changedBy,
            'count' => count($this->tasks),
        ];
    }

    /**
     * The sentence for the inbox, in the language of the reader.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        $replace = ['name' => $data['by'] ?? __('Someone'), 'count' => $data['count'] ?? 0];

        return ($data['kind'] ?? 'completed_many') === 'reopened_many' ? __(':name reopened :count tasks', $replace) : __(':name completed :count tasks', $replace);
    }
}
