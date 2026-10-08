<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\BuildsLocalizedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Goes to the previous address after a change, so that a change nobody asked for does not go unnoticed.
 */
class EmailChanged extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, Queueable;

    public function __construct(public string $previousEmail) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return $this->localizedMail('email-changed', [
            'name' => $notifiable->name,
            'email' => $notifiable->email,
        ]);
    }
}
