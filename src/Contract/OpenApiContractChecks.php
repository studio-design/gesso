<?php

declare(strict_types=1);

namespace Studio\Gesso\Contract;

use InvalidArgumentException;

use function class_alias;

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

// Declared beside the class, not by an autoloader, so the legacy name exists
// whenever this file is included — including PHPUnit's direct require_once
// in a separate process. See Autoload.php. Removed in Gesso 3.0.
class_alias(OpenApiContractChecks::class, 'Studio\\Gesso\\Fuzz\\OpenApiContractChecks');
