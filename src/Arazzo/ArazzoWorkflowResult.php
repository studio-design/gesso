<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use function array_map;
use function implode;
use function sprintf;

/**
 * The outcome of one Arazzo workflow run.
 */
final readonly class ArazzoWorkflowResult
{
    /**
     * @internal Built by the workflow runner.
     *
     * @param list<ArazzoStepResult> $steps executed steps in order, including the failing one
     * @param array<string, mixed> $outputs workflow outputs; empty when the run failed
     */
    public function __construct(
        public string $workflowId,
        public array $steps,
        public array $outputs,
        public ?ArazzoFailure $failure,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->failure === null;
    }

    /**
     * A human-readable failure report for assertion messages; empty on
     * success. The text layout is not a compatibility surface — branch on
     * {@see self::$failure} instead of parsing it.
     */
    public function failureMessage(): string
    {
        $failure = $this->failure;
        if ($failure === null) {
            return '';
        }

        $lines = [sprintf(
            "Arazzo workflow '%s' failed%s (%s): %s",
            $this->workflowId,
            $failure->stepId === null ? '' : sprintf(" at step '%s'", $failure->stepId),
            $failure->kind->value,
            $failure->message,
        )];
        foreach ($failure->errors as $error) {
            $lines[] = '- ' . $error;
        }

        $lines[] = 'Completed steps: ' . ($this->steps === [] ? '(none)' : implode(', ', array_map(
            static fn(ArazzoStepResult $step): string => sprintf(
                '%s (%s %s → %s)',
                $step->stepId,
                $step->request->method,
                $step->request->pathTemplate,
                $step->statusCode ?? 'not sent',
            ),
            $this->steps,
        )));

        $requestShown = false;
        foreach ($this->steps as $step) {
            if ($step->failure === null) {
                continue;
            }

            if ($step->failure !== $failure) {
                // A goto routed around this failure; it still fails the run.
                $lines[] = sprintf("Also failed: step '%s' (%s): %s", $step->stepId, $step->failure->kind->value, $step->failure->message);
            } elseif (!$requestShown) {
                $lines[] = 'Request: ' . $step->request->curlSnippet();
                $requestShown = true;
            }
        }

        return implode("\n", $lines);
    }
}
