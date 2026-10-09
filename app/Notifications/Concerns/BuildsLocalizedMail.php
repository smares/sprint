<?php

namespace App\Notifications\Concerns;

use App\Services\MarkdownService;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Mails are written once per language: `resources/views/mail/{locale}/{name}.blade.php` for the body and
 * `resources/views/mail/{locale}/subjects/{name}.blade.php` for the subject. A language without templates
 * falls back to English. Notifications are sent in the language of the person they go to.
 *
 * Texts in the data (names, titles, excerpts) are escaped for the Markdown body, so a name like
 * `[Click](https://…)` stays text; values under keys ending in `url` / `Url` are left as they are.
 */
trait BuildsLocalizedMail
{
    /** How often a mail that could not be sent is tried. */
    public int $tries = 3;

    /**
     * Seconds to wait before the next try.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function localizedMail(string $name, array $data = []): MailMessage
    {
        $locale = view()->exists('mail.'.app()->getLocale().".{$name}") ? app()->getLocale() : 'en';

        $subject = trim((string) preg_replace('/\s+/', ' ', view("mail.{$locale}.subjects.{$name}", $data)->render()));

        return (new MailMessage)->subject($subject)->markdown("mail.{$locale}.{$name}", self::escapeForMarkdown($data));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function escapeForMarkdown(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/(^u|U)rl$/', $key)) {
                continue;
            }

            if (is_string($value)) {
                $data[$key] = MarkdownService::escape($value);
            } elseif (is_array($value)) {
                $data[$key] = self::escapeForMarkdown($value);
            }
        }

        return $data;
    }
}
