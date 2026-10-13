<?php

declare(strict_types=1);

namespace Studio\Gesso\Contract;

use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\ExploredOperation;

use function class_alias;

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

// Declared beside the class, not by an autoloader, so the legacy name exists
// whenever this file is included — including PHPUnit's direct require_once
// in a separate process. See Autoload.php. Removed in Gesso 3.0.
class_alias(ContractCheckProbe::class, 'Studio\\Gesso\\Fuzz\\ContractCheckProbe');
