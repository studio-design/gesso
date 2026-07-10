<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

final readonly class SpecExplorationSummary
{
    /**
     * @param list<ExploredOperation> $operations Successfully executed operations.
     * @param list<ExplorationSkip> $skips
     */
    public function __construct(
        public int $executedOperations,
        public int $executedCases,
        public array $operations,
        public array $skips,
    ) {}

    public function hasSkips(): bool
    {
        return $this->skips !== [];
    }
}
