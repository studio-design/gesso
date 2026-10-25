<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

final readonly class SpecExplorationSummary
{
    /**
     * @param list<ExploredOperation> $operations Successfully executed operations.
     * @param list<ExplorationSkip> $skips
     * @param int $followedLinks follow-up requests dispatched through response `links` (see followLinksUsing())
     */
    public function __construct(
        public int $executedOperations,
        public int $executedCases,
        public array $operations,
        public array $skips,
        public int $followedLinks = 0,
    ) {}

    public function hasSkips(): bool
    {
        return $this->skips !== [];
    }
}
