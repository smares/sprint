<?php

namespace App\Services;

use Carbon\Translator;
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
     * Dates appear as YYYY-MM-DD and times in 24 hours in every language; only month and weekday names
     * and relative times ("2 hours ago") stay in the person's language. Changes the short formats
     * (L, LT, LTS) that the views use with isoFormat().
     */
    public static function useIsoDateFormats(): void
    {
        $global = Date::getTranslator();

        foreach (self::codes() as $code) {
            // Carbon keeps one translator for Date::setLocale() and one per language for ->locale().
            foreach ([$global, Translator::get($code)] as $translator) {
                if ($translator instanceof Translator) {
                    $translator->setMessages($code, []);
                    $formats = ['L' => 'YYYY-MM-DD', 'LT' => 'HH:mm', 'LTS' => 'HH:mm:ss'] + ($translator->getMessages($code)['formats'] ?? []);
                    $translator->setMessages($code, ['formats' => $formats]);
                }
            }
        }
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
