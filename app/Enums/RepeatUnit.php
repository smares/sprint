<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum RepeatUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Day => __('Days'),
            self::Week => __('Weeks'),
            self::Month => __('Months'),
            self::Year => __('Years'),
        };
    }

    /**
     * The date that is the given number of units later. A month that is too short for the wanted day of the month
     * gets its last day (31 January + 1 month = 28 February); because the wanted day is passed along, the day comes back
     * in the next longer month (31 March) and 29 February comes back in the next leap year, instead of staying at the
     * shortened day.
     *
     * @param  int|null  $day  The wanted day of the month; the day of the date itself if omitted.
     */
    public function addTo(CarbonInterface $date, int $interval, ?int $day = null): CarbonInterface
    {
        $date = $date->copy();
        $wanted = $day ?? $date->day;

        return match ($this) {
            self::Day => $date->addDays($interval),
            self::Week => $date->addWeeks($interval),
            self::Month => $this->withWantedDay($date->day(1)->addMonths($interval), $wanted),
            self::Year => $this->withWantedDay($date->day(1)->addYears($interval), $wanted),
        };
    }

    /**
     * The date moved to the wanted day of its month, or to the last day of the month if that is shorter.
     */
    private function withWantedDay(CarbonInterface $firstOfMonth, int $day): CarbonInterface
    {
        return $firstOfMonth->day(max(1, min($day, $firstOfMonth->daysInMonth)));
    }
}
