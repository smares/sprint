<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDueTomorrow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('reminders:send {--force : Send again even if today\'s reminders have already gone out}')]
#[Description('Puts an inbox entry for every open task due tomorrow in the inbox of its assignee and collaborators')]
class SendDueReminders extends Command
{
    public function handle(): int
    {
        $tomorrow = now()->addDay()->startOfDay();

        // One run per day, even if the command is started again by hand or by a second scheduler
        if (! Cache::add('sprint:reminders-sent:'.now()->toDateString(), true, now()->addDays(2)) && ! $this->option('force')) {
            $this->components->info('Reminders for today have already been sent.');

            return self::SUCCESS;
        }

        $sent = 0;

        Task::query()
            ->where('is_section', false)
            ->open()
            ->whereDate('due_date', $tomorrow)
            ->whereIn('project_id', Project::query()->whereNull('archived_at')->select('id'))
            ->with(['project', 'collaborators', 'notificationMutes'])
            ->chunkById(200, function ($tasks) use (&$sent) {
                foreach ($tasks as $task) {
                    foreach ($task->usersToNotify()->filter(fn (User $user) => $user->reminders_enabled) as $user) {
                        $user->notify(new TaskDueTomorrow($task));
                        $sent++;
                    }
                }
            });

        $this->components->info("{$sent} reminder(s) for tasks due on {$tomorrow->toDateString()}.");

        return self::SUCCESS;
    }
}
