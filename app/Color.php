<?php

namespace App;

/**
 * The colors of tags, statuses and field options. They are stored as `#rrggbb`; the color names
 * used before the color picker existed are still understood and mapped to the same shades.
 */
class Color
{
    public const FALLBACK = '#71717a';

    /**
     * Swatches offered in the color picker, as [hex, label].
     *
     * @var list<array{0: string, 1: string}>
     */
    public const SWATCHES = [
        ['#71717a', 'Gray'],
        ['#ef4444', 'Red'],
        ['#f97316', 'Orange'],
        ['#f59e0b', 'Amber'],
        ['#84cc16', 'Lime'],
        ['#22c55e', 'Green'],
        ['#14b8a6', 'Turquoise'],
        ['#0ea5e9', 'Sky blue'],
        ['#3b82f6', 'Blue'],
        ['#6366f1', 'Indigo'],
        ['#a855f7', 'Violet'],
        ['#ec4899', 'Pink'],
    ];

    /**
     * The swatches with their names in the current language, for the color pickers.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function swatches(): array
    {
        return array_map(fn (array $swatch) => [$swatch[0], __($swatch[1])], self::SWATCHES);
    }

    /**
     * @var array<string, string>
     */
    private const array LEGACY = [
        'zinc' => '#71717a',
        'red' => '#ef4444',
        'orange' => '#f97316',
        'amber' => '#f59e0b',
        'lime' => '#84cc16',
        'green' => '#22c55e',
        'teal' => '#14b8a6',
        'sky' => '#0ea5e9',
        'blue' => '#3b82f6',
        'indigo' => '#6366f1',
        'purple' => '#a855f7',
        'pink' => '#ec4899',
    ];

    /**
     * @return list<string>
     */
    public static function hexes(): array
    {
        return array_column(self::SWATCHES, 0);
    }

    /**
     * The next palette color, so new items get varied colors.
     */
    public static function next(int $index): string
    {
        $hexes = self::hexes();

        return $hexes[$index % count($hexes)];
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * A usable `#rrggbb` for anything stored: hex, a legacy color name, or the fallback.
     */
    public static function normalize(mixed $value): string
    {
        if (self::isHex($value)) {
            return strtolower($value);
        }

        return self::LEGACY[strtolower((string) $value)] ?? self::FALLBACK;
    }
}
