<?php

declare(strict_types=1);

namespace Studio\Gesso\Schema;

/**
 * Result of a single (PHP enum, OpenAPI enum) drift comparison. `specPath`
 * is the file path or the `#/components/schemas/Name` pointer the enum was
 * compared against.
 *
 * `phpOnly` and `specOnly` are zero-indexed and sorted by
 * {@see EnumDriftDetector} for deterministic output — consumers (JSON
 * exports, snapshot-style diagnostic rendering) need a stable list shape.
 */
final class EnumDriftReport
{
    /**
     * @param list<int|string> $phpOnly values present in the PHP enum but absent from the spec
     * @param list<int|string> $specOnly values present in the spec but absent from the PHP enum
     */
    public function __construct(
        public readonly string $enumFqcn,
        public readonly string $specPath,
        public readonly array $phpOnly,
        public readonly array $specOnly,
    ) {}

    public function hasDrift(): bool
    {
        return $this->phpOnly !== [] || $this->specOnly !== [];
    }
}
