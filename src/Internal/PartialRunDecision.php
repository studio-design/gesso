<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

use function implode;

/**
 * Represents the decision "the current PHPUnit run is a partial /
 * filtered subset of the suite configured in `phpunit.xml`". The
 * absence of an instance (i.e. `null`) means "full run, write reports
 * normally".
 *
 * Issue #221: when a partial run completes, `CoverageReportSubscriber`
 * would otherwise overwrite a persistent `output_file` (e.g. a
 * coverage doc committed in the repo) with subset data, wiping
 * endpoints that weren't exercised. The subscriber consults this
 * value and skips every persistent file write (with a stderr WARNING
 * listing the targets) when the value is non-null.
 *
 * The detection is signal-based rather than event-based on purpose:
 * the `PHPUnit\Event\TestSuite\Filtered` event only fires for the
 * filter-style flags (`--filter`, `--group`, `--exclude-group`,
 * `--covers`, etc.) as enumerated in PHPUnit's own
 * `TestSuiteFilterProcessor`. It does NOT fire for CLI path arguments
 * (`phpunit tests/Foo/`) or for `--testsuite=X`, because those are
 * resolved by `TestSuiteBuilder` before the filter pipeline. Issue
 * #221's primary reproducer is the CLI path-arg case, so we read the
 * `Configuration` structured getters instead, which cover every
 * selection mechanism uniformly across PHPUnit 11/12/13.
 *
 * Constructed from primitives (not from a real `Configuration` object)
 * because PHPUnit's `Configuration` is `final readonly` with 150+
 * constructor parameters and is not reasonably stubbable in unit
 * tests — same rationale as the existing
 * `OpenApiCoverageExtension::setupExtension()` test seam.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class PartialRunDecision
{
    /**
     * @param non-empty-string $reason Stable, comma-joined list of the PHPUnit
     *                                 selection flags that triggered the partial
     *                                 verdict. Surfaced verbatim in the
     *                                 subscriber's WARNING.
     */
    private function __construct(public string $reason) {}

    /**
     * @param non-empty-string $reason
     */
    public static function partial(string $reason): self
    {
        return new self($reason);
    }

    /**
     * Returns a decision when any selection signal indicates a partial
     * run, or `null` when the run is full. Callers branch on
     * `null` / non-null — there is no separate `isPartial` flag,
     * so by construction "partial" and "has reason" are the same fact.
     *
     * Issue #236: `$defaultTestSuite` + `$treatDefaultTestSuiteAsFull` let
     * callers opt the `--testsuite` signal out when `$includeTestSuites`
     * was filled by PHPUnit's `defaultTestSuite` xml attribute rather
     * than a CLI selection — e.g. `phpunit` with no args resolves to
     * `[defaultTestSuite()]`, which the user considers the canonical
     * full run. The override is scoped to the `--testsuite` reason only;
     * every other signal (`--filter`, path args, `--group`, …) keeps
     * its partial verdict so the escape hatch can't silently relax
     * unrelated selections. Default `false` preserves pre-#236 behavior.
     *
     * @param list<non-empty-string> $includeTestSuites
     * @param list<non-empty-string> $excludeTestSuites
     */
    public static function fromSignals(
        bool $hasCliArguments,
        bool $hasFilter,
        bool $hasExcludeFilter,
        bool $hasGroups,
        bool $hasExcludeGroups,
        array $includeTestSuites,
        array $excludeTestSuites,
        bool $hasTestsCovering,
        bool $hasTestsUsing,
        bool $hasTestsRequiringPhpExtension,
        ?string $defaultTestSuite = null,
        bool $treatDefaultTestSuiteAsFull = false,
    ): ?self {
        // Reason fragments emitted in declaration order so output is
        // stable across runs and tests can rely on substring assertions
        // without sorting.
        $reasons = [];

        if ($hasCliArguments) {
            $reasons[] = 'test paths';
        }
        if ($hasFilter) {
            $reasons[] = '--filter';
        }
        if ($hasExcludeFilter) {
            $reasons[] = '--exclude-filter';
        }
        if ($hasGroups) {
            $reasons[] = '--group';
        }
        if ($hasExcludeGroups) {
            $reasons[] = '--exclude-group';
        }
        if ($includeTestSuites !== []) {
            $matchesDefault = $treatDefaultTestSuiteAsFull &&
                $defaultTestSuite !== null &&
                $includeTestSuites === [$defaultTestSuite];
            if (!$matchesDefault) {
                $reasons[] = '--testsuite';
            }
        }
        if ($excludeTestSuites !== []) {
            $reasons[] = '--exclude-testsuite';
        }
        if ($hasTestsCovering) {
            $reasons[] = '--covers';
        }
        if ($hasTestsUsing) {
            $reasons[] = '--uses';
        }
        if ($hasTestsRequiringPhpExtension) {
            $reasons[] = '--requires-php-extension';
        }

        if ($reasons === []) {
            return null;
        }

        return new self(implode(', ', $reasons));
    }
}
