<?php

namespace App\Notifications\Concerns;

use App\Models\User;

/**
 * Task notifications go by mail and to the inbox; while someone is absent (profile) only the inbox gets them,
 * so nothing is missed after coming back.
 */
trait PausesMailWhileAbsent
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->isAbsent() ? ['database'] : ['mail', 'database'];
    }
}
