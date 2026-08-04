<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * One request a {@see ContractCheckPlan} is about to dispatch, together with
 * the metadata a failure needs: which documented operation it was derived
 * from, how the case was mutated, and whether the plan's
 * `authenticateUsing()` hook may run for it.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class ContractCheckProbe
{
    /**
     * @param null|string $operationId reported on the failure; null for
     *                                 path-level checks whose probe has no
     *                                 documented operation by construction
     * @param null|string $mutation human-readable description of what the
     *                              probe changed ("no credentials",
     *                              "omitted required header 'X-Request-Id'")
     * @param bool $authenticate false for probes whose whole point is the
     *                           absence of credentials — running the plan's
     *                           authenticate hook would defeat the check
     */
    public function __construct(
        public ExploredCase $case,
        public ExploredOperation $operation,
        public ?string $operationId = null,
        public ?string $mutation = null,
        public bool $authenticate = true,
    ) {}
}
