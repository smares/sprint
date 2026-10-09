<?php

namespace App\Services;

/**
 * Push notifications to browsers and phones (Web Push): they work once the installation has its VAPID key pair
 * (`php artisan webpush:vapid`, see docs/configuration.md) and PHP the gmp or bcmath extension for the encryption.
 */
class PushService
{
    /**
     * The push services of the browsers (Google for Chrome and most others, Firefox, Safari, Edge on Windows): the server sends to
     * the address a browser hands over, so only these hosts are accepted, never an address inside the network.
     */
    private const array HOSTS = ['googleapis.com', 'google.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    public static function configured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'))
            && (extension_loaded('gmp') || extension_loaded('bcmath'));
    }

    /**
     * The public key the browser needs to subscribe; it is public by design.
     */
    public static function publicKey(): string
    {
        return (string) config('webpush.vapid.public_key');
    }

    /**
     * Whether the address a browser handed over belongs to one of the known push services.
     */
    public static function isPushServiceEndpoint(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST);

        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || ! is_string($host)) {
            return false;
        }

        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
