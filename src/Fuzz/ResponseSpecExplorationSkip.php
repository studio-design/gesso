<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * One response schema that a spec-wide SDK round-trip plan did not execute.
 */
final readonly class ResponseSpecExplorationSkip
{
    public function __construct(
        public ExploredOperation $operation,
        public string $status,
        public ?string $contentType,
        public string $reason,
        public bool $mappingGap = false,
    ) {}
}
