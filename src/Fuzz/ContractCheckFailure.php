<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use function array_map;
use function implode;
use function sprintf;
use function strval;

/**
 * Contract check detected a failure (probe received unexpected status).
 */
final readonly class ContractCheckFailure
{
    /**
     * @param list<int> $expectedStatuses
     */
    public function __construct(
        public ContractCheck $check,
        public string $method,
        public string $path,
        /**
         * Null for unsupported_method (the probe's method has no documented
         * operation by definition); populated by per-operation checks
         * (planned: ignored_auth, missing_required_header).
         */
        public ?string $operationId,
        public array $expectedStatuses,
        public int $actualStatus,
        public ExploredCase $case,
    ) {}

    public function describe(): string
    {
        return sprintf(
            "%s: %s %s — expected %s, got %d\n  Curl: %s",
            $this->check->value,
            $this->method,
            $this->path,
            implode(' or ', array_map(strval(...), $this->expectedStatuses)),
            $this->actualStatus,
            $this->case->curlSnippet(),
        );
    }
}
