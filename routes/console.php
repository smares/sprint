<?php

use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('digest:send')->weekdays()->dailyAt(config('sprint.digest_time'))->onOneServer()->withoutOverlapping();

Schedule::call(fn () => Cache::put(HealthCheckService::SCHEDULER_HEARTBEAT, now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');
