<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class DailyDigest extends Notification implements ShouldQueue
{
    use Queueable;

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
        $parts = array_filter([
            $this->tasks['overdue']->isNotEmpty() ? $this->tasks['overdue']->count().' überfällig' : null,
            $this->tasks['today']->isNotEmpty() ? $this->tasks['today']->count().' heute fällig' : null,
            $this->tasks['upcoming']->isNotEmpty() ? $this->tasks['upcoming']->count().' demnächst' : null,
        ]);

        $mail = (new MailMessage)
            ->subject('Deine Aufgaben: '.implode(', ', $parts))
            ->greeting("Guten Morgen {$notifiable->name},");

        foreach (['overdue' => 'Überfällig', 'today' => 'Heute fällig', 'upcoming' => 'In den nächsten Tagen'] as $key => $heading) {
            if ($this->tasks[$key]->isEmpty()) {
                continue;
            }

            $mail->line("**{$heading}**");

            foreach ($this->tasks[$key] as $task) {
                $mail->line("- [{$task->title}](".route('tasks.show', $task).") · {$task->project->name} · {$task->due_date->format('d.m.Y')}");
            }
        }

        return $mail
            ->action('Meine Aufgaben öffnen', route('tasks.mine'))
            ->line('Du bekommst diese Zusammenfassung werktags, wenn du zuständig oder beteiligt bist. Du kannst sie in deinem Profil abstellen.');
    }
}
