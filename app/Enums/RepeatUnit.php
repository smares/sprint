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
     * The date that is the given number of units later; month ends are kept (31 January + 1 month = 28 February).
     */
    public function addTo(CarbonInterface $date, int $interval): CarbonInterface
    {
        $date = $date->copy();

        return match ($this) {
            self::Day => $date->addDays($interval),
            self::Week => $date->addWeeks($interval),
            self::Month => $date->addMonthsNoOverflow($interval),
            self::Year => $date->addYearsNoOverflow($interval),
        };
    }
}
