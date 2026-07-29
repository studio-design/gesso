<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use function array_map;
use function implode;

/**
 * Summary of contract check results across all probed paths and methods.
 */
final readonly class ContractCheckSummary
{
    /**
     * @param list<ContractCheckFailure> $failures
     * @param list<ContractCheckSkip> $skips
     */
    public function __construct(
        public int $probedPaths,
        public int $dispatchedProbes,
        public array $failures,
        public array $skips,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    public function hasSkips(): bool
    {
        return $this->skips !== [];
    }

    public function describeFailures(): string
    {
        return implode("\n", array_map(static fn(ContractCheckFailure $f): string => $f->describe(), $this->failures));
    }
}
