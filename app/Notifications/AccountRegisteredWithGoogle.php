<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\BuildsLocalizedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the administrators that somebody registered with their Google account: the new account sees no project and has no
 * rights until an administrator gives them.
 */
class AccountRegisteredWithGoogle extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, Queueable;

    public function __construct(public User $registered) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return $this->localizedMail('account-registered-with-google', [
            'name' => $notifiable->name,
            'registeredName' => $this->registered->name,
            'registeredEmail' => $this->registered->email,
            'url' => route('admin.users'),
        ]);
    }
}
