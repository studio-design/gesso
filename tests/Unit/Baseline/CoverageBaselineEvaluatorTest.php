<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\CoverageBaseline;
use Studio\Gesso\Baseline\CoverageBaselineEntry;
use Studio\Gesso\Baseline\CoverageBaselineEvaluator;
use Studio\Gesso\Coverage\EndpointCoverageState;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\ResponseCoverageState;

use function array_map;
use function count;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
 */
class CoverageBaselineEvaluatorTest extends TestCase
{
    #[Test]
    public function collect_returns_every_response_that_was_not_validated(): void
    {
        $baseline = CoverageBaselineEvaluator::collect([
            'front' => self::specResult([
                self::endpoint('GET', '/pets', [
                    ['200', 'application/json', ResponseCoverageState::Validated],
                    ['500', 'application/json', ResponseCoverageState::Uncovered],
                    ['503', '*', ResponseCoverageState::Skipped],
                ]),
            ]),
        ]);

        $this->assertSame(2, $baseline->count());
        $this->assertSame([
            '[front] GET /pets status=500 content-type=application/json',
            '[front] GET /pets status=503 content-type=*',
        ], array_map(static fn(CoverageBaselineEntry $e): string => $e->describe(), $baseline->sorted()));
    }

    #[Test]
    public function collect_keys_entries_by_spec_name(): void
    {
        $rows = [self::endpoint('GET', '/pets', [['200', '*', ResponseCoverageState::Uncovered]])];

        $baseline = CoverageBaselineEvaluator::collect([
            'front' => self::specResult($rows),
            'admin' => self::specResult($rows),
        ]);

        $this->assertSame(2, $baseline->count());
        $this->assertTrue($baseline->contains(new CoverageBaselineEntry('admin', 'GET', '/pets', '200', '*')));
        $this->assertTrue($baseline->contains(new CoverageBaselineEntry('front', 'GET', '/pets', '200', '*')));
    }

    #[Test]
    public function evaluate_passes_when_the_uncovered_set_matches_the_baseline(): void
    {
        $results = [
            'front' => self::specResult([
                self::endpoint('GET', '/pets', [
                    ['200', 'application/json', ResponseCoverageState::Validated],
                    ['500', 'application/json', ResponseCoverageState::Uncovered],
                ]),
            ]),
        ];

        $verdict = CoverageBaselineEvaluator::evaluate(
            self::baselineOf([['front', 'GET', '/pets', '500', 'application/json']]),
            $results,
        );

        $this->assertSame([], $verdict['regressions']);
        $this->assertSame([], $verdict['stale']);
        $this->assertSame(1, $verdict['uncovered']);
    }

    #[Test]
    public function evaluate_reports_a_newly_uncovered_response_as_a_regression(): void
    {
        $verdict = CoverageBaselineEvaluator::evaluate(
            self::baselineOf([['front', 'GET', '/pets', '500', 'application/json']]),
            [
                'front' => self::specResult([
                    self::endpoint('GET', '/pets', [
                        ['200', 'application/json', ResponseCoverageState::Uncovered],
                        ['500', 'application/json', ResponseCoverageState::Uncovered],
                    ]),
                ]),
            ],
        );

        $this->assertCount(1, $verdict['regressions']);
        $this->assertSame(
            '[front] GET /pets status=200 content-type=application/json',
            $verdict['regressions'][0]->describe(),
        );
        $this->assertSame([], $verdict['stale']);
    }

    #[Test]
    public function evaluate_ignores_a_newly_documented_response_that_is_covered(): void
    {
        // Issue #481 problem 2: documenting a response grows the denominator.
        // A percentage gate at the current value fails; a set gate does not,
        // as long as the new response is actually covered.
        $verdict = CoverageBaselineEvaluator::evaluate(
            self::baselineOf([['front', 'GET', '/pets', '500', 'application/json']]),
            [
                'front' => self::specResult([
                    self::endpoint('GET', '/pets', [
                        ['200', 'application/json', ResponseCoverageState::Validated],
                        ['503', 'application/json', ResponseCoverageState::Validated],
                        ['500', 'application/json', ResponseCoverageState::Uncovered],
                    ]),
                ]),
            ],
        );

        $this->assertSame([], $verdict['regressions']);
        $this->assertSame([], $verdict['stale']);
    }

    #[Test]
    public function evaluate_reports_a_now_covered_baseline_entry_as_stale(): void
    {
        $verdict = CoverageBaselineEvaluator::evaluate(
            self::baselineOf([
                ['front', 'GET', '/pets', '200', 'application/json'],
                ['front', 'GET', '/pets', '500', 'application/json'],
            ]),
            [
                'front' => self::specResult([
                    self::endpoint('GET', '/pets', [
                        ['200', 'application/json', ResponseCoverageState::Validated],
                        ['500', 'application/json', ResponseCoverageState::Uncovered],
                    ]),
                ]),
            ],
        );

        $this->assertSame([], $verdict['regressions']);
        $this->assertCount(1, $verdict['stale']);
        $this->assertSame(
            '[front] GET /pets status=200 content-type=application/json',
            $verdict['stale'][0]->describe(),
        );
    }

    #[Test]
    public function evaluate_reports_a_no_longer_declared_baseline_entry_as_stale(): void
    {
        $verdict = CoverageBaselineEvaluator::evaluate(
            self::baselineOf([['front', 'DELETE', '/pets/{id}', '204', '*']]),
            ['front' => self::specResult([])],
        );

        $this->assertSame([], $verdict['regressions']);
        $this->assertCount(1, $verdict['stale']);
    }

    #[Test]
    public function regression_message_names_every_offending_row(): void
    {
        $message = CoverageBaselineEvaluator::renderRegressionMessage(
            self::baselineOf([
                ['front', 'DELETE', '/pets/{id}', '204', '*'],
                ['front', 'GET', '/pets', '500', 'application/json'],
            ])->sorted(),
            'OPENAPI_BASELINE_GENERATE=1 vendor/bin/phpunit',
        );

        $this->assertStringContainsString('[Gesso] FATAL: 2 response(s) are not covered', $message);
        $this->assertStringContainsString("  - [front] DELETE /pets/{id} status=204 content-type=*\n", $message);
        $this->assertStringContainsString('  - [front] GET /pets status=500 content-type=application/json', $message);
        $this->assertStringContainsString('OPENAPI_BASELINE_GENERATE=1 vendor/bin/phpunit', $message);
        $this->assertStringEndsWith("\n", $message);
    }

    #[Test]
    public function stale_message_switches_severity(): void
    {
        $stale = self::baselineOf([['front', 'GET', '/pets', '200', '*']])->sorted();

        $this->assertStringContainsString(
            '[Gesso] NOTE: 1 coverage baseline entry(ies) are covered now',
            CoverageBaselineEvaluator::renderStaleMessage($stale, false),
        );
        $this->assertStringContainsString(
            '[Gesso] FATAL: 1 coverage baseline entry(ies) are covered now',
            CoverageBaselineEvaluator::renderStaleMessage($stale, true),
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $entries
     */
    private static function baselineOf(array $entries): CoverageBaseline
    {
        $baseline = new CoverageBaseline();
        foreach ($entries as [$spec, $method, $path, $status, $contentType]) {
            $baseline->add(new CoverageBaselineEntry($spec, $method, $path, $status, $contentType));
        }

        return $baseline;
    }

    /**
     * @param list<array{0: string, 1: string, 2: ResponseCoverageState}> $responses
     *
     * @return array{
     *     endpoint: string,
     *     method: string,
     *     path: string,
     *     operationId: ?string,
     *     state: EndpointCoverageState,
     *     requestReached: bool,
     *     responses: list<ResponseRow>,
     *     coveredResponseCount: int,
     *     skippedResponseCount: int,
     *     totalResponseCount: int,
     *     unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>,
     * }
     */
    private static function endpoint(string $method, string $path, array $responses): array
    {
        $rows = [];
        $covered = 0;
        $skipped = 0;
        foreach ($responses as [$statusKey, $contentTypeKey, $state]) {
            $rows[] = [
                'statusKey' => $statusKey,
                'contentTypeKey' => $contentTypeKey,
                'state' => $state,
                'hits' => $state === ResponseCoverageState::Uncovered ? 0 : 1,
                'skipReason' => null,
            ];
            if ($state === ResponseCoverageState::Validated) {
                $covered++;
            } elseif ($state === ResponseCoverageState::Skipped) {
                $skipped++;
            }
        }

        return [
            'endpoint' => $method . ' ' . $path,
            'method' => $method,
            'path' => $path,
            'operationId' => null,
            'state' => $covered === count($rows) ? EndpointCoverageState::AllCovered : EndpointCoverageState::Partial,
            'requestReached' => true,
            'responses' => $rows,
            'coveredResponseCount' => $covered,
            'skippedResponseCount' => $skipped,
            'totalResponseCount' => count($rows),
            'unexpectedObservations' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $endpoints
     *
     * @return CoverageResult
     */
    private static function specResult(array $endpoints): array
    {
        $responseTotal = 0;
        $responseCovered = 0;
        $responseSkipped = 0;
        foreach ($endpoints as $endpoint) {
            $responseTotal += $endpoint['totalResponseCount'];
            $responseCovered += $endpoint['coveredResponseCount'];
            $responseSkipped += $endpoint['skippedResponseCount'];
        }

        /** @phpstan-ignore return.type */
        return [
            'endpoints' => $endpoints,
            'endpointTotal' => count($endpoints),
            'endpointFullyCovered' => 0,
            'endpointPartial' => count($endpoints),
            'endpointUncovered' => 0,
            'endpointRequestOnly' => 0,
            'responseTotal' => $responseTotal,
            'responseCovered' => $responseCovered,
            'responseSkipped' => $responseSkipped,
            'responseUncovered' => $responseTotal - $responseCovered - $responseSkipped,
        ];
    }
}
