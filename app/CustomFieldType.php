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
            self::Select => 'Auswahl',
            self::Text => 'Text',
            self::Number => 'Zahl',
            self::Date => 'Datum',
        };
    }
}
