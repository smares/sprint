<?php

namespace App\Notifications;

use App\Markdown;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class UserMentioned extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'comment'|'description'  $where
     */
    public function __construct(
        public Task $task,
        public string $where,
        public string $text,
        public ?string $mentionedBy = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->mentionedBy ?? 'Jemand';
        $place = $this->where === 'comment' ? 'in einem Kommentar zur Aufgabe' : 'in der Beschreibung der Aufgabe';

        return (new MailMessage)
            ->subject("Du wurdest erwähnt: {$this->task->title}")
            ->greeting("Hallo {$notifiable->name},")
            ->line("{$who} hat dich {$place} „{$this->task->title}“ im Projekt „{$this->task->project->name}“ erwähnt:")
            ->line('> '.Str::limit(Markdown::plainText($this->text), 500))
            ->action('Aufgabe öffnen', route('tasks.show', $this->task))
            ->line('Du bekommst diese Mail, weil dich jemand mit @ erwähnt hat. [Für diese Aufgabe abbestellen]('.TaskCommented::unsubscribeUrl($this->task, $notifiable).')');
    }

    /**
     * @return array{task_id: int, summary: string}
     */
    public function toArray(object $notifiable): array
    {
        $place = $this->where === 'comment' ? 'in einem Kommentar' : 'in der Beschreibung';

        return [
            'task_id' => $this->task->id,
            'summary' => ($this->mentionedBy ?? 'Jemand')." hat dich {$place} erwähnt",
        ];
    }
}
