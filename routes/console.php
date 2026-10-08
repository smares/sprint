<?php

use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('digest:send')->weekdays()->dailyAt(config('sprint.digest_time'))->onOneServer()->withoutOverlapping();

Schedule::command('notifications:prune')->dailyAt('03:15')->onOneServer();

Schedule::command('sprint:backup')->dailyAt(config('sprint.backup.time'))->onOneServer()->withoutOverlapping()->when(fn () => config('sprint.backup.enabled'));

Schedule::call(fn () => Cache::put(HealthCheckService::SCHEDULER_HEARTBEAT, now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');
