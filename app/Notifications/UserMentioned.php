<?php

namespace App\Notifications;

use App\Models\Task;
use App\Notifications\Concerns\BuildsLocalizedMail;
use App\Notifications\Concerns\PausesMail;
use App\Services\MarkdownService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class UserMentioned extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, PausesMail, Queueable;

    /**
     * @param  'comment'|'description'  $where
     */
    public function __construct(
        public Task $task,
        public string $where,
        public string $text,
        public ?string $mentionedBy = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('user-mentioned', [
            'name' => $notifiable->name,
            'who' => $this->mentionedBy,
            'where' => $this->where,
            'title' => $this->task->title,
            'project' => $this->task->project->name,
            'excerpt' => Str::limit(MarkdownService::plainText($this->text), 500),
            'url' => route('tasks.show', $this->task),
            'unsubscribeUrl' => TaskCommented::unsubscribeUrl($this->task, $notifiable),
        ]);
    }

    /**
     * @return array{task_id: int, kind: string, by: ?string, where: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'kind' => 'mentioned',
            'by' => $this->mentionedBy,
            'where' => $this->where,
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

        return ($data['where'] ?? 'comment') === 'comment'
            ? __(':name mentioned you in a comment', ['name' => $name])
            : __(':name mentioned you in the description', ['name' => $name]);
    }
}
