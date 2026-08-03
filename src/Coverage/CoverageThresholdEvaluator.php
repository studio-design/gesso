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
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 *
 * @phpstan-type ThresholdLine array{percent: float, threshold: float, ok: bool, evaluable: bool}
 * @phpstan-type ThresholdResult array{
 *     passed: bool,
 *     endpoint: ?ThresholdLine,
 *     response: ?ThresholdLine,
 *     sdkExercise: ?ThresholdLine,
 *     message: string,
 * }
 */
final class CoverageThresholdEvaluator
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     *
     * @return ThresholdResult
     */
    public static function evaluate(
        array $results,
        array $sdkResults,
        ?float $minEndpointPct,
        ?float $minResponsePct,
        ?float $minSdkExercisePct,
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

        $sdkExercised = 0;
        $sdkTotal = 0;
        foreach ($sdkResults as $result) {
            $sdkExercised += $result['responseExercised'];
            $sdkTotal += $result['responseTotal'];
        }

        $endpoint = $minEndpointPct === null
            ? null
            : self::buildLine($endpointCovered, $endpointTotal, $minEndpointPct);
        $response = $minResponsePct === null
            ? null
            : self::buildLine($responseCovered, $responseTotal, $minResponsePct);
        $sdkExercise = $minSdkExercisePct === null
            ? null
            : self::buildLine($sdkExercised, $sdkTotal, $minSdkExercisePct, emptyIsFailure: true);

        $passed = ($endpoint['ok'] ?? true) &&
            ($response['ok'] ?? true) &&
            ($sdkExercise['ok'] ?? true);

        $message = '';
        if (!$passed) {
            $message = self::renderMessage($endpoint, $response, $sdkExercise, $strict);
        }

        return [
            'passed' => $passed,
            'endpoint' => $endpoint,
            'response' => $response,
            'sdkExercise' => $sdkExercise,
            'message' => $message,
        ];
    }

    /**
     * @return ThresholdLine
     */
    private static function buildLine(
        int $covered,
        int $total,
        float $threshold,
        bool $emptyIsFailure = false,
    ): array {
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
            'ok' => (!$emptyIsFailure || $total > 0) && $percent >= $threshold,
            'evaluable' => !$emptyIsFailure || $total > 0,
        ];
    }

    /**
     * @param null|ThresholdLine $endpoint
     * @param null|ThresholdLine $response
     * @param null|ThresholdLine $sdkExercise
     */
    private static function renderMessage(
        ?array $endpoint,
        ?array $response,
        ?array $sdkExercise,
        bool $strict,
    ): string {
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
        if ($sdkExercise !== null) {
            $lead = $lines === [] ? $prefix : $indent;
            $lines[] = $lead . self::renderLine('SDK exercise', $sdkExercise);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param ThresholdLine $line
     */
    private static function renderLine(string $label, array $line): string
    {
        if (!$line['evaluable']) {
            return sprintf('%s coverage cannot be evaluated (no eligible response schemas).', $label);
        }

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
