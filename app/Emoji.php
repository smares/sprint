<?php

namespace App;

/**
 * Emojis for reactions: any single emoji is accepted (skin tones, flags, families, keycaps included), the quick picks are the ones offered first.
 */
class Emoji
{
    /** The longest emoji (a family with skin tones, a flag of a region) in bytes, with room to spare. */
    public const MAX_BYTES = 64;

    /**
     * What the reaction menu offers first, with the name for the tooltip.
     *
     * @return array<string, string>
     */
    public static function quick(): array
    {
        return [
            '👍' => __('Thumbs up'),
            '❤️' => __('Heart'),
            '🎉' => __('Celebrate'),
            '😄' => __('Laugh'),
            '👀' => __('Eyes'),
            '🙏' => __('Thanks'),
        ];
    }

    /**
     * The emoji without surrounding spaces, or null when the text is not exactly one emoji.
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > self::MAX_BYTES) {
            return null;
        }

        $modifier = '[\x{1F3FB}-\x{1F3FF}]';
        $pictograph = "\\p{Extended_Pictographic}\\x{FE0F}?{$modifier}?";
        $pattern = '/^(?:'
            .'[\x{1F1E6}-\x{1F1FF}]{2}'                                       // flag
            .'|[0-9#*]\x{FE0F}?\x{20E3}'                                     // keycap
            .'|\\x{1F3F4}[\\x{E0020}-\\x{E007E}]+\\x{E007F}'                   // flag of a region
            ."|{$pictograph}(?:\\x{200D}{$pictograph})*"                       // pictograph, joined ones (families, professions)
            .')$/u';

        return preg_match($pattern, $value) === 1 ? $value : null;
    }
}
