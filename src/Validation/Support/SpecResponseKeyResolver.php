<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use Studio\OpenApiContractTesting\OpenApiResponseValidator;

use function array_keys;
use function preg_match;

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
 * Pure function. Callers that need to surface "fell back to `default`"
 * diagnostics (e.g. {@see OpenApiResponseValidator})
 * should compare the returned key against the supplied status string and the
 * `default` key.
 *
 * @internal
 */
final class SpecResponseKeyResolver
{
    /**
     * @param array<string, mixed> $responses spec response map for the operation
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
                // PHP auto-coerces numeric string keys (e.g. "200") to int
                // when used as array keys, so cast back to string before
                // the regex. Range keys like "5XX" are non-numeric and
                // unaffected.
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
}
