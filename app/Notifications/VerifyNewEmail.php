<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\BuildsLocalizedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Goes to the new address (see User::routeNotificationForMail); the address changes only when its owner opens the link.
 */
class VerifyNewEmail extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, Queueable;

    /** How long the link works. */
    public const VALID_MINUTES = 60;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return $this->localizedMail('verify-new-email', [
            'name' => $notifiable->name,
            'email' => $notifiable->pending_email,
            'url' => URL::temporarySignedRoute('email.confirm', now()->addMinutes(self::VALID_MINUTES), [
                'user' => $notifiable->getKey(),
                'hash' => sha1((string) $notifiable->pending_email),
            ]),
            'minutes' => self::VALID_MINUTES,
        ]);
    }
}
