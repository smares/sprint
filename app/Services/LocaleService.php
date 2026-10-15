<?php

namespace App\Services;

use Carbon\Translator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;

/**
 * The languages of the application, as two-letter codes (de, en, fr, …). A new language needs a `lang/xx.json`
 * and `lang/xx/` files, a folder `resources/views/mail/xx` and an entry in `config/sprint.php`.
 */
class LocaleService
{
    /**
     * Language => its date format (L) where that is not YYYY-MM-DD.
     */
    private const array DATE_FORMATS = ['de' => 'DD.MM.YYYY'];

    /**
     * Carbon's wording where it reads badly: "after tomorrow", "3 days from now".
     */
    private const array RELATIVE_DAY_WORDS = [
        'en' => ['diff_before_yesterday' => 'the day before yesterday', 'diff_after_tomorrow' => 'the day after tomorrow', 'from_now' => 'in :time'],
    ];

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
     * Dates appear as YYYY-MM-DD in every language except German (DD.MM.YYYY, the way people write them there), times
     * in 24 hours everywhere; month and weekday names and relative times ("2 hours ago") stay in the person's
     * language. Changes the short formats (L, LT, LTS) that the views use with isoFormat(). Machine formats
     * (addresses, CSV, MCP, API, the datetime attribute) never go through here and stay YYYY-MM-DD.
     */
    public static function useDateFormats(): void
    {
        $global = Date::getTranslator();

        foreach (self::codes() as $code) {
            // Carbon keeps one translator for Date::setLocale() and one per language for ->locale().
            foreach ([$global, Translator::get($code)] as $translator) {
                if ($translator instanceof Translator) {
                    $translator->setMessages($code, []);
                    $formats = ['L' => self::DATE_FORMATS[$code] ?? 'YYYY-MM-DD', 'LT' => 'HH:mm', 'LTS' => 'HH:mm:ss'] + ($translator->getMessages($code)['formats'] ?? []);
                    $translator->setMessages($code, ['formats' => $formats] + (self::RELATIVE_DAY_WORDS[$code] ?? []));
                }
            }
        }
    }

    /**
     * Use the language for everything that follows in this request, including dates and numbers (file sizes).
     */
    public static function apply(string $code): void
    {
        app()->setLocale($code);
        Date::setLocale($code);
        Number::useLocale($code);
    }
}
