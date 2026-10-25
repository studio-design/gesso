<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiValidationResult;

/**
 * What happened in one executed step: the request, both contract
 * validations, the criteria, the evaluated outputs and the failure the step
 * produced, if any. Response fields are null when the step stopped before
 * dispatch.
 */
final readonly class ArazzoStepResult
{
    /**
     * @internal Built by the workflow runner.
     *
     * @param array<string, list<string>> $responseHeaders lower-cased names
     * @param list<array{condition: string, passed: bool, error: ?string}> $criteria
     * @param array<string, mixed> $outputs
     */
    public function __construct(
        public string $stepId,
        public ArazzoStepRequest $request,
        public OpenApiValidationResult $requestValidation,
        public ?int $statusCode = null,
        public array $responseHeaders = [],
        public ?DecodedBody $responseBody = null,
        public ?OpenApiValidationResult $responseValidation = null,
        public array $criteria = [],
        public array $outputs = [],
        public ?ArazzoFailure $failure = null,
    ) {}
}
