<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * A pinned per-case branch selection for {@see SchemaDataGenerator}.
 *
 * `selections` maps choice-point JSON Pointers (as reported by
 * {@see SchemaChoicePointEnumerator}) to the branch generation must take
 * there; every choice point without an entry keeps the documented rotation
 * strategy for the case's iteration index. `targetPointer`/`targetBranch`
 * identify the (choice point, branch) pair the case exists to cover — null
 * for extra rotation-only cases — so failures can name the pinned branch.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class CaseSelectionPlan
{
    /**
     * `probe` marks a case that pins a branch whose reachability cannot be
     * determined from the schema alone (see {@see SchemaChoicePoint}); its
     * generated value is dropped instead of failing loudly when the schema
     * forbids that state.
     *
     * @param array<string, int> $selections
     */
    public function __construct(
        public array $selections,
        public ?string $targetPointer = null,
        public ?int $targetBranch = null,
        public bool $probe = false,
    ) {}

    public function branchFor(string $pointer): ?int
    {
        return $this->selections[$pointer] ?? null;
    }
}
