<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use Studio\Gesso\Coverage\CoverageThresholdEvaluator;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\ResponseCoverageState;

use function count;
use function implode;
use function sprintf;

/**
 * Pure evaluator for the coverage baseline gate (issue #481).
 *
 * Where {@see CoverageThresholdEvaluator} compares a rounded percentage
 * against a hand-maintained float, this compares two **sets** of responses:
 * the ones the run did not cover, and the ones the committed baseline says
 * are known-uncovered. That makes the gate independent of the denominator —
 * documenting a new response cannot fail an unrelated PR by moving a
 * percentage — and lets a failure name the offending rows instead of
 * reporting a number.
 *
 * A response counts as covered only when it is
 * {@see ResponseCoverageState::Validated}; `Skipped` rows are baselined like
 * uncovered ones, matching the numerator the percentage gate uses, so
 * switching to the baseline never silently weakens the gate.
 *
 * Decoupled from I/O so the PHPUnit subscriber (in-process) and the merge
 * CLI (paratest post-step) share one implementation.
 *
 * @internal Used by the PHPUnit extension and merge CLI. The
 *           `coverage_baseline_file` configuration and the committed file
 *           format are the supported surfaces.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 *
 * @phpstan-type CoverageBaselineVerdict array{
 *     regressions: list<CoverageBaselineEntry>,
 *     stale: list<CoverageBaselineEntry>,
 *     uncovered: int,
 * }
 */
final class CoverageBaselineEvaluator
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Every response the run did not validate, as a baseline.
     *
     * @param array<string, CoverageResult> $results
     */
    public static function collect(array $results): CoverageBaseline
    {
        $baseline = new CoverageBaseline();

        foreach ($results as $specName => $result) {
            foreach ($result['endpoints'] as $endpoint) {
                foreach ($endpoint['responses'] as $response) {
                    if ($response['state'] === ResponseCoverageState::Validated) {
                        continue;
                    }

                    $baseline->add(CoverageBaselineEntry::create(
                        $specName,
                        $endpoint['method'],
                        $endpoint['path'],
                        $response['statusKey'],
                        $response['contentTypeKey'],
                    ));
                }
            }
        }

        return $baseline;
    }

    /**
     * Compare the run against the committed baseline.
     *
     * `regressions` are uncovered responses the baseline does not list — the
     * gate failure. `stale` are baseline entries that are covered now (or no
     * longer declared at all): the ratchet-down signal, removable from the
     * file.
     *
     * @param array<string, CoverageResult> $results
     *
     * @return CoverageBaselineVerdict
     */
    public static function evaluate(CoverageBaseline $baseline, array $results): array
    {
        $current = self::collect($results);

        $regressions = [];
        foreach ($current->sorted() as $entry) {
            if (!$baseline->contains($entry)) {
                $regressions[] = $entry;
            }
        }

        $stale = [];
        foreach ($baseline->sorted() as $entry) {
            if (!$current->contains($entry)) {
                $stale[] = $entry;
            }
        }

        return [
            'regressions' => $regressions,
            'stale' => $stale,
            'uncovered' => $current->count(),
        ];
    }

    /**
     * @param list<CoverageBaselineEntry> $regressions
     */
    public static function renderRegressionMessage(array $regressions, string $generateHint): string
    {
        return sprintf(
            "[Gesso] FATAL: %d response(s) are not covered and are not listed in the coverage baseline:\n%s\n  Action: cover them with a test, or accept them by regenerating the baseline with `%s`.\n",
            count($regressions),
            self::renderListing($regressions),
            $generateHint,
        );
    }

    /**
     * @param list<CoverageBaselineEntry> $stale
     */
    public static function renderStaleMessage(array $stale, bool $isFatal): string
    {
        return sprintf(
            "[Gesso] %s: %d coverage baseline entry(ies) are covered now and can be removed from the baseline file:\n%s\n",
            $isFatal ? 'FATAL' : 'NOTE',
            count($stale),
            self::renderListing($stale),
        );
    }

    /**
     * @param list<CoverageBaselineEntry> $entries
     */
    private static function renderListing(array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = '  - ' . $entry->describe();
        }

        return implode("\n", $lines);
    }
}
