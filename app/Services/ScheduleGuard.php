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
     * How long after its time a daily event may still start (in minutes of real time). The scheduler decides which
     * events are due once per minute, but asks their filters one after the other, so a long command that runs first
     * can push the next one past the minute change; the window lets that one through anyway. It also lets the second
     * pass of a repeated hour in autumn through, where the cache keeps it from running twice.
     */
    private const int WINDOW_MINUTES = 120;

    /**
     * A filter for Schedule::when(): the event passes only when the clock has really reached its time (not before:
     * that is the cron library's false start at 02:15 for a job at 03:15) and it has not run for this calendar day yet.
     * A time that does not exist on a day (02:30 in spring) is the moment the gap ends (03:30), so the run is moved,
     * not lost. Put it last, as it remembers the run when it lets it through.
     *
     * @param  string  $name  What the event is called in the cache.
     * @param  string  $time  The time of day of the event, as given to dailyAt() (see time()).
     * @return Closure(): bool
     */
    public static function once(string $name, string $time): Closure
    {
        return function () use ($name, $time): bool {
            $zone = (string) config('app.schedule_timezone', config('app.timezone'));
            $now = now($zone);

            // Today's slot, and yesterday's for a window that reaches over midnight
            foreach ([0, 1] as $daysBack) {
                $target = today($zone)->subDays($daysBack)->setTimeFromTimeString($time);

                if ($now->greaterThanOrEqualTo($target) && $now->lessThan($target->copy()->addMinutes(self::WINDOW_MINUTES))) {
                    return Cache::add("sprint:schedule:{$name}:{$target->toDateString()}", true, $now->copy()->addDays(2));
                }
            }

            return false;
        };
    }

    /**
     * A time of day for dailyAt() and once(), as H:i; the fallback when the value is not one (a typo in the environment
     * must not stop the scheduler or make the two read the time differently).
     */
    public static function time(mixed $value, string $fallback): string
    {
        if (is_string($value) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $match) === 1) {
            return sprintf('%02d:%s', (int) $match[1], $match[2]);
        }

        return $fallback;
    }
}
