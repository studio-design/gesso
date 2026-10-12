<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

/**
 * Why an Arazzo workflow run failed, and where.
 */
final readonly class ArazzoFailure
{
    /**
     * @internal Built by the workflow runner.
     *
     * @param ?string $stepId null when the failure is not tied to one step (inputs, workflow outputs)
     * @param list<string> $errors individual validator errors, failed conditions or exception messages
     */
    public function __construct(
        public ArazzoFailureKind $kind,
        public ?string $stepId,
        public string $message,
        public array $errors = [],
    ) {}
}
