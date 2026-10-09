<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsLocalizedMail;
use App\Notifications\Concerns\PausesMailWhileAbsent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One message for many status changes, sent when somebody changes several tasks at once.
 */
class TasksStatusChanged extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, PausesMailWhileAbsent, Queueable;

    /** How many tasks the mail lists before it says "and n more". */
    public const LISTED = 25;

    /**
     * @param  list<array{id: int, title: string, project: string, from: string, to: string}>  $changes
     */
    public function __construct(public array $changes, public ?string $changedBy = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('tasks-status-changed', [
            'name' => $notifiable->name,
            'who' => $this->changedBy,
            'count' => count($this->changes),
            'changes' => array_slice($this->changes, 0, self::LISTED),
            'more' => max(0, count($this->changes) - self::LISTED),
            'url' => route('tasks.mine'),
        ]);
    }

    /**
     * @return array{task_id: int, kind: string, by: ?string, count: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->changes[0]['id'],
            'kind' => 'status_changed_many',
            'by' => $this->changedBy,
            'count' => count($this->changes),
        ];
    }

    /**
     * The sentence for the inbox, in the language of the reader.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        return __(':name changed the status of :count tasks', ['name' => $data['by'] ?? __('Someone'), 'count' => $data['count'] ?? 0]);
    }
}
