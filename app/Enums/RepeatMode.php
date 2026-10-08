<?php

namespace App\Enums;

enum RepeatMode: string
{
    /** The next task is due one interval after the last due date, whenever the last one was finished. */
    case Schedule = 'schedule';

    /** The next task is due one interval after the day the last one was finished. */
    case Completion = 'completion';

    public function label(): string
    {
        return match ($this) {
            self::Schedule => __('on schedule'),
            self::Completion => __('after completion'),
        };
    }
}
