<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsLocalizedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link for a new password, in the person's language; it is valid for the time set in config/auth.php.
 */
class ResetPassword extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, Queueable;

    public function __construct(public string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->localizedMail('reset-password', [
            'name' => $notifiable->name,
            'url' => route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]),
            'minutes' => config('auth.passwords.users.expire'),
        ]);
    }
}
