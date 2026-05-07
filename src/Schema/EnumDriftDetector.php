<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Schema;

use function array_filter;
use function array_unique;
use function array_values;
use function in_array;
use function sort;

/**
 * Pure two-direction set diff between PHP enum case values and an OpenAPI
 * `enum` array. No I/O, no reflection — kept side-effect free so the
 * comparison logic is independently testable.
 *
 * @internal Not part of the package's public API. Consumers use
 *           {@see EnumDriftAsserter}; this is the exposed seam for unit
 *           tests of the diff itself.
 */
final class EnumDriftDetector
{
    /**
     * @param list<int|string> $phpValues case values from `EnumClass::cases()`
     * @param list<int|string> $specValues the `enum` array from the bound spec file
     */
    public static function detect(
        string $enumFqcn,
        string $specPath,
        array $phpValues,
        array $specValues,
    ): EnumDriftReport {
        // Dedupe defensively. Real PHP enums forbid duplicate case values, so
        // dupes only sneak in via direct caller input; the diff would still
        // emit them and double-count drift in the rendered report.
        $php = array_values(array_unique($phpValues));
        $spec = array_values(array_unique($specValues));

        // Strict comparison via in_array(..., strict: true). array_diff would
        // type-juggle, conflating PHP '1' with spec 1 — a real type drift the
        // user must see, since backed enums are int-only or string-only.
        $phpOnly = array_values(array_filter(
            $php,
            static fn(int|string $value): bool => !in_array($value, $spec, true),
        ));
        $specOnly = array_values(array_filter(
            $spec,
            static fn(int|string $value): bool => !in_array($value, $php, true),
        ));

        // Stable, deterministic order — important for snapshot-style
        // diagnostic output and for byte-stable JSON exports.
        sort($phpOnly);
        sort($specOnly);

        return new EnumDriftReport(
            enumFqcn: $enumFqcn,
            specPath: $specPath,
            phpOnly: $phpOnly,
            specOnly: $specOnly,
        );
    }
}
