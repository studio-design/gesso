<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * One composition choice point discovered by {@see SchemaChoicePointEnumerator}.
 *
 * `pointer` addresses the choice keyword on the effective schema — the view the
 * generator sees after `allOf`/branch merging — so a pinned selection plan and
 * generation resolve the same location. `ancestors` are the pinned selections
 * (pointer => branch) required for this choice point to be reachable at all:
 * enclosing optional properties forced present, nullable ancestors forced to
 * the value branch, enclosing composition branches, and minimum array sizes.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class SchemaChoicePoint
{
    /** Branch index that includes an optional property / keeps a value. */
    public const PRESENT = 0;

    /** Branch index that omits an optional property. */
    public const OMITTED = 1;

    /** Branch index that keeps the non-null value of a nullable node. */
    public const VALUE = 0;

    /** Branch index that emits null for a nullable node. */
    public const NULL_VALUE = 1;

    /**
     * `probeBranch` names a branch whose reachability cannot be determined
     * from the schema alone (the none-match state of conditional `allOf`);
     * `probeContext` marks a choice point discovered inside such a state.
     * Cases pinning either are reachability probes: they are dropped instead
     * of failing loudly when the schema forbids that state.
     *
     * @param array<string, int> $ancestors
     */
    public function __construct(
        public SchemaChoicePointKind $kind,
        public string $pointer,
        public int $branchCount,
        public array $ancestors,
        public ?int $probeBranch = null,
        public bool $probeContext = false,
    ) {}
}
