<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageThresholdEvaluator;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\SdkExerciseCoverageReportBuilder;

use function explode;
use function str_repeat;
use function substr;
use function trim;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 */
class CoverageThresholdEvaluatorTest extends TestCase
{
    #[Test]
    public function passes_when_both_thresholds_met(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 8, endpointTotal: 10, responseCovered: 7, responseTotal: 10),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: 70.0,
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertTrue($result['passed']);
        $this->assertSame('', $result['message']);
        $this->assertNotNull($result['endpoint']);
        $this->assertSame(80.0, $result['endpoint']['percent']);
        $this->assertSame(80.0, $result['endpoint']['threshold']);
        $this->assertTrue($result['endpoint']['ok']);
        $this->assertNotNull($result['response']);
        $this->assertSame(70.0, $result['response']['percent']);
        $this->assertTrue($result['response']['ok']);
    }

    #[Test]
    public function fails_when_endpoint_threshold_unmet_and_strict(): void
    {
        // endpointFullyCovered/endpointTotal = 67/99 → 67.7%
        // responseCovered/responseTotal = 80/100 → 80% (>= 70%)
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 67, endpointTotal: 99, responseCovered: 80, responseTotal: 100),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: 70.0,
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('[OpenAPI Coverage] FAIL:', $result['message']);
        $this->assertStringContainsString('endpoint coverage 67.7% < threshold 80%', $result['message']);
        $this->assertStringContainsString('response coverage 80% (>= 70%, ok)', $result['message']);
        $this->assertNotNull($result['endpoint']);
        $this->assertFalse($result['endpoint']['ok']);
        $this->assertNotNull($result['response']);
        $this->assertTrue($result['response']['ok']);
    }

    #[Test]
    public function fails_when_response_threshold_unmet(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 9, endpointTotal: 10, responseCovered: 5, responseTotal: 10),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: 70.0,
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('endpoint coverage 90% (>= 80%, ok)', $result['message']);
        $this->assertStringContainsString('response coverage 50% < threshold 70%', $result['message']);
    }

    #[Test]
    public function fails_when_both_thresholds_unmet(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 5, endpointTotal: 10, responseCovered: 5, responseTotal: 10),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: 70.0,
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('endpoint coverage 50% < threshold 80%', $result['message']);
        $this->assertStringContainsString('response coverage 50% < threshold 70%', $result['message']);
    }

    #[Test]
    public function uses_warn_prefix_when_not_strict(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 5, endpointTotal: 10, responseCovered: 5, responseTotal: 10),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: null,
            minSdkExercisePct: null,
            strict: false,
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('[OpenAPI Coverage] WARN:', $result['message']);
        $this->assertStringNotContainsString('FAIL:', $result['message']);
    }

    #[Test]
    public function omits_unset_threshold_from_message(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 5, endpointTotal: 10, responseCovered: 5, responseTotal: 10),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: null, // not configured
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('endpoint coverage 50% < threshold 80%', $result['message']);
        $this->assertStringNotContainsString('response coverage', $result['message']);
        $this->assertNotNull($result['endpoint']);
        $this->assertNull($result['response']);
    }

    #[Test]
    public function returns_passed_with_empty_message_when_no_thresholds_configured(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 0, endpointTotal: 100, responseCovered: 0, responseTotal: 100),
            ],
            sdkResults: [],
            minEndpointPct: null,
            minResponsePct: null,
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertTrue($result['passed']);
        $this->assertSame('', $result['message']);
        $this->assertNull($result['endpoint']);
        $this->assertNull($result['response']);
    }

    #[Test]
    public function treats_zero_total_as_passing(): void
    {
        // endpointTotal = 0 → vacuously meets any threshold (no endpoints to fail).
        // The "no coverage recorded" edge case is handled upstream by hasAnyCoverage().
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 0, endpointTotal: 0, responseCovered: 0, responseTotal: 0),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: 70.0,
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertTrue($result['passed']);
        $this->assertSame('', $result['message']);
    }

    #[Test]
    public function aggregates_counts_across_specs(): void
    {
        // front: 10/20 endpoints, 30/40 responses
        // admin: 5/30 endpoints, 10/60 responses
        // total: endpoint 15/50 = 30%, response 40/100 = 40%
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 10, endpointTotal: 20, responseCovered: 30, responseTotal: 40),
                'admin' => self::counts(endpointFullyCovered: 5, endpointTotal: 30, responseCovered: 10, responseTotal: 60),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: 50.0,
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['endpoint']);
        $this->assertSame(30.0, $result['endpoint']['percent']);
        $this->assertNotNull($result['response']);
        $this->assertSame(40.0, $result['response']['percent']);
        $this->assertStringContainsString('endpoint coverage 30% < threshold 80%', $result['message']);
        $this->assertStringContainsString('response coverage 40% < threshold 50%', $result['message']);
    }

    #[Test]
    public function passes_when_actual_equals_threshold(): void
    {
        // 8/10 = 80% == threshold 80% → ok (>= is the pass condition).
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 8, endpointTotal: 10, responseCovered: 8, responseTotal: 10),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: 80.0,
            minSdkExercisePct: null,
            strict: true,
        );

        $this->assertTrue($result['passed']);
    }

    #[Test]
    public function aligns_second_message_line_to_label_indent(): void
    {
        // Issue's example shows the second metric line indented to the column
        // where the first line's metric starts. Pin so future renaming of the
        // prefix keeps the visual alignment.
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 5, endpointTotal: 10, responseCovered: 7, responseTotal: 10),
            ],
            sdkResults: [],
            minEndpointPct: 80.0,
            minResponsePct: 60.0,
            minSdkExercisePct: null,
            strict: true,
        );

        $lines = explode("\n", trim($result['message']));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('[OpenAPI Coverage] FAIL: endpoint coverage', $lines[0]);
        // Second line must start with whitespace whose length equals
        // "[OpenAPI Coverage] FAIL: " (25 chars), so "response" lands in the
        // same column as "endpoint".
        $this->assertSame(str_repeat(' ', 25), substr($lines[1], 0, 25));
        $this->assertStringStartsWith('response coverage', substr($lines[1], 25));
    }

    #[Test]
    public function evaluates_an_sdk_only_threshold_across_specs(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [],
            sdkResults: [
                'front' => self::sdkCounts(exercised: 3, total: 4),
                'admin' => self::sdkCounts(exercised: 1, total: 1),
            ],
            minEndpointPct: null,
            minResponsePct: null,
            minSdkExercisePct: 80.0,
            strict: true,
        );

        $this->assertTrue($result['passed']);
        $this->assertNull($result['endpoint']);
        $this->assertNull($result['response']);
        $this->assertNotNull($result['sdkExercise']);
        $this->assertSame(80.0, $result['sdkExercise']['percent']);
        $this->assertTrue($result['sdkExercise']['ok']);
    }

    #[Test]
    public function reports_sdk_miss_alongside_passing_http_metrics(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [
                'front' => self::counts(endpointFullyCovered: 10, endpointTotal: 10, responseCovered: 9, responseTotal: 10),
            ],
            sdkResults: ['front' => self::sdkCounts(exercised: 1, total: 2)],
            minEndpointPct: 100.0,
            minResponsePct: 90.0,
            minSdkExercisePct: 100.0,
            strict: false,
        );

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('endpoint coverage 100% (>= 100%, ok)', $result['message']);
        $this->assertStringContainsString('response coverage 90% (>= 90%, ok)', $result['message']);
        $this->assertStringContainsString('SDK exercise coverage 50% < threshold 100%', $result['message']);
        $this->assertStringContainsString('[OpenAPI Coverage] WARN:', $result['message']);
    }

    #[Test]
    public function sdk_zero_percent_meets_a_zero_threshold(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [],
            sdkResults: ['front' => self::sdkCounts(exercised: 0, total: 2)],
            minEndpointPct: null,
            minResponsePct: null,
            minSdkExercisePct: 0.0,
            strict: true,
        );

        $this->assertTrue($result['passed']);
    }

    #[Test]
    public function empty_sdk_denominator_is_an_unevaluable_miss(): void
    {
        $result = CoverageThresholdEvaluator::evaluate(
            results: [],
            sdkResults: ['front' => self::sdkCounts(exercised: 0, total: 0)],
            minEndpointPct: null,
            minResponsePct: null,
            minSdkExercisePct: 0.0,
            strict: true,
        );

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['sdkExercise']);
        $this->assertFalse($result['sdkExercise']['ok']);
        $this->assertStringContainsString('SDK exercise coverage cannot be evaluated (no eligible response schemas)', $result['message']);
    }

    /**
     * Build a minimal CoverageResult-shaped array carrying only the counts the
     * evaluator reads. The other fields are not relevant to threshold gating;
     * defaults match the empty/zero shape so a typo can't accidentally inflate
     * a metric.
     *
     * @return CoverageResult
     */
    private static function counts(
        int $endpointFullyCovered,
        int $endpointTotal,
        int $responseCovered,
        int $responseTotal,
    ): array {
        return [
            'endpoints' => [],
            'endpointTotal' => $endpointTotal,
            'endpointFullyCovered' => $endpointFullyCovered,
            'endpointPartial' => 0,
            'endpointUncovered' => 0,
            'endpointRequestOnly' => 0,
            'responseTotal' => $responseTotal,
            'responseCovered' => $responseCovered,
            'responseSkipped' => 0,
            'responseUncovered' => 0,
        ];
    }

    /** @return SdkExerciseCoverageResult */
    private static function sdkCounts(int $exercised, int $total): array
    {
        return [
            'responses' => [],
            'responseTotal' => $total,
            'responseExercised' => $exercised,
            'responseUnexercised' => $total - $exercised,
            'unexpectedObservations' => [],
        ];
    }
}
