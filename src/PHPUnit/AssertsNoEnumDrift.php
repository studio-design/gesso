<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use Studio\Gesso\Schema\EnumDriftAsserter;
use Studio\Gesso\Schema\EnumDriftReport;

use function array_filter;
use function array_values;

/**
 * PHPUnit-aware shim over {@see EnumDriftAsserter}.
 *
 * `EnumDriftAsserter::assertNoDrift()` is framework-agnostic — it throws on
 * failure and returns void on success, which means PHPUnit's bookkeeping
 * never sees it and tests that "passed" get marked risky whenever
 * `beStrictAboutTestsThatDoNotTestAnything=true` (the PHPUnit 13 default;
 * opt-in on earlier majors).
 *
 * This trait wraps the same comparison through `CleanTraceAssert::fail()` and bumps
 * the assertion counter so passing drift tests stay green. The static
 * `assertNoDrift()` API is unchanged; non-PHPUnit drift CI scripts can
 * keep using it.
 *
 * Misconfiguration (`EnumBindingException`) is intentionally NOT routed
 * through `CleanTraceAssert::fail()` — a missing `#[BoundToOpenApiEnum]` or
 * unreadable spec file is a setup error, not a drift signal, and callers
 * want the structured exception properties (`$reason`, `$enumFqcn`,
 * `$specPath`) preserved.
 */
trait AssertsNoEnumDrift
{
    /**
     * @param list<class-string> $enumFqcns
     * @param list<string> $specNames spec names searched for component bindings
     */
    public function assertNoEnumDrift(array $enumFqcns, array $specNames = []): void
    {
        $reports = EnumDriftAsserter::detectAll($enumFqcns, $specNames);
        $drifting = array_values(array_filter(
            $reports,
            static fn(EnumDriftReport $report): bool => $report->hasDrift(),
        ));

        if ($drifting === []) {
            $this->addToAssertionCount(1);

            return;
        }

        CleanTraceAssert::fail(EnumDriftAsserter::renderMessage($drifting, failOnDrift: true));
    }
}
