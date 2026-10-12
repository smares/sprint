<?php

namespace App\Services;

/**
 * Push notifications to browsers and phones (Web Push): they work once the installation has its VAPID key pair
 * (`php artisan webpush:vapid`, see docs/configuration.md) and PHP the gmp or bcmath extension for the encryption.
 */
class PushService
{
    /**
     * The push services of the browsers (Firebase for Chrome and most others, Firefox, Safari, Edge on Windows): the server sends
     * to the address a browser hands over, so only these hosts (and their subdomains) are accepted, never an address inside the
     * network or another service of the same company.
     */
    private const array HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'jmt17.google.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    /** At most this many devices per person get push notifications; turning it on for another one drops the one set up longest ago. */
    public const int MAX_DEVICES = 10;

    /** A browser's public key for the encryption (an uncompressed P-256 point) and the secret shared with it, in bytes. */
    private const int PUBLIC_KEY_BYTES = 65;

    private const int AUTH_TOKEN_BYTES = 16;

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

    /**
     * Whether the keys a browser handed over (base64url, as PushSubscription.toJSON() gives them) have the right size.
     */
    public static function hasValidKeys(string $publicKey, string $authToken): bool
    {
        $decode = fn (string $value) => preg_match('/^[A-Za-z0-9_\-+\/]+={0,2}$/', $value) === 1 ? base64_decode(strtr($value, '-_', '+/'), true) : false;
        $key = $decode($publicKey);
        $token = $decode($authToken);

        return is_string($key) && strlen($key) === self::PUBLIC_KEY_BYTES && $key[0] === "\x04"
            && is_string($token) && strlen($token) === self::AUTH_TOKEN_BYTES;
    }
}
