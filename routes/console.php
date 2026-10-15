<?php

use App\Services\HealthCheckService;
use App\Services\ScheduleGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
 * The clocks change between 01:00 and 03:00 in most zones: that stretch is missing in spring and happens twice in autumn.
 * Nothing runs in it by default, and ScheduleGuard makes every daily command run exactly once on those days even if a
 * time is set into it (onOneServer() and withoutOverlapping() would not: the first locks a minute for an hour, the
 * second only a run that is still going). The guard goes last, as it remembers the run.
 */
$digestTime = ScheduleGuard::time(config('sprint.digest_time'), '07:30');
$reminderTime = ScheduleGuard::time(config('sprint.reminder_time'), '08:00');
$backupTime = ScheduleGuard::time(config('sprint.backup.time'), '03:30');

Schedule::command('digest:send')->weekdays()->dailyAt($digestTime)->onOneServer()->withoutOverlapping()->when(ScheduleGuard::once('digest:send', $digestTime));

Schedule::command('reminders:send')->dailyAt($reminderTime)->onOneServer()->withoutOverlapping()->when(ScheduleGuard::once('reminders:send', $reminderTime));

Schedule::command('notifications:prune')->dailyAt('03:15')->onOneServer()->when(ScheduleGuard::once('notifications:prune', '03:15'));

Schedule::command('cache:prune-expired')->dailyAt('03:20')->onOneServer()->when(ScheduleGuard::once('cache:prune-expired', '03:20'));

Schedule::command('sprint:backup')->dailyAt($backupTime)->onOneServer()->withoutOverlapping()->when(fn () => config('sprint.backup.enabled'))->when(ScheduleGuard::once('sprint:backup', $backupTime));

Schedule::call(fn () => Cache::put(HealthCheckService::SCHEDULER_HEARTBEAT, now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');
