<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DailyDigest as DailyDigestNotification;
use App\Services\DailyDigestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('digest:send {--user= : Only send to this email address}')]
#[Description('Sends the daily summary of overdue and upcoming tasks')]
class SendDailyDigest extends Command
{
    public function handle(DailyDigestService $digest): int
    {
        $sent = 0;

        User::query()
            ->active()
            ->where('digest_enabled', true)
            ->when($this->option('user'), fn ($users, string $email) => $users->where('email', $email))
            ->each(function (User $user) use ($digest, &$sent) {
                $tasks = $digest->tasksFor($user);

                if (collect($tasks)->every->isEmpty()) {
                    return;
                }

                $user->notify(new DailyDigestNotification($tasks));
                $sent++;
            });

        $this->components->info("{$sent} summary mail(s) sent.");

        return self::SUCCESS;
    }
}
