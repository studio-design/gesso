<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use Studio\Gesso\Internal\RuntimeExpression;

/**
 * One preflighted step bound to its source operation.
 *
 * @internal Arazzo runner implementation detail; not public API.
 */
final readonly class ArazzoStepPlan
{
    /**
     * @param string $method as declared in the source (fixed methods upper-cased, additionalOperations keys verbatim)
     * @param array<string, mixed> $operation the source's own Operation Object
     * @param list<array{name: string, in: string, value: mixed}> $parameters workflow parameters merged with step parameters
     * @param list<ArazzoSimpleCondition> $conditions parsed `successCriteria`
     * @param array<string, RuntimeExpression> $outputs
     * @param array<string, string> $queryDelimiters query parameter name → wire delimiter of its non-exploded style
     * @param list<ArazzoAction> $onSuccess workflow successActions merged with the step's onSuccess
     * @param list<ArazzoAction> $onFailure workflow failureActions merged with the step's onFailure
     */
    public function __construct(
        public string $stepId,
        public string $specName,
        public ?string $operationId,
        public string $method,
        public string $pathTemplate,
        public array $operation,
        public array $parameters,
        public ?string $contentType,
        public bool $hasBody,
        public mixed $payload,
        public array $conditions,
        public array $outputs,
        public array $queryDelimiters = [],
        public array $onSuccess = [],
        public array $onFailure = [],
    ) {}
}
