<?php

declare(strict_types=1);

namespace Studio\Gesso\Contract;

use function array_map;
use function class_alias;
use function implode;

/**
 * Summary of contract check results across all probed paths and methods.
 */
final readonly class ContractCheckSummary
{
    /**
     * @param int $probedPaths unique paths for which at least one probe was dispatched
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

// Declared beside the class, not by an autoloader, so the legacy name exists
// whenever this file is included — including PHPUnit's direct require_once
// in a separate process. See Autoload.php. Removed in Gesso 3.0.
class_alias(ContractCheckSummary::class, 'Studio\\Gesso\\Fuzz\\ContractCheckSummary');
