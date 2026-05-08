<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use const E_USER_WARNING;

use function array_keys;
use function preg_match;
use function sprintf;
use function trigger_error;

/**
 * Maps a literal HTTP status to the spec response key declared for that
 * operation, applying the conventional three-tier OpenAPI fallback shared by
 * major tools: exact match → range key → `default`.
 *
 * Range keys are accepted in two casings only: `1XX`/`2XX`/`3XX`/`4XX`/`5XX`
 * (uppercase) or `1xx`/`2xx`/`3xx`/`4xx`/`5xx` (lowercase). Mixed-case forms
 * (`5Xx`, `5xX`) are intentionally rejected — see {@see self::resolve()}.
 *
 * Returns the spec author's literal key so coverage / error messages reflect
 * what they wrote.
 *
 * {@see self::resolve()} is pure. {@see self::warnSuspiciousKeys()} is the
 * companion side-effecting diagnostic — callers that get a `'default'`
 * fallback should invoke it so spec-author typos like `'40'` (truncated 404)
 * or `'Default'` (wrong case) sitting next to a `default` entry don't
 * silently route every unmatched status to the default schema.
 *
 * @internal
 */
final class SpecResponseKeyResolver
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $responses spec response map for the
     *                                           operation. Keys are typed as
     *                                           `array-key` because PHP coerces
     *                                           numeric-string keys (`"200"`) to
     *                                           int when used as array keys —
     *                                           this method casts them back to
     *                                           string before regex tests.
     *
     * @return null|string the matched key (`"503"`, `"5XX"`, `"default"`, ...)
     *                     or null when no rule matches
     */
    public static function resolve(string $statusCodeStr, array $responses): ?string
    {
        if (isset($responses[$statusCodeStr])) {
            return $statusCodeStr;
        }

        // Range key match — preserve the spec author's exact casing.
        if (preg_match('/^[1-5][0-9]{2}$/', $statusCodeStr) === 1) {
            $leadingDigit = $statusCodeStr[0];
            foreach (array_keys($responses) as $key) {
                $keyStr = (string) $key;
                if (preg_match('/^([1-5])(?:XX|xx)$/', $keyStr, $m) === 1 && $m[1] === $leadingDigit) {
                    return $keyStr;
                }
            }
        }

        if (isset($responses['default'])) {
            return 'default';
        }

        return null;
    }

    /**
     * Emit `E_USER_WARNING` for any response key that LOOKS like an
     * attempted spec key but doesn't satisfy the exact / range / default
     * form. Intended to be invoked by a caller that just received
     * `'default'` from {@see self::resolve()} as a fallback (i.e. the
     * literal status was neither an exact nor a range match) — the
     * warning surfaces typos that would otherwise route every unmatched
     * status to the default schema and mask the dogfood signal "your
     * spec doesn't actually cover this status".
     *
     * Both the request-side and response-side validators call this so a
     * test class with only one of the two hooks active still sees the
     * diagnostic. trigger_error fires every time, so callers with both
     * hooks active will see the warning twice per offending operation —
     * acceptable noise for a loud-by-design dogfood signal.
     *
     * @param array<array-key, mixed> $responses
     */
    public static function warnSuspiciousKeys(string $specName, string $method, string $matchedPath, array $responses): void
    {
        foreach (array_keys($responses) as $key) {
            $keyStr = (string) $key;
            if ($keyStr === 'default') {
                continue;
            }
            if (preg_match('/^[1-5][0-9]{2}$/', $keyStr) === 1) {
                continue;
            }
            if (preg_match('/^[1-5](?:XX|xx)$/', $keyStr) === 1) {
                continue;
            }

            trigger_error(
                sprintf(
                    "[OpenAPI] spec '%s' %s %s: response key '%s' is not a valid HTTP status, range key (1XX-5XX / 1xx-5xx), or 'default'; falling back to 'default' may be hiding a typo.",
                    $specName,
                    $method,
                    $matchedPath,
                    $keyStr,
                ),
                E_USER_WARNING,
            );
        }
    }
}
