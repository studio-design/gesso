<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when a `#[BoundToOpenApiEnum]` binding cannot be turned into a
 * (PHP enum cases, spec enum values) pair. This is always a misconfiguration
 * — distinct from `EnumDriftException` which signals that the comparison
 * succeeded but the two sides disagree.
 *
 * The exhaustive list of failure categories lives on `EnumBindingReason`;
 * consumers should branch on `$reason` rather than pattern-matching the
 * `$message`.
 */
final class EnumBindingException extends RuntimeException
{
    public function __construct(
        public readonly EnumBindingReason $reason,
        string $message,
        public readonly ?string $enumFqcn = null,
        public readonly ?string $specPath = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
