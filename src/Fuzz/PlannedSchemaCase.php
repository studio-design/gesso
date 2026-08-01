<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * One generated branch-complete case: the schema-valid value together with
 * the selection plan that produced it, so callers can name the pinned
 * (choice point, branch) pair in replayable failure output.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class PlannedSchemaCase
{
    public function __construct(
        public int $index,
        public mixed $value,
        public CaseSelectionPlan $plan,
    ) {}
}
