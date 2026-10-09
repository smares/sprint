<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\BuildsLocalizedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a new user that an account was created for them. It carries no password and no link to set one:
 * whoever created the account hands the password over, or the person asks for a new one on the login page.
 */
class AccountCreated extends Notification implements ShouldQueueAfterCommit
{
    use BuildsLocalizedMail, Queueable;

    /**
     * @param  string|null  $createdBy  Name of the person who created the account, null if it was done on the server.
     */
    public function __construct(public ?string $createdBy = null) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return $this->localizedMail('account-created', [
            'name' => $notifiable->name,
            'email' => $notifiable->email,
            'createdBy' => $this->createdBy,
            'url' => route('login'),
        ]);
    }
}
