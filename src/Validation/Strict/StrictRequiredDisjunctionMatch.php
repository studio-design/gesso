<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

/**
 * Lookup result returned by {@see StrictRequiredSchemaAnalysis::lookup()}
 * when the observed pointer falls under a schema node where descent
 * stopped — `anyOf` / `oneOf` / scalar / unwalkable root. The
 * `coveringPointer` identifies the schema-side pointer at which descent
 * stopped (empty string means "the root schema itself is unwalkable").
 *
 * Caller code MUST NOT produce "add to `required`" drift advice for these
 * pointers because `required` has no AND-semantic across disjunctions —
 * the suggestion would be actively wrong. Run-level mode surfaces these
 * via the unwalkable NOTE channel; per-call mode silently skips (no NOTE
 * channel exists for it yet).
 *
 * @internal Lookup variants are not part of the SemVer-frozen public API.
 */
final class StrictRequiredDisjunctionMatch
{
    /**
     * @param string $coveringPointer schema-side pointer at which descent
     *                                stopped; empty string means "root
     *                                schema is unwalkable"
     * @param string $reason one of `anyOf` / `oneOf` / `unwalkable`
     */
    public function __construct(
        public readonly string $coveringPointer,
        public readonly string $reason,
    ) {}
}
