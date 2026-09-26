<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

/**
 * Result of {@see StrictRequiredSchemaWalker::analyse()}: the parallel
 * (walked-required, disjunctions, maps) triple produced by descending an
 * OpenAPI response schema once.
 *
 * All fields are filled together by a single descent and consumed
 * together by the asserter and the per-call checker. Wrapping the triple
 * in this object enforces the "check covering stop-nodes first, then look
 * up required" rule structurally — callers go through {@see self::lookup()}
 * which returns a tagged array: `disjunction` (caller must skip / NOTE —
 * `required` has no AND-semantic across `anyOf` / `oneOf`), `map` (caller
 * must skip silently — dynamically-keyed observations are data, issue
 * #437), or `required` (caller can diff against observed keys).
 *
 * @phpstan-type Lookup array{kind: 'required', required: list<string>}|array{kind: 'map', coveringPointer: string}|array{kind: 'disjunction', coveringPointer: string, reason: string}
 *
 * @internal Returned by the schema walker; consumers are the asserter and
 *           the per-call checker.
 */
final class StrictRequiredSchemaAnalysis
{
    /**
     * @param array<string, list<string>> $walked
     * @param list<array{pointer: string, reason: string}> $disjunctions
     * @param list<string> $maps
     */
    public function __construct(
        private readonly array $walked,
        private readonly array $disjunctions,
        private readonly array $maps = [],
    ) {}

    /**
     * Resolve a single observed pointer against the schema. Always returns
     * one of the three tagged variants — never null — so callers' `kind`
     * branches are exhaustive. `coveringPointer` is the schema-side pointer
     * at which descent stopped (empty string means "the root schema itself");
     * `reason` is one of `anyOf` / `oneOf` / `unwalkable`.
     *
     * @return Lookup
     */
    public function lookup(string $pointer): array
    {
        $covering = StrictRequiredSchemaWalker::findCoveringDisjunction($pointer, $this->disjunctions);
        if ($covering !== null) {
            return ['kind' => 'disjunction', 'coveringPointer' => $covering['pointer'], 'reason' => $covering['reason']];
        }

        $coveringMap = StrictRequiredSchemaWalker::findCoveringMapPointer($pointer, $this->maps);
        if ($coveringMap !== null) {
            return ['kind' => 'map', 'coveringPointer' => $coveringMap];
        }

        return ['kind' => 'required', 'required' => $this->walked[$pointer] ?? []];
    }
}
