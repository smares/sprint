<?php

namespace App\Enums;

/**
 * What a person wants behind the pages (see resources/js/ambient.js), chosen in the user menu like the appearance.
 */
enum AmbientBackground: string
{
    case Animated = 'animated';
    case Still = 'still';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Animated => __('Animated'),
            self::Still => __('Still'),
            self::Off => __('Off'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Animated => 'sparkles',
            self::Still => 'photo',
            self::Off => 'eye-slash',
        };
    }
}
