<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

/**
 * One spec-shape problem found in a `schemeName => scopes` pair of a security
 * requirement entry, as reported by
 * {@see SecurityValidator::inspectRequirementPair()}.
 *
 * Deliberately carries the *kind* plus its interpolation data rather than a
 * finished sentence. The runtime validator must keep emitting its
 * `[security]`-prefixed strings verbatim — ADR 0001 pins the identity-neutral
 * diagnostic inventory down to the per-file literal count — while the fuzz-side
 * `ignored_auth` probe wants its own wording. Sharing the detection rules and
 * splitting the prose lets both hold.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class SecurityRequirementDefect
{
    public const SCHEME_NAME_NOT_STRING = 'scheme-name-not-string';
    public const SCOPES_NOT_LIST = 'scopes-not-list';
    public const SCOPE_NOT_STRING = 'scope-not-string';
    public const UNDEFINED_SCHEME = 'undefined-scheme';
    public const MALFORMED_DEFINITION = 'malformed-definition';

    /**
     * @param string $kind one of the class constants
     * @param null|string $schemeName null only for {@see self::SCHEME_NAME_NOT_STRING},
     *                                where the name is not a usable string
     * @param string $actualType `get_debug_type()` of the offending node; empty
     *                           for kinds that have no offending value
     * @param null|int $scopeIndex populated for {@see self::SCOPE_NOT_STRING}
     * @param string $reason populated for {@see self::MALFORMED_DEFINITION}
     */
    private function __construct(
        public string $kind,
        public ?string $schemeName,
        public string $actualType = '',
        public ?int $scopeIndex = null,
        public string $reason = '',
    ) {}

    public static function schemeNameNotString(string $actualType): self
    {
        return new self(self::SCHEME_NAME_NOT_STRING, null, actualType: $actualType);
    }

    public static function scopesNotList(string $schemeName, string $actualType): self
    {
        return new self(self::SCOPES_NOT_LIST, $schemeName, actualType: $actualType);
    }

    public static function scopeNotString(string $schemeName, int $scopeIndex, string $actualType): self
    {
        return new self(self::SCOPE_NOT_STRING, $schemeName, actualType: $actualType, scopeIndex: $scopeIndex);
    }

    public static function undefinedScheme(string $schemeName): self
    {
        return new self(self::UNDEFINED_SCHEME, $schemeName);
    }

    public static function malformedDefinition(string $schemeName, string $reason): self
    {
        return new self(self::MALFORMED_DEFINITION, $schemeName, reason: $reason);
    }
}
