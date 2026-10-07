<?php

namespace App\Casts;

use App\Color;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<string, string>
 */
class ColorCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        return Color::normalize($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return Color::normalize($value);
    }
}
