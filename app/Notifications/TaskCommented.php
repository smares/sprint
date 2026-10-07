<?php

namespace App\Notifications;

use App\Markdown;
use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class TaskCommented extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Comment $comment) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->comment->task;

        return (new MailMessage)
            ->subject("Neuer Kommentar: {$task->title}")
            ->greeting("Hallo {$notifiable->name},")
            ->line("{$this->comment->user->name} hat die Aufgabe „{$task->title}“ im Projekt „{$task->project->name}“ kommentiert:")
            ->line('> '.Str::limit(Markdown::plainText($this->comment->body), 500))
            ->action('Aufgabe öffnen', route('tasks.show', $task))
            ->line('Du bekommst diese Mail, weil du zuständig oder beteiligt bist. [Für diese Aufgabe abbestellen]('.self::unsubscribeUrl($task, $notifiable).')');
    }

    public static function unsubscribeUrl(object $task, object $user): string
    {
        return URL::signedRoute('tasks.notifications', ['task' => $task, 'user' => $user]);
    }
}
