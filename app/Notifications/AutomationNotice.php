<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The "notify a person" action of an automation: an entry in the inbox, no mail.
 */
class AutomationNotice extends Notification
{
    use Queueable;

    public function __construct(public Task $task, public string $automation) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{task_id: int, kind: string, by: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'kind' => 'automation',
            'by' => $this->automation,
        ];
    }

    /**
     * The sentence for the inbox.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        return __(':name flagged this task for you', ['name' => $data['by'] ?? __('Someone')]);
    }
}
