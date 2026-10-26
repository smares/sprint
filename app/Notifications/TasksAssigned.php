<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsLocalizedMail;
use App\Notifications\Concerns\PausesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One message for many tasks handed to the same person at once (a change of several tasks in bulk).
 */
class TasksAssigned extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, PausesMail, Queueable;

    /** How many tasks the mail lists before it says "and n more". */
    public const LISTED = 25;

    /**
     * @param  list<array{id: int, title: string, project: string}>  $tasks
     */
    public function __construct(public array $tasks, public ?string $assignedBy = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('tasks-assigned', [
            'name' => $notifiable->name,
            'who' => $this->assignedBy,
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
            'kind' => 'assigned_many',
            'by' => $this->assignedBy,
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
        return __(':name assigned :count tasks to you', ['name' => $data['by'] ?? __('Someone'), 'count' => $data['count'] ?? 0]);
    }
}
