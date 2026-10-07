<?php

namespace App;

enum CustomFieldType: string
{
    case Select = 'select';
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';

    public function label(): string
    {
        return match ($this) {
            self::Select => __('Selection'),
            self::Text => __('Text'),
            self::Number => __('Number'),
            self::Date => __('Date'),
        };
    }
}
