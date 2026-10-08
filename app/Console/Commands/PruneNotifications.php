<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

#[Signature('notifications:prune')]
#[Description('Removes read inbox notifications that are older than the configured number of days')]
class PruneNotifications extends Command
{
    public function handle(): int
    {
        $deleted = DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays((int) config('sprint.keep_read_notifications_days')))
            ->delete();

        $this->components->info("{$deleted} read notification(s) removed.");

        return self::SUCCESS;
    }
}
