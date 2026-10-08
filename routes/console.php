<?php

use App\HealthCheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('digest:send')->weekdays()->dailyAt(config('sprint.digest_time'))->onOneServer();

Schedule::call(fn () => Cache::put(HealthCheck::SCHEDULER_HEARTBEAT, now()->timestamp, now()->addDay()))->everyMinute()->name('scheduler-heartbeat');
