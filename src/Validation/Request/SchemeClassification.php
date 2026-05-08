<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

/**
 * Immutable result of classifying a security scheme definition.
 *
 * Construct only via the named factories — `bearer()` / `apiKey()` /
 * `malformed()` / `unsupported()` — so the kind-discriminated payload
 * invariant is enforced in code rather than maintained by convention:
 *
 * - `Malformed`   → `$reason` populated, all other payload fields null.
 *   `$reason` is a human-readable explanation pinpointing the broken
 *   spec field.
 * - `Unsupported` → `$unsupportedTypeLabel` populated, all other payload fields null.
 *   `$unsupportedTypeLabel` is the display label used in the silent-pass
 *   warning (e.g. `OAuth2`, `OpenID Connect`, `Mutual TLS`, `http-basic`,
 *   `http-digest`).
 * - `Bearer`     → all payload fields null.
 *   The bearer scheme has no per-spec parameters worth carrying forward;
 *   its location is fixed at "Authorization: Bearer …".
 * - `ApiKey`     → `$apiKeyIn` and `$apiKeyName` populated, others null.
 *   `$apiKeyIn` is the validated location (`'header'|'query'|'cookie'`);
 *   `$apiKeyName` is the spec-declared parameter name. Carrying these on
 *   the classification means downstream consumers do not have to re-read
 *   and re-validate the raw `$schemeDef` array — single source of truth
 *   for "what does this scheme look like?".
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class SchemeClassification
{
    private function __construct(
        public SchemeKind $kind,
        public ?string $reason = null,
        public ?string $unsupportedTypeLabel = null,
        public ?string $apiKeyIn = null,
        public ?string $apiKeyName = null,
    ) {}

    public static function bearer(): self
    {
        return new self(SchemeKind::Bearer);
    }

    /**
     * @param 'cookie'|'header'|'query' $in already-validated apiKey location
     * @param string $name spec-declared parameter name
     */
    public static function apiKey(string $in, string $name): self
    {
        return new self(SchemeKind::ApiKey, apiKeyIn: $in, apiKeyName: $name);
    }

    public static function malformed(string $reason): self
    {
        return new self(SchemeKind::Malformed, reason: $reason);
    }

    public static function unsupported(string $typeLabel): self
    {
        return new self(SchemeKind::Unsupported, unsupportedTypeLabel: $typeLabel);
    }
}
