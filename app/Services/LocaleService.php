<?php

namespace App\Services;

use Illuminate\Support\Facades\Date;

/**
 * The languages of the application, as two-letter codes (de, en, fr, …). A new language needs a `lang/xx.json`
 * and `lang/xx/` files, a folder `resources/views/mail/xx` and an entry in `config/sprint.php`.
 */
class LocaleService
{
    /**
     * Code => name in the language itself.
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        return config('sprint.locales');
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::available());
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::available());
    }

    /**
     * The best supported language from an `Accept-Language` header, if any.
     */
    public static function fromHeader(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            [$tag, $quality] = array_pad(explode(';q=', trim($part)), 2, '1');
            $candidates[strtolower(substr(trim($tag), 0, 2))] = max($candidates[strtolower(substr(trim($tag), 0, 2))] ?? 0, (float) $quality);
        }

        arsort($candidates);

        foreach (array_keys($candidates) as $code) {
            if (self::isSupported($code)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Use the language for everything that follows in this request, including dates.
     */
    public static function apply(string $code): void
    {
        app()->setLocale($code);
        Date::setLocale($code);
    }
}
