<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * Result of one spec-wide SDK response round-trip plan.
 */
final readonly class ResponseSpecExplorationSummary
{
    /**
     * @param list<ExploredOperation> $operations
     * @param list<ResponseSpecExplorationFailure> $decodeFailures
     * @param list<ResponseSpecExplorationFailure> $roundTripFailures
     * @param list<ResponseSpecExplorationSkip> $skips
     */
    public function __construct(
        public int $executedOperations,
        public int $executedResponses,
        public int $executedCases,
        public array $operations,
        public array $decodeFailures,
        public array $roundTripFailures,
        public array $skips,
    ) {}

    public function hasFailures(): bool
    {
        return $this->decodeFailures !== [] || $this->roundTripFailures !== [];
    }

    public function hasSkips(): bool
    {
        return $this->skips !== [];
    }

    public function hasMappingGaps(): bool
    {
        foreach ($this->skips as $skip) {
            if ($skip->mappingGap) {
                return true;
            }
        }

        return false;
    }
}
