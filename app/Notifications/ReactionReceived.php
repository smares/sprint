<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Someone reacted to your task or comment: an entry in the inbox, no mail.
 */
class ReactionReceived extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public User $reactor,
        public string $target,
        public int $targetId,
        public string $emoji,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{task_id: int, kind: string, by: string, reactor_id: int, target: string, target_id: int, emoji: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'kind' => 'reaction',
            'by' => $this->reactor->name,
            'reactor_id' => $this->reactor->id,
            'target' => $this->target,
            'target_id' => $this->targetId,
            'emoji' => $this->emoji,
        ];
    }

    /**
     * The sentence for the inbox, in the language of the reader.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sentence(array $data): string
    {
        $replacements = ['name' => $data['by'] ?? __('Someone'), 'emoji' => (string) ($data['emoji'] ?? '')];

        return ($data['target'] ?? 'task') === 'comment'
            ? __(':name reacted :emoji to your comment', $replacements)
            : __(':name reacted :emoji to your task', $replacements);
    }
}
