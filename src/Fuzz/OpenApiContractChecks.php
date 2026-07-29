<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;

/**
 * Entry point for deterministic named contract checks
 * ({@see ContractCheck}) over a whole spec.
 */
final class OpenApiContractChecks
{
    public static function run(string $specName, int $seed = 1): ContractCheckPlan
    {
        if ($specName === '') {
            throw new InvalidArgumentException('OpenApiContractChecks::run() requires a non-empty spec name.');
        }

        return new ContractCheckPlan($specName, $seed);
    }
}
