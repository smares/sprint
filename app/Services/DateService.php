<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Dates coming from addresses, forms and agents are always YYYY-MM-DD; this is the one place that reads them.
 */
class DateService
{
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
}
