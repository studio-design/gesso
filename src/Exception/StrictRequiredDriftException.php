<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Exception;

use InvalidArgumentException;
use RuntimeException;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredReport;

/**
 * Thrown when one or more endpoints' response bodies consistently contain
 * keys that are not declared in the matching schema's `required` array, and
 * the {@see StrictRequiredMode}
 * is `Fail`.
 *
 * Distinct from a regular schema conformance failure: this signals that the
 * **spec under-describes the implementation** (the impl always returns a
 * field but the spec marks it optional), which downstream SDK consumers see
 * as needless `T | undefined` types.
 *
 * The full report list is exposed so CI summary builders can render or
 * count drift programmatically without re-parsing the message.
 */
final class StrictRequiredDriftException extends RuntimeException
{
    /**
     * @param list<StrictRequiredReport> $reports must be non-empty and every
     *                                            entry must satisfy
     *                                            {@see StrictRequiredReport::hasDrift()} —
     *                                            the exception means "drift was detected",
     *                                            so carrying clean reports would contradict
     *                                            the type
     *
     * @throws InvalidArgumentException when the invariant is violated
     */
    public function __construct(
        public readonly array $reports,
        string $message,
    ) {
        if ($reports === []) {
            throw new InvalidArgumentException(
                'StrictRequiredDriftException requires at least one StrictRequiredReport — empty reports contradict the "drift detected" semantic.',
            );
        }
        foreach ($reports as $report) {
            if (!$report->hasDrift()) {
                throw new InvalidArgumentException(
                    'StrictRequiredDriftException reports must all satisfy hasDrift(). Filter clean reports before constructing.',
                );
            }
        }

        parent::__construct($message);
    }
}
