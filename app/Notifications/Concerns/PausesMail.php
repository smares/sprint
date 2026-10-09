<?php

namespace App\Notifications\Concerns;

use App\Models\User;

/**
 * Task notifications go by mail and to the inbox; during an absence or a quiet time (profile) only the inbox gets
 * them, so nothing is missed afterwards.
 */
trait PausesMail
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && ! $notifiable->wantsMailNow() ? ['database'] : ['mail', 'database'];
    }
}
