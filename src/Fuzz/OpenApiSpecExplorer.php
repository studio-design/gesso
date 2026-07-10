<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use InvalidArgumentException;

use function sprintf;

/**
 * Entry point for deterministic whole-spec request exploration.
 */
final class OpenApiSpecExplorer
{
    public static function explore(
        string $specName,
        int $casesPerOperation = 30,
        int $seed = 1,
    ): OpenApiSpecExploration {
        if ($specName === '') {
            throw new InvalidArgumentException('OpenApiSpecExplorer::explore() requires a non-empty spec name.');
        }

        if ($casesPerOperation < 1) {
            throw new InvalidArgumentException(sprintf(
                'OpenApiSpecExplorer::explore() requires casesPerOperation >= 1, got %d.',
                $casesPerOperation,
            ));
        }

        return new OpenApiSpecExploration($specName, $casesPerOperation, $seed);
    }
}
