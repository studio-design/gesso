<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use function count;
use function explode;
use function str_ends_with;
use function strstr;
use function strtolower;
use function trim;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ContentTypeMatcher
{
    /** JSON decoding policy shared by request and response adapters. */
    public static function isJsonOrUnspecified(?string $contentType): bool
    {
        $normalized = self::normalizeMediaType($contentType ?? '');

        return $normalized === '' || self::isJsonContentType($normalized);
    }

    /**
     * Find the first JSON-compatible content type key in the spec's
     * `content` map.
     *
     * Resolution priority (most-specific first):
     *   1. Literal `application/json` or any `+json` structured-syntax suffix (RFC 6838)
     *   2. `application/*` — the only range that can plausibly carry JSON
     *
     * `*&#47;*` and other `<type>/*` ranges (`text/*`, `image/*`, `multipart/*`)
     * are intentionally NOT returned: they cover non-JSON media types, and
     * routing through JSON schema validation against them would re-introduce
     * the silent-pass class this method is meant to eliminate. Callers that
     * need general content-type matching (not JSON-specific) should use
     * {@see findContentTypeKey()} instead.
     *
     * Matching is case-insensitive.
     *
     * @param array<string, mixed> $content
     */
    public static function findJsonContentType(array $content): ?string
    {
        // Pass 1: literal JSON keys.
        foreach ($content as $contentType => $_mediaType) {
            if (self::isJsonContentType(strtolower((string) $contentType))) {
                return (string) $contentType;
            }
        }

        // Pass 2: only `application/*` — `text/*`, `image/*`, `multipart/*`,
        // etc. cannot plausibly hold a JSON body, and `*&#47;*` is too broad.
        foreach ($content as $contentType => $_mediaType) {
            if (strtolower((string) $contentType) === 'application/*') {
                return (string) $contentType;
            }
        }

        return null;
    }

    /**
     * Find the JSON-compatible content type key that best matches a given
     * actual response Content-Type. Behaves like {@see findJsonContentType()}
     * but prefers an exact spec key match before falling back to the first
     * JSON key in the spec.
     *
     * Resolution priority (most-specific first):
     *   1. Exact case-insensitive match between the response Content-Type
     *      and a JSON-flavoured spec key (e.g. response
     *      `application/problem+json` → spec key `application/problem+json`)
     *   2. {@see findJsonContentType()} fallback (first JSON key, then
     *      `application/*`)
     *
     * The exact-match-first preference is what allows multi-JSON specs
     * (e.g. `application/json` AND `application/problem+json` for the same
     * status) to validate each Content-Type against its own schema. Without
     * it, a problem-details body would be wrongly judged against the first
     * key's success-shape schema.
     *
     * `$normalizedResponseType` must be the response Content-Type stripped
     * of parameters and lower-cased, as produced by
     * {@see normalizeMediaType()}. Callers that pass non-JSON types should
     * route to {@see findContentTypeKey()} instead — this method is only
     * useful when the actual Content-Type is JSON-flavoured.
     *
     * @param array<string, mixed> $content
     */
    public static function findJsonContentTypeForResponse(
        string $normalizedResponseType,
        array $content,
    ): ?string {
        foreach ($content as $contentType => $_mediaType) {
            if (strtolower((string) $contentType) === $normalizedResponseType) {
                return (string) $contentType;
            }
        }

        return self::findJsonContentType($content);
    }

    /**
     * Extract the media type portion before any parameters (e.g. charset),
     * and return it lower-cased.
     *
     * Example: "text/html; charset=utf-8" → "text/html"
     */
    public static function normalizeMediaType(string $contentType): string
    {
        $mediaType = strstr($contentType, ';', true);

        return strtolower(trim($mediaType !== false ? $mediaType : $contentType));
    }

    /**
     * Return the spec key (with the spec author's original casing) whose
     * lower-cased form matches the given normalised content type, or null
     * when no spec key matches. Used by coverage tracking to surface the
     * spec's literal media-type keys (which may mix casings, e.g. petstore
     * declares both `Application/Problem+JSON` and `application/problem+json`).
     *
     * Resolution priority (most-specific first):
     *   1. Exact case-insensitive match
     *   2. `<type>/*` range matching `<type>/<subtype>` (e.g. `application/*`)
     *   3. `*&#47;*` full wildcard
     *
     * @param array<string, mixed> $content
     */
    public static function findContentTypeKey(string $normalizedContentType, array $content): ?string
    {
        // Pass 1: exact match.
        foreach ($content as $specContentType => $_mediaType) {
            if (strtolower((string) $specContentType) === $normalizedContentType) {
                return (string) $specContentType;
            }
        }

        // Pass 2: type-range match (`application/*` for `application/json`).
        $type = self::primaryType($normalizedContentType);
        if ($type !== null) {
            $rangeKey = $type . '/*';
            foreach ($content as $specContentType => $_mediaType) {
                if (strtolower((string) $specContentType) === $rangeKey) {
                    return (string) $specContentType;
                }
            }
        }

        // Pass 3: full wildcard.
        foreach ($content as $specContentType => $_mediaType) {
            if (strtolower((string) $specContentType) === '*/*') {
                return (string) $specContentType;
            }
        }

        return null;
    }

    /**
     * True for "application/json" or any "+json" structured syntax suffix (RFC 6838).
     * Expects a lower-cased media type without parameters.
     *
     * This is intentionally a literal check — wildcards are NOT JSON. The
     * actual response/request Content-Type passed by the caller is always a
     * concrete media type, so a wildcard here would be a category error.
     */
    public static function isJsonContentType(string $lowerContentType): bool
    {
        return $lowerContentType === 'application/json' || str_ends_with($lowerContentType, '+json');
    }

    /**
     * Extract the type half ("application" from "application/json"), or null
     * if the input is malformed (no slash, empty type half, etc.).
     */
    private static function primaryType(string $normalized): ?string
    {
        $parts = explode('/', $normalized, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[0] === '*') {
            return null;
        }

        return $parts[0];
    }
}
