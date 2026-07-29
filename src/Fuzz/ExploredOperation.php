<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Studio\Gesso\Spec\OpenApiOperationResolver;

use function array_filter;
use function array_values;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function var_export;

/**
 * Metadata for one operation selected by a whole-spec exploration.
 */
final readonly class ExploredOperation
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $specName,
        public string $method,
        public string $path,
        public ?string $operationId,
        public array $tags,
        public bool $deprecated,
        public int $seed,
    ) {}

    /**
     * Build the operation DTO from a raw Path Item declaration.
     *
     * @internal shared factory for the exploration and contract-check plans.
     */
    public static function fromDeclaration(
        string $specName,
        string $path,
        string $method,
        mixed $rawOperation,
        int $derivedSeed,
    ): self {
        $normalizedMethod = OpenApiOperationResolver::normalizeMethodForKey($method);
        $operation = is_array($rawOperation) ? $rawOperation : [];
        $operationId = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null;
        $tags = is_array($operation['tags'] ?? null)
            ? array_values(array_filter($operation['tags'], is_string(...)))
            : [];

        return new self(
            $specName,
            $normalizedMethod,
            $path,
            $operationId,
            $tags,
            ($operation['deprecated'] ?? false) === true,
            $derivedSeed,
        );
    }

    /**
     * Return a minimal expression that regenerates the exact input case.
     */
    public function replaySnippet(int $caseIndex): string
    {
        return sprintf(
            'OpenApiEndpointExplorer::explore(%s, %s, %s, cases: %d, seed: %d)->cases[%d]',
            var_export($this->specName, true),
            var_export($this->method, true),
            var_export($this->path, true),
            $caseIndex + 1,
            $this->seed,
            $caseIndex,
        );
    }

    /**
     * Return a minimal expression that regenerates the exact negative input case.
     *
     * @param list<int> $expectedStatusClasses
     */
    public function replayInvalidSnippet(int $caseIndex, array $expectedStatusClasses): string
    {
        return sprintf(
            'OpenApiEndpointExplorer::exploreInvalid(%s, %s, %s, expectedStatusClasses: [%s], cases: %d, seed: %d)->cases[%d]',
            var_export($this->specName, true),
            var_export($this->method, true),
            var_export($this->path, true),
            implode(', ', $expectedStatusClasses),
            $caseIndex + 1,
            $this->seed,
            $caseIndex,
        );
    }

    /**
     * Key shape used by OpenApiCoverageTracker diagnostic rows.
     */
    public function coverageKey(): string
    {
        return $this->method . ' ' . $this->path;
    }
}
