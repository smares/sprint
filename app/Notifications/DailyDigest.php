<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Concerns\BuildsLocalizedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class DailyDigest extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, Queueable;

    /** At most this many tasks per section; the rest is only counted, the link leads to all of them. */
    public const PER_SECTION = 25;

    /**
     * @param  array{overdue: Collection<int, Task>, today: Collection<int, Task>, upcoming: Collection<int, Task>}  $tasks
     */
    public function __construct(public array $tasks) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('daily-digest', [
            'name' => $notifiable->name,
            'tasks' => $this->tasks,
            'limit' => self::PER_SECTION,
            'url' => route('tasks.mine'),
        ]);
    }
}
