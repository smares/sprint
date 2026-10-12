<?php

namespace App\Notifications;

use App\Http\Controllers\ProgressiveWebAppController;
use App\Models\Task;
use App\Models\User;
use App\Services\InboxTextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A new inbox entry as a push notification on the devices someone turned push on for (profile): the task as the title,
 * the inbox sentence in the reader's language as the text, a tap opens the task. Not during an absence or a quiet time,
 * like the emails; the inbox still has everything.
 */
class InboxPush extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Push services keep an undelivered message this long (seconds) for a device that is off. */
    private const int TIME_TO_LIVE = 86400;

    /**
     * Without it push services treat the message as normal priority, which Android holds back while the phone sleeps
     * (Doze) until it wakes up, e.g. when the person opens an app. High wakes the device at once, like a chat message.
     */
    public const string URGENCY = 'high';

    public function __construct(public DatabaseNotification $entry) {}

    /**
     * Checked again when the queue sends it: someone who lost access to the project or was deactivated meanwhile
     * gets nothing on the lock screen.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $task = $this->task();

        return $notifiable instanceof User && $notifiable->isActive() && $notifiable->acceptsNotificationsNow()
            && $task instanceof Task && Gate::forUser($notifiable)->allows('view', $task->project)
            ? [WebPushChannel::class] : [];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $task = $this->task();

        return (new WebPushMessage)
            ->title($task->title)
            ->body(InboxTextService::sentence($this->entry).' · '.$task->project->name)
            ->icon(ProgressiveWebAppController::ICON)
            ->lang(app()->getLocale())
            ->tag('task-'.$task->id)
            ->renotify()
            ->data(['url' => route('tasks.show', $task, absolute: false)])
            ->options(['TTL' => self::TIME_TO_LIVE, 'urgency' => self::URGENCY]);
    }

    private function task(): ?Task
    {
        return Task::with('project')->find($this->entry->data['task_id'] ?? null);
    }
}
