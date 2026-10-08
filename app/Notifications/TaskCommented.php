<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Notifications\Concerns\BuildsLocalizedMail;
use App\Services\MarkdownService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class TaskCommented extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, Queueable;

    public function __construct(public Comment $comment) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->comment->task;

        return $this->localizedMail('task-commented', [
            'name' => $notifiable->name,
            'who' => $this->comment->user->name,
            'title' => $task->title,
            'project' => $task->project->name,
            'excerpt' => Str::limit(MarkdownService::plainText($this->comment->body), 500),
            'url' => route('tasks.show', $task),
            'unsubscribeUrl' => self::unsubscribeUrl($task, $notifiable),
        ]);
    }

    /**
     * What the in-app inbox shows; names and text are not stored, the task is read live.
     *
     * @return array{task_id: int, kind: string, by: ?string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->comment->task_id,
            'kind' => 'commented',
            'by' => $this->comment->user?->name,
        ];
    }

    /**
     * The sentence for the inbox, in the language of the reader.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        return __(':name commented', ['name' => $data['by'] ?? __('Someone')]);
    }

    public static function unsubscribeUrl(object $task, object $user): string
    {
        return URL::signedRoute('tasks.notifications', ['task' => $task, 'user' => $user]);
    }
}
