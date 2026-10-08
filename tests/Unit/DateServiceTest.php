<?php

namespace Tests\Unit;

use App\Services\DateService;
use PHPUnit\Framework\TestCase;

class DateServiceTest extends TestCase
{
    public function test_only_real_dates_in_iso_format_are_read(): void
    {
        $this->assertSame('2026-02-28', DateService::parseIsoDate('2026-02-28')?->toDateString());
        $this->assertSame('00:00:00', DateService::parseIsoDate('2026-02-28')?->toTimeString());

        foreach (['2026-02-30', '28.02.2026', '02/28/2026', 'friday', '2026-2-8', '', null] as $value) {
            $this->assertNull(DateService::parseIsoDate($value), (string) $value);
        }
    }
}
