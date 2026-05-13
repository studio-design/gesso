<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

/**
 * Lookup result returned by {@see StrictRequiredSchemaAnalysis::lookup()}
 * when the observed pointer resolves to a walkable schema node. Carries
 * the union of `required` arrays at that node (with `allOf` branches
 * unioned).
 *
 * Companion of {@see StrictRequiredDisjunctionMatch}. Caller code that
 * diffs observed keys against the schema's required set should branch on
 * `instanceof` of either variant — the union return type on `lookup()`
 * (`StrictRequiredKnownRequired|StrictRequiredDisjunctionMatch`) makes
 * PHPStan exhaustively check the discriminator.
 *
 * @internal Lookup variants are not part of the SemVer-frozen public API.
 */
final class StrictRequiredKnownRequired
{
    /**
     * @param list<string> $required schema-declared `required` keys at the
     *                               matching pointer; empty list when the
     *                               schema node declares no `required`
     */
    public function __construct(
        public readonly array $required,
    ) {}
}
