<?php

use App\HealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('digest:send')->weekdays()->dailyAt(config('sprint.digest_time'));

Schedule::call(fn () => Cache::put(HealthCheck::SCHEDULER_HEARTBEAT, now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');
