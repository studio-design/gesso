<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Schema;

/**
 * Result of a single (PHP enum, OpenAPI enum file) drift comparison.
 *
 * `phpOnly` and `specOnly` are zero-indexed lists, regardless of which keys
 * `array_diff` produced — consumers (JSON output, sorted rendering) need
 * stable list shape.
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
