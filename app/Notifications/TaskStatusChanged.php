<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Task $task,
        public string $oldStatus,
        public string $newStatus,
        public ?string $changedBy = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->changedBy ?? 'Jemand';

        return (new MailMessage)
            ->subject("Status geändert: {$this->task->title}")
            ->greeting("Hallo {$notifiable->name},")
            ->line("{$who} hat den Status der Aufgabe „{$this->task->title}“ im Projekt „{$this->task->project->name}“ geändert:")
            ->line("**{$this->oldStatus}** → **{$this->newStatus}**")
            ->action('Aufgabe öffnen', route('tasks.show', $this->task))
            ->line('Du bekommst diese Mail, weil du zuständig oder beteiligt bist. [Für diese Aufgabe abbestellen]('.TaskCommented::unsubscribeUrl($this->task, $notifiable).')');
    }
}
