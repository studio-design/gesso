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

    /**
     * Build an exception for a specific bound enum that failed to resolve
     * against its spec file. `$enumFqcn` is required because all per-binding
     * reasons (`TargetIsNotEnum`, `AttributeMissing`, `SpecFileNotFound`, …)
     * carry it.
     */
    public static function forBinding(
        EnumBindingReason $reason,
        string $message,
        string $enumFqcn,
        ?string $specPath = null,
        ?Throwable $previous = null,
    ): self {
        return new self($reason, $message, $enumFqcn, $specPath, $previous);
    }

    /**
     * Build an exception for a scanner-level misconfiguration that is not
     * tied to any single binding (`NoNamespacesConfigured`,
     * `ScanNamespaceUnresolvable`, `ScanComposerLoaderUnavailable`).
     * `$enumFqcn` and `$specPath` are deliberately not accepted — these
     * reasons describe the scan setup itself, not a failed binding.
     */
    public static function forScan(
        EnumBindingReason $reason,
        string $message,
        ?Throwable $previous = null,
    ): self {
        return new self($reason, $message, null, null, $previous);
    }

    /**
     * Build an exception for a configuration-level failure that is global
     * to the test run rather than per-binding (`EnumBasePathNotFound`,
     * `EnumSpecBasePathOrphaned`). Structurally identical to {@see forScan}
     * — no `$enumFqcn` / `$specPath` — but documented separately so callers
     * branching on the reason aren't misled by an arbitrary "first failing
     * binding" enumFqcn surfacing on a global error.
     */
    public static function forConfig(
        EnumBindingReason $reason,
        string $message,
        ?Throwable $previous = null,
    ): self {
        return new self($reason, $message, null, null, $previous);
    }
}
