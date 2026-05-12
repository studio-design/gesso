<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

use PHPUnit\TextUI\TestSuiteFilterProcessor;

use function implode;

/**
 * Detects whether the current PHPUnit run is a partial / filtered run
 * (a subset of the suite configured in `phpunit.xml`).
 *
 * Issue #221: when a partial run completes, `CoverageReportSubscriber`
 * would overwrite a persistent `output_file` (e.g. a coverage doc
 * committed in the repo) with subset data, wiping endpoints that
 * weren't exercised. The subscriber consults this detector and skips
 * persistent writes (with a stderr WARNING) when `isPartial` is true.
 *
 * The detection is signal-based rather than event-based on purpose:
 * `PHPUnit\Event\TestSuite\Filtered` only fires for the filter-style
 * flags (`--filter`, `--group`, `--exclude-group`, `--covers`, etc.;
 * see {@see TestSuiteFilterProcessor::process()}). It
 * does NOT fire for CLI path arguments (`phpunit tests/Foo/`) or for
 * `--testsuite=X`, because those are resolved by `TestSuiteBuilder`
 * before the filter pipeline. Issue #221's primary reproducer is the
 * CLI path-arg case, so we have to fall back to the `Configuration`
 * structured getters which cover every selection mechanism uniformly.
 *
 * Constructed from primitives (not from a real `Configuration` object)
 * because `PHPUnit\TextUI\Configuration\Configuration` is `final
 * readonly` with 150+ constructor parameters and is not reasonably
 * stubbable in unit tests — same rationale as the existing
 * `OpenApiCoverageExtension::setupExtension()` test seam.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class PartialRunDetector
{
    private function __construct(
        public bool $isPartial,
        public ?string $reason,
    ) {}

    /**
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
    ): self {
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
            $reasons[] = '--testsuite';
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
            return new self(false, null);
        }

        return new self(true, implode(', ', $reasons));
    }
}
