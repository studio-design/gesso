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
     * @param list<int> $expectedStatusClasses status classes accepted in
     *                                         addition to `$expectedStatuses`
     *                                         (`4` matches every 4xx)
     */
    public function __construct(
        public ContractCheck $check,
        public string $method,
        public string $path,
        /**
         * Null for unsupported_method (the probe's method has no documented
         * operation by definition); populated by the per-operation checks
         * ignored_auth and missing_required_header.
         */
        public ?string $operationId,
        public array $expectedStatuses,
        public int $actualStatus,
        public ExploredCase $case,
        public array $expectedStatusClasses = [],
        /** Human-readable description of the mutation the probe dispatched. */
        public ?string $mutation = null,
    ) {}

    public function describe(): string
    {
        return sprintf(
            "%s: %s %s%s%s — expected %s, got %d\n  Curl: %s",
            $this->check->value,
            $this->method,
            $this->path,
            $this->operationId !== null ? ' (' . $this->operationId . ')' : '',
            $this->mutation !== null ? ' [' . $this->mutation . ']' : '',
            $this->describeExpected(),
            $this->actualStatus,
            $this->case->curlSnippet(),
        );
    }

    private function describeExpected(): string
    {
        $parts = array_map(strval(...), $this->expectedStatuses);
        foreach ($this->expectedStatusClasses as $statusClass) {
            $parts[] = $statusClass . 'xx';
        }

        return implode(' or ', $parts);
    }
}
