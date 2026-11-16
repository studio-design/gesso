<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use RuntimeException;

use function app;
use function function_exists;
use function is_string;

/**
 * @internal Laravel adapter plumbing; not a consumer API.
 */
final class TestCookieDecrypter
{
    private function __construct() {}

    /**
     * Laravel's `withCookie()` encrypts each value the way the
     * {@see EncryptCookies} middleware expects, so the pre-dispatch request
     * the adapter validates holds ciphertext. Decrypt each value as that
     * middleware does, so cookie parameters see what the application sees. A
     * value this application did not encrypt (`withUnencryptedCookie()`) is
     * kept as sent.
     *
     * @param array<array-key, mixed> $cookies
     *
     * @return array<array-key, mixed>
     */
    public static function decrypt(array $cookies): array
    {
        if (!function_exists('app') || !app()->bound('encrypter')) {
            return $cookies;
        }

        try {
            // Resolving throws MissingAppKeyException without an APP_KEY.
            $encrypter = app('encrypter');
        } catch (RuntimeException) {
            return $cookies;
        }
        if (!$encrypter instanceof Encrypter) {
            return $cookies;
        }

        foreach ($cookies as $name => $value) {
            if (!is_string($value)) {
                continue;
            }

            try {
                $plain = $encrypter->decrypt($value, EncryptCookies::serialized((string) $name));
            } catch (RuntimeException) {
                // DecryptException: not ciphertext from this application.
                continue;
            }

            $plain = is_string($plain) ? CookieValuePrefix::validate((string) $name, $plain, $encrypter->getAllKeys()) : null;
            if ($plain !== null) {
                $cookies[$name] = $plain;
            }
        }

        return $cookies;
    }
}
