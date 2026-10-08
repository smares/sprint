<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Mails are written once per language: `resources/views/mail/{locale}/{name}.blade.php` for the body and
 * `resources/views/mail/{locale}/subjects/{name}.blade.php` for the subject. A language without templates
 * falls back to English. Notifications are sent in the language of the person they go to.
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

        return (new MailMessage)->subject($subject)->markdown("mail.{$locale}.{$name}", $data);
    }
}
