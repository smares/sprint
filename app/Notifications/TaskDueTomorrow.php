<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * An inbox entry the day before a task is due (see reminders:send); no mail, the daily digest covers that.
 */
class TaskDueTomorrow extends Notification
{
    use Queueable;

    public function __construct(public Task $task) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{task_id: int, kind: string, due: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'kind' => 'due_tomorrow',
            'due' => $this->task->due_date->toDateString(),
        ];
    }

    /**
     * The sentence for the inbox, in the language of the reader.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        return __('Due tomorrow (:date)', ['date' => Carbon::parse($data['due'] ?? 'tomorrow')->isoFormat('L')]);
    }
}
