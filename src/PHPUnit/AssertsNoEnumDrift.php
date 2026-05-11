<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Studio\OpenApiContractTesting\Internal\StackTraceFilter;
use Studio\OpenApiContractTesting\Schema\EnumDriftAsserter;
use Studio\OpenApiContractTesting\Schema\EnumDriftReport;

use function array_filter;
use function array_values;

/**
 * PHPUnit-aware shim over {@see EnumDriftAsserter}.
 *
 * `EnumDriftAsserter::assertNoDrift()` is framework-agnostic — it throws on
 * failure and returns void on success, which means PHPUnit's bookkeeping
 * never sees it and tests that "passed" get marked risky under
 * `beStrictAboutTestsThatDoNotTestAnything=true` (PHPUnit 13 default).
 *
 * This trait wraps the same comparison through `Assert::fail()` and bumps
 * the assertion counter so passing drift tests stay green. The static
 * `assertNoDrift()` API is unchanged; non-PHPUnit drift CI scripts can
 * keep using it.
 *
 * Misconfiguration (`EnumBindingException`) is intentionally NOT routed
 * through `Assert::fail()` — a missing `#[BoundToOpenApiEnum]` or
 * unreadable spec file is a setup error, not a drift signal, and callers
 * want the structured exception properties (`$reason`, `$enumFqcn`,
 * `$specPath`) preserved.
 */
trait AssertsNoEnumDrift
{
    /**
     * @param list<class-string> $enumFqcns
     */
    public function assertNoEnumDrift(array $enumFqcns): void
    {
        $reports = EnumDriftAsserter::detectAll($enumFqcns);
        $drifting = array_values(array_filter(
            $reports,
            static fn(EnumDriftReport $report): bool => $report->hasDrift(),
        ));

        if ($drifting === []) {
            $this->addToAssertionCount(1);

            return;
        }

        try {
            Assert::fail(EnumDriftAsserter::renderMessage($drifting, failOnDrift: true));
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }
}
