<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a command that is scheduled once a day running exactly once on the days the clocks change.
 *
 * The scheduler asks "does the wall clock read 03:15?" every minute. Around a change of the clocks that goes wrong: the
 * repeated hour in autumn is two real hours long (02:30 happens twice), the missing hour in spring has no 02:30 at all,
 * and the cron library behind the scheduler even takes the first pass of the repeated hour for the hour after it (a job
 * at 03:15 also starts at 02:15, twice). onOneServer() and withoutOverlapping() do not catch this: the first locks a
 * minute for an hour, the second only a run that is still going.
 */
class ScheduleGuard
{
    /**
     * A filter for Schedule::when(): the event passes only when the clock really reads the time and the event has not
     * run on this calendar day yet. A time that does not exist on a day (02:30 in spring) is the moment the gap ends
     * (03:30), so the run is moved, not lost. Put it last, as it remembers the run when it lets it through.
     *
     * @param  string  $name  What the event is called in the cache.
     * @param  string  $time  The time of day of the event, as given to dailyAt().
     * @return Closure(): bool
     */
    public static function once(string $name, string $time): Closure
    {
        return function () use ($name, $time): bool {
            $zone = (string) config('app.schedule_timezone', config('app.timezone'));
            $now = now($zone);

            if ($now->format('H:i') !== today($zone)->setTimeFromTimeString($time)->format('H:i')) {
                return false;
            }

            return Cache::add("sprint:schedule:{$name}:{$now->toDateString()}", true, $now->copy()->addDays(2));
        };
    }
}
