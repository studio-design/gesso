<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use Studio\Gesso\Internal\RuntimeExpression;

/**
 * A workflow that passed preflight: every step resolved to one OpenAPI
 * operation and every expression parsed within the supported subset.
 *
 * @internal Becomes public with the Arazzo workflow runner (#428).
 */
final readonly class ArazzoWorkflowPlan
{
    /**
     * @param null|array<string, mixed> $inputsSchema
     * @param list<ArazzoStepPlan> $steps
     * @param array<string, RuntimeExpression> $outputs
     */
    public function __construct(
        public string $workflowId,
        public ?array $inputsSchema,
        public array $steps,
        public array $outputs,
    ) {}
}
