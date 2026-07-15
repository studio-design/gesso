<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use Studio\Gesso\PHPUnit\CoverageReportSubscriber;

use function implode;
use function round;
use function sprintf;
use function str_repeat;
use function strlen;

/**
 * Pure evaluator that gates a CI run on coverage thresholds. Mirrors PHPUnit's
 * own `--coverage-threshold`: take the per-spec results from
 * {@see OpenApiCoverageTracker::computeCoverage()}, sum the raw covered/total
 * counts across configured specs (NOT an average of per-spec percentages —
 * that would weight uneven specs incorrectly), recompute a single percentage,
 * and compare against optional `min_endpoint_coverage` / `min_response_coverage`
 * percent values.
 *
 * Decoupled from I/O so {@see CoverageReportSubscriber} (in-process) and
 * {@see CoverageMergeCommand} (paratest post-step) can share gating logic
 * without duplicating it. The caller decides whether to print/exit; the
 * evaluator only reports.
 *
 * @internal Used by the PHPUnit extension and merge CLI. Configuration and
 *           diagnostics are the supported public surfaces.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 *
 * @phpstan-type ThresholdLine array{percent: float, threshold: float, ok: bool}
 * @phpstan-type ThresholdResult array{
 *     passed: bool,
 *     endpoint: ?ThresholdLine,
 *     response: ?ThresholdLine,
 *     message: string,
 * }
 */
final class CoverageThresholdEvaluator
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * @param array<string, CoverageResult> $results
     *
     * @return ThresholdResult
     */
    public static function evaluate(
        array $results,
        ?float $minEndpointPct,
        ?float $minResponsePct,
        bool $strict,
    ): array {
        $endpointCovered = 0;
        $endpointTotal = 0;
        $responseCovered = 0;
        $responseTotal = 0;
        foreach ($results as $result) {
            $endpointCovered += $result['endpointFullyCovered'];
            $endpointTotal += $result['endpointTotal'];
            $responseCovered += $result['responseCovered'];
            $responseTotal += $result['responseTotal'];
        }

        $endpoint = $minEndpointPct === null
            ? null
            : self::buildLine($endpointCovered, $endpointTotal, $minEndpointPct);
        $response = $minResponsePct === null
            ? null
            : self::buildLine($responseCovered, $responseTotal, $minResponsePct);

        $passed = ($endpoint['ok'] ?? true) && ($response['ok'] ?? true);

        $message = '';
        if (!$passed) {
            $message = self::renderMessage($endpoint, $response, $strict);
        }

        return [
            'passed' => $passed,
            'endpoint' => $endpoint,
            'response' => $response,
            'message' => $message,
        ];
    }

    /**
     * @return ThresholdLine
     */
    private static function buildLine(int $covered, int $total, float $threshold): array
    {
        // `total === 0` only happens for a spec with no declared paths /
        // responses — there's no contract API to fail against, so report it
        // as 100% so the gate doesn't punish well-formed empty specs.
        // The "no coverage was recorded" silent-pass case is *not* defended
        // here: callers (`CoverageReportSubscriber`, `CoverageMergeCommand`)
        // detect empty results explicitly and emit FATAL/WARNING before
        // reaching this method (issue #135 review C2).
        $percent = $total > 0 ? round($covered / $total * 100, 1) : 100.0;

        return [
            'percent' => $percent,
            'threshold' => $threshold,
            'ok' => $percent >= $threshold,
        ];
    }

    /**
     * @param null|ThresholdLine $endpoint
     * @param null|ThresholdLine $response
     */
    private static function renderMessage(?array $endpoint, ?array $response, bool $strict): string
    {
        $prefix = sprintf('[OpenAPI Coverage] %s: ', $strict ? 'FAIL' : 'WARN');
        $indent = str_repeat(' ', strlen($prefix));
        $lines = [];
        if ($endpoint !== null) {
            $lines[] = $prefix . self::renderLine('endpoint', $endpoint);
        }
        if ($response !== null) {
            $lead = $lines === [] ? $prefix : $indent;
            $lines[] = $lead . self::renderLine('response', $response);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param ThresholdLine $line
     */
    private static function renderLine(string $label, array $line): string
    {
        $actual = self::formatPercent($line['percent']);
        $threshold = self::formatPercent($line['threshold']);

        return $line['ok']
            ? sprintf('%s coverage %s%% (>= %s%%, ok).', $label, $actual, $threshold)
            : sprintf('%s coverage %s%% < threshold %s%%.', $label, $actual, $threshold);
    }

    /**
     * Cast to string via the natural PHP coercion so integer-valued floats
     * print without a trailing `.0` (`80.0 → "80"`, `67.4 → "67.4"`). Matches
     * the issue's example output and how MarkdownCoverageRenderer formats
     * percentages.
     */
    private static function formatPercent(float $value): string
    {
        return (string) $value;
    }
}
