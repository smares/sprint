<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Single sign-on with Google: whether it is on and which email domains may register (both set by administrators in the app),
 * and whether the installation has the Google credentials (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` in the environment).
 * Signing in and connecting need it on; registering also needs a domain on the list.
 */
class SsoSettings
{
    private const string ENABLED = 'sso.google.enabled';

    private const string DOMAINS = 'sso.google.domains';

    /** Domains of personal Google accounts, which have no Workspace domain (`hd`). */
    private const array PERSONAL_DOMAINS = ['gmail.com', 'googlemail.com'];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function hasCredentials(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function isEnabled(): bool
    {
        return (bool) $this->value(self::ENABLED, false);
    }

    /**
     * Whether people can sign in with Google here: on, and the credentials are there.
     */
    public function isAvailable(): bool
    {
        return $this->hasCredentials() && $this->isEnabled();
    }

    /**
     * The email domains whose Google accounts may register, in lower case.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return array_values(array_filter((array) $this->value(self::DOMAINS, []), is_string(...)));
    }

    /**
     * Whether somebody with this Google account may register. The email address must be at one of the domains, and for a
     * domain of a Google Workspace the account must belong to that Workspace (`hd`): an address at the domain that someone
     * made a personal Google account with does not count. Domains of personal accounts (gmail.com) have no `hd`.
     */
    public function allowsRegistration(string $email, ?string $workspaceDomain): bool
    {
        $domain = Str::lower(Str::afterLast($email, '@'));

        // A real address: something before the @, and the domain after the last one
        if (! str_contains($email, '@') || Str::startsWith($email, '@') || ! in_array($domain, $this->domains(), true)) {
            return false;
        }

        return $this->isAuthoritativeFor($email, $workspaceDomain);
    }

    /**
     * Whether Google decides who owns this address: the account belongs to the Workspace of its domain, or the domain is one
     * of the personal accounts (gmail.com). Only then "verified" by Google says the person really controls the address.
     */
    public function isAuthoritativeFor(string $email, ?string $workspaceDomain): bool
    {
        $domain = Str::lower(Str::afterLast($email, '@'));

        return $workspaceDomain !== null
            ? Str::lower($workspaceDomain) === $domain
            : in_array($domain, self::PERSONAL_DOMAINS, true);
    }

    /**
     * @param  list<string>  $domains
     */
    public function update(bool $enabled, array $domains): void
    {
        Setting::query()->updateOrCreate(['key' => self::ENABLED], ['value' => $enabled]);
        Setting::query()->updateOrCreate(['key' => self::DOMAINS], ['value' => array_values(array_unique(array_map(Str::lower(...), $domains)))]);

        $this->values = null;
    }

    private function value(string $key, mixed $default): mixed
    {
        $this->values ??= Setting::query()->whereIn('key', [self::ENABLED, self::DOMAINS])->pluck('value', 'key')->all();

        return $this->values[$key] ?? $default;
    }
}
