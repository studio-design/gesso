<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Exception;

use RuntimeException;
use Studio\OpenApiContractTesting\Schema\EnumDriftReport;

/**
 * Thrown when one or more `(PHP enum, OpenAPI enum file)` pairs disagree on
 * their value set. Distinct from `EnumBindingException`, which signals that
 * the comparison could not even be performed (missing attribute, unreadable
 * file, etc.).
 *
 * The full report list is exposed so PHPUnit assertions, CI summary
 * builders, and other consumers can render or count drift programmatically
 * without re-parsing the message.
 */
final class EnumDriftException extends RuntimeException
{
    /**
     * @param list<EnumDriftReport> $reports every report passed in here is
     *                                       guaranteed to satisfy
     *                                       {@see EnumDriftReport::hasDrift()}; clean
     *                                       reports are filtered out by the asserter
     */
    public function __construct(
        public readonly array $reports,
        string $message,
    ) {
        parent::__construct($message);
    }
}
