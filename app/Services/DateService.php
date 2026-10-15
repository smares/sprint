<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Dates coming from addresses, forms and agents are always YYYY-MM-DD; this is the one place that reads them. It also
 * names calendar weeks the same way everywhere. Display formats of dates follow the language (see LocaleService).
 */
class DateService
{
    /**
     * Farthest distance in days from today at which a due date is told as a word or a count ("Tomorrow", "In 3 days").
     */
    public const int RELATIVE_DAYS = 7;

    /**
     * The day for a real calendar date such as 2026-02-28; null for anything else (2026-02-30, 28.02.2026, "friday").
     */
    public static function parseIsoDate(?string $value): ?Carbon
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);

        return $date instanceof Carbon && $date->toDateString() === $value ? $date : null;
    }

    /**
     * The calendar week of a day as the charts and the workload name it: "Week 42".
     */
    public static function weekLabel(CarbonInterface $day): string
    {
        return __('Week :number', ['number' => $day->isoWeek()]);
    }

    /**
     * Monday to Sunday of the week of a day: "2026-10-12 – 2026-10-18" ("12.10.2026 – 18.10.2026" in German).
     */
    public static function weekRange(CarbonInterface $day): string
    {
        return $day->copy()->startOfWeek()->isoFormat('L').' – '.$day->copy()->endOfWeek()->isoFormat('L');
    }

    /**
     * Today's calendar day where the person is (the application's time zone without a person), at midnight in that zone.
     */
    public static function today(?User $viewer = null): Carbon
    {
        return $viewer?->today() ?? today();
    }

    /**
     * A calendar day (due date, start date) as words relative to today: "Today", "Yesterday", "Tomorrow", "The day after
     * tomorrow", "In 3 days", "3 days ago" in the person's language, up to a week away; null beyond, where the date
     * itself reads better. Both days count as plain dates (compared at midnight UTC, which has no summer time), so
     * 7 days stay 7 days when clocks change in between, and the date does not move with a time zone; only "today" is
     * the one of the person.
     */
    public static function relativeLabel(CarbonInterface $day, ?User $viewer = null): ?string
    {
        $today = Carbon::parse(self::today($viewer)->toDateString(), 'UTC');
        $target = Carbon::parse($day->toDateString(), 'UTC');
        $distance = (int) round($today->diffInDays($target, false));

        if ($distance === 0) {
            return __('Today');
        }

        if (abs($distance) > self::RELATIVE_DAYS) {
            return null;
        }

        return Str::ucfirst($target->diffForHumans($today, [
            'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
            'options' => CarbonInterface::ONE_DAY_WORDS | CarbonInterface::TWO_DAY_WORDS,
            'skip' => ['week', 'month', 'year'],
        ]));
    }

    /**
     * The words of relativeLabel() for every day that has one (today and a week either way), keyed by YYYY-MM-DD. The
     * date fields look a typed-in date up here while it is not saved yet, so the logic stays in one place.
     *
     * @return array<string, string>
     */
    public static function relativeLabelsByDate(?User $viewer = null): array
    {
        $today = Carbon::parse(self::today($viewer)->toDateString(), 'UTC');
        $labels = [];

        for ($offset = -self::RELATIVE_DAYS; $offset <= self::RELATIVE_DAYS; $offset++) {
            $day = $today->copy()->addDays($offset);
            $labels[$day->toDateString()] = (string) self::relativeLabel($day, $viewer);
        }

        return $labels;
    }

    /**
     * A calendar day in full, with the weekday, in the format of the language: "Thu, 2026-10-15" or "Do., 15.10.2026".
     */
    public static function fullLabel(CarbonInterface $day): string
    {
        return $day->isoFormat('ddd, L');
    }
}
