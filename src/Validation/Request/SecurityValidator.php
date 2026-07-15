<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use const E_USER_WARNING;

use Studio\Gesso\Validation\Support\HeaderNormalizer;

use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;
use function trigger_error;
use function trim;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SecurityValidator
{
    /** @var array<string, true> */
    private static array $warnedSchemeNames = [];

    /**
     * Reset the per-process "already-warned" set used by
     * {@see warnIfFirstEncounter()}. Test seam — production code never needs
     * this. The convention mirrors {@see OpenApiSchemaConverter}'s same-named
     * method so test setUp/tearDown patterns stay symmetric across the codebase.
     *
     * @internal
     */
    public static function resetWarningStateForTesting(): void
    {
        self::$warnedSchemeNames = [];
    }

    /**
     * Classify a security scheme definition. {@see SchemeKind} documents the
     * four outcomes; the `$reason` field of the returned classification is
     * populated only for `Malformed` to explain which spec field is broken.
     *
     * The single source of truth for "what does this scheme look like?" —
     * other components within the package read the result rather than
     * re-implementing the rules so the auto-inject side and the validate
     * side cannot drift apart.
     *
     * @param array<string, mixed> $schemeDef
     *
     * @internal Not part of the package's public API. Do not use from user code.
     */
    public static function classifyScheme(array $schemeDef): SchemeClassification
    {
        $type = $schemeDef['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return SchemeClassification::malformed("missing required 'type' field.");
        }

        if ($type === 'apiKey') {
            $in = $schemeDef['in'] ?? null;
            $name = $schemeDef['name'] ?? null;
            if (!is_string($in) || !is_string($name)) {
                return SchemeClassification::malformed("apiKey scheme requires string 'in' and 'name' fields.");
            }
            if (!in_array($in, ['header', 'query', 'cookie'], true)) {
                return SchemeClassification::malformed("apiKey scheme 'in' must be one of header|query|cookie, got '{$in}'.");
            }

            return SchemeClassification::apiKey($in, $name);
        }

        if ($type === 'http') {
            $scheme = $schemeDef['scheme'] ?? null;
            if (!is_string($scheme) || trim($scheme) === '') {
                // Reject empty / whitespace-only `scheme` as malformed: the
                // OAS spec requires the field to name an HTTP authentication
                // scheme (e.g. "bearer", "basic"), and an empty value would
                // otherwise fall through to Unsupported with a meaningless
                // label like "http-".
                return SchemeClassification::malformed("http scheme requires a non-empty string 'scheme' field (e.g. 'bearer', 'basic').");
            }

            if (strtolower($scheme) === 'bearer') {
                return SchemeClassification::bearer();
            }

            // http + basic / digest / etc. are well-formed but the validator
            // cannot verify them. Label normalises arbitrary scheme names to
            // `http-<lowercased>` (RFC 7235 schemes are case-insensitive).
            return SchemeClassification::unsupported('http-' . strtolower($scheme));
        }

        if ($type === 'oauth2') {
            return SchemeClassification::unsupported('OAuth2');
        }
        if ($type === 'openIdConnect') {
            return SchemeClassification::unsupported('OpenID Connect');
        }
        if ($type === 'mutualTLS') {
            return SchemeClassification::unsupported('Mutual TLS');
        }

        return SchemeClassification::malformed(
            "unknown type '{$type}' — OpenAPI 3.x enumerates apiKey|http|oauth2|openIdConnect|mutualTLS.",
        );
    }

    /**
     * Validate the endpoint's `security` requirement against the incoming
     * request. The supported / unsupported / malformed scheme partition is
     * defined by {@see classifyScheme()}; entries containing an unsupported
     * scheme are skipped (contributes neither pass nor fail) and emit a
     * one-shot per-scheme-name {@see E_USER_WARNING} so the silent pass does
     * not stay invisible. Malformed entries are always surfaced as hard
     * errors so the spec author is pushed to fix the broken declaration.
     *
     * Resolution: operation-level `security` takes precedence; otherwise the
     * root-level `security` is inherited. `security: []` (empty array)
     * explicitly opts out of all authentication. Missing `security` on both
     * levels also returns `[]` (no authentication required).
     *
     * OR / AND semantics (per OpenAPI 3.x):
     * - Multiple entries in the `security` array → OR (any one satisfied is enough)
     * - Multiple schemes within a single entry object → AND (all must be satisfied)
     *
     * @param array<string, mixed> $spec full spec root (for `components.securitySchemes` + root-level `security`)
     * @param array<string, mixed> $operation operation spec (for operation-level `security`)
     * @param array<array-key, mixed> $headers caller-supplied request headers
     * @param array<string, mixed> $queryParams parsed query string
     * @param array<string, mixed> $cookies request cookies (for `apiKey` with `in: cookie`)
     *
     * @return string[]
     */
    public function validate(
        string $method,
        string $matchedPath,
        array $spec,
        array $operation,
        array $headers,
        array $queryParams,
        array $cookies,
    ): array {
        $security = array_key_exists('security', $operation)
            ? $operation['security']
            : ($spec['security'] ?? null);

        if ($security === null) {
            return [];
        }

        if (!is_array($security) || !array_is_list($security)) {
            return [
                self::formatError(
                    $method,
                    $matchedPath,
                    sprintf(
                        'operation/root-level `security` must be a list of requirement objects, got %s.',
                        get_debug_type($security),
                    ),
                ),
            ];
        }

        if ($security === []) {
            return [];
        }

        $schemes = $spec['components']['securitySchemes'] ?? [];
        // A non-array `components.securitySchemes` is a malformed spec — without
        // this hard error, every scheme reference below would be reported as
        // "undefined scheme … add it under components.securitySchemes", which
        // misdirects the spec author away from the real cause.
        if (!is_array($schemes)) {
            return [
                sprintf(
                    '[security] %s %s: components.securitySchemes must be an object mapping scheme names to definitions, got %s.',
                    $method,
                    $matchedPath,
                    get_debug_type($schemes),
                ),
            ];
        }

        $normalizedHeaders = HeaderNormalizer::normalize($headers);

        $hardErrors = [];
        $failureErrors = [];
        $satisfied = false;

        foreach ($security as $entryIndex => $entry) {
            if (!is_array($entry)) {
                $hardErrors[] = sprintf(
                    '[security] %s %s: security requirement at index %d must be an object mapping scheme names to scope arrays, got %s.',
                    $method,
                    $matchedPath,
                    $entryIndex,
                    get_debug_type($entry),
                );

                continue;
            }

            $entryHasHardError = false;
            $entryHasUnsupported = false;
            /** @var array<string, array{kind: SchemeKind, def: array<string, mixed>}> $validatable */
            $validatable = [];

            foreach ($entry as $schemeName => $scopes) {
                if (!is_string($schemeName)) {
                    $hardErrors[] = sprintf(
                        '[security] %s %s: security scheme name must be a string, got %s.',
                        $method,
                        $matchedPath,
                        get_debug_type($schemeName),
                    );
                    $entryHasHardError = true;

                    continue;
                }

                if (!is_array($scopes) || !array_is_list($scopes)) {
                    $hardErrors[] = self::formatError(
                        $method,
                        $matchedPath,
                        sprintf(
                            "security requirement '%s' scopes must be a list of strings, got %s.",
                            $schemeName,
                            get_debug_type($scopes),
                        ),
                    );
                    $entryHasHardError = true;

                    continue;
                }

                $scopeHasHardError = false;
                foreach ($scopes as $scopeIndex => $scope) {
                    if (is_string($scope)) {
                        continue;
                    }

                    $hardErrors[] = self::formatError(
                        $method,
                        $matchedPath,
                        sprintf(
                            "security requirement '%s' scope at index %d must be a string, got %s.",
                            $schemeName,
                            $scopeIndex,
                            get_debug_type($scope),
                        ),
                    );
                    $entryHasHardError = true;
                    $scopeHasHardError = true;
                }

                if ($scopeHasHardError) {
                    continue;
                }

                $schemeDef = $schemes[$schemeName] ?? null;
                if (!is_array($schemeDef)) {
                    $hardErrors[] = sprintf(
                        "[security] %s %s: security requirement references undefined scheme '%s' — add it under components.securitySchemes.",
                        $method,
                        $matchedPath,
                        $schemeName,
                    );
                    $entryHasHardError = true;

                    continue;
                }

                $classification = self::classifyScheme($schemeDef);

                if ($classification->kind === SchemeKind::Malformed) {
                    $hardErrors[] = sprintf(
                        "[security] %s %s: security scheme '%s' is malformed: %s",
                        $method,
                        $matchedPath,
                        $schemeName,
                        $classification->reason,
                    );
                    $entryHasHardError = true;

                    continue;
                }

                if ($classification->kind === SchemeKind::Unsupported) {
                    /** @var string $typeLabel */
                    $typeLabel = $classification->unsupportedTypeLabel;
                    self::warnIfFirstEncounter($schemeName, $typeLabel, $method, $matchedPath);
                    $entryHasUnsupported = true;

                    continue;
                }

                $validatable[$schemeName] = ['kind' => $classification->kind, 'def' => $schemeDef];
            }

            if ($entryHasHardError) {
                continue;
            }

            if ($entryHasUnsupported) {
                continue;
            }

            $entryFailures = [];
            foreach ($validatable as $schemeName => $info) {
                $schemeErrors = $this->checkSchemeSatisfaction(
                    $info['kind'],
                    $info['def'],
                    $normalizedHeaders,
                    $queryParams,
                    $cookies,
                );
                foreach ($schemeErrors as $schemeError) {
                    $entryFailures[] = sprintf(
                        "[security] %s %s: requirement '%s' not satisfied: %s",
                        $method,
                        $matchedPath,
                        $schemeName,
                        $schemeError,
                    );
                }
            }

            if ($entryFailures === []) {
                $satisfied = true;

                break;
            }

            $failureErrors = [...$failureErrors, ...$entryFailures];
        }

        if ($satisfied) {
            return $hardErrors;
        }

        // If every requirement entry was either skipped (unsupported scheme
        // within) or malformed (already captured in $hardErrors), there is no
        // *validatable* entry that failed. Returning early avoids blocking a
        // test for a spec we fundamentally cannot evaluate (false-negative
        // avoidance for oauth2-only endpoints).
        if ($failureErrors === []) {
            return $hardErrors;
        }

        return [...$hardErrors, ...$failureErrors];
    }

    private static function formatError(string $method, string $matchedPath, string $detail): string
    {
        return sprintf('[security] %s %s: %s', $method, $matchedPath, $detail);
    }

    /**
     * Issue a one-shot E_USER_WARNING for security schemes the validator
     * cannot enforce (`oauth2`, `openIdConnect`, `mutualTLS`,
     * `http` + non-`bearer`). Without this, requirement entries containing
     * only unsupported schemes silently pass — a green test against an
     * unauthenticated request misleads users into thinking the endpoint is
     * actually verified.
     *
     * Dedup is per `components.securitySchemes` key, not per type: a spec
     * that defines both `oauth2_user` and `oauth2_admin` will warn twice (once
     * each), so users can see how many silent-pass schemes exist in their spec.
     */
    private static function warnIfFirstEncounter(
        string $schemeName,
        string $typeLabel,
        string $method,
        string $matchedPath,
    ): void {
        if (isset(self::$warnedSchemeNames[$schemeName])) {
            return;
        }

        self::$warnedSchemeNames[$schemeName] = true;
        trigger_error(
            sprintf(
                "[security] %s scheme '%s' is silently passed (no token check) — %s %s. "
                    . 'The opis/json-schema-based validator cannot verify oauth2 / openIdConnect / '
                    . 'mutualTLS / http-basic / http-digest credentials. Your test will not detect '
                    . 'a missing or invalid token. Workaround: split the bearer-token surface into '
                    . 'a separate test, or assert the Authorization header presence manually.',
                $typeLabel,
                $schemeName,
                $method,
                $matchedPath,
            ),
            E_USER_WARNING,
        );
    }

    /**
     * Check whether a single (already-classified, well-formed) security scheme
     * is satisfied by the request. Returns an empty list when satisfied, or
     * one or more error strings explaining why not.
     *
     * Only `Bearer` and `ApiKey` reach this method — `Malformed` and
     * `Unsupported` classifications are short-circuited by the caller in
     * {@see SecurityValidator::validate()} — so the `match` is exhaustive on
     * the two validatable cases.
     *
     * @param array<string, mixed> $schemeDef
     * @param array<string, mixed> $normalizedHeaders lower-cased header map
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $cookies
     *
     * @return string[]
     */
    private function checkSchemeSatisfaction(
        SchemeKind $kind,
        array $schemeDef,
        array $normalizedHeaders,
        array $queryParams,
        array $cookies,
    ): array {
        return match ($kind) {
            SchemeKind::Bearer => $this->checkBearerSatisfied($normalizedHeaders),
            SchemeKind::ApiKey => $this->checkApiKeySatisfied($schemeDef, $normalizedHeaders, $queryParams, $cookies),
            SchemeKind::Malformed, SchemeKind::Unsupported => [],
        };
    }

    /**
     * @param array<string, mixed> $normalizedHeaders
     *
     * @return string[]
     */
    private function checkBearerSatisfied(array $normalizedHeaders): array
    {
        $raw = $normalizedHeaders['authorization'] ?? null;
        $value = $this->extractSingleStringValue($raw);
        if ($value === null || $value === '') {
            return ['Authorization header is missing.'];
        }

        // RFC 6750 §2.1: `Bearer <token>`. Scheme name is case-insensitive
        // per RFC 7235 §2.1, so we accept "Bearer" / "bearer" / "BEARER" etc.
        // Require a non-empty token portion; "Bearer" alone or "Bearer " is
        // not a valid credential.
        if (preg_match('/^bearer\s+(\S+)/i', $value) !== 1) {
            return ["Authorization header does not contain a 'Bearer <token>' credential."];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $schemeDef
     * @param array<string, mixed> $normalizedHeaders
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $cookies
     *
     * @return string[]
     */
    private function checkApiKeySatisfied(
        array $schemeDef,
        array $normalizedHeaders,
        array $queryParams,
        array $cookies,
    ): array {
        /** @var string $in */
        $in = $schemeDef['in'];
        /** @var string $name */
        $name = $schemeDef['name'];

        $raw = match ($in) {
            'header' => $normalizedHeaders[strtolower($name)] ?? null,
            'query' => $queryParams[$name] ?? null,
            'cookie' => $cookies[$name] ?? null,
            default => null,
        };

        $value = $this->extractSingleStringValue($raw);
        if ($value === null || $value === '') {
            return [sprintf("api key '%s' is missing from the %s.", $name, $in)];
        }

        return [];
    }

    /**
     * Return the first element of an array, the value itself if it's a string,
     * or `null` otherwise (absent, empty array, or non-string scalar like int
     * or bool). No coercion is performed — a non-string first element still
     * returns `null`.
     *
     * Unlike {@see HeaderParameterValidator} (which rejects multi-value arrays
     * as a hard error to force the spec author to pick a canonical value), the
     * security layer silently accepts the first element. Presence of a
     * credential is all the security layer checks, and duplicate headers are
     * a framework-level concern surfaced elsewhere.
     */
    private function extractSingleStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $first = $value[array_key_first($value)];

            return is_string($first) ? $first : null;
        }

        return is_string($value) ? $value : null;
    }
}
