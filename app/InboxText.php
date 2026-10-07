<?php

namespace App;

use App\Notifications\TaskCommented;
use App\Notifications\TaskStatusChanged;
use App\Notifications\UserMentioned;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The line shown for an inbox entry. Entries store what happened, not the words, so the sentence follows
 * the language of the reader; older entries that stored their sentence are shown as they were.
 */
class InboxText
{
    public static function sentence(DatabaseNotification $notification): string
    {
        $data = $notification->data;

        return match (true) {
            isset($data['summary']) => $data['summary'],
            $notification->type === TaskCommented::class => TaskCommented::sentence($data),
            $notification->type === TaskStatusChanged::class => TaskStatusChanged::sentence($data),
            $notification->type === UserMentioned::class => UserMentioned::sentence($data),
            default => '',
        };
    }
}
