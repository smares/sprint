<?php

namespace App;

enum RepeatMode: string
{
    /** The next task is due one interval after the last due date, whenever the last one was finished. */
    case Schedule = 'schedule';

    /** The next task is due one interval after the day the last one was finished. */
    case Completion = 'completion';

    public function label(): string
    {
        return match ($this) {
            self::Schedule => 'nach Plan',
            self::Completion => 'nach Erledigung',
        };
    }
}
