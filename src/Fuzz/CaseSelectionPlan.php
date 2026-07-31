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
    /** @param array<string, int> $selections */
    public function __construct(
        public array $selections,
        public ?string $targetPointer = null,
        public ?int $targetBranch = null,
    ) {}

    public function branchFor(string $pointer): ?int
    {
        return $this->selections[$pointer] ?? null;
    }
}
