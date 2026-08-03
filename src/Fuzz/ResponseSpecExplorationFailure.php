<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Throwable;

/**
 * Replayable context for one failed SDK decode or encode/round-trip case.
 */
final readonly class ResponseSpecExplorationFailure
{
    public function __construct(
        public ExploredOperation $operation,
        public string $status,
        public int $wireStatus,
        public string $contentType,
        public int $caseIndex,
        public int $seed,
        public ?string $pinnedBranch,
        public string $replay,
        public string $message,
        public Throwable $cause,
    ) {}
}
