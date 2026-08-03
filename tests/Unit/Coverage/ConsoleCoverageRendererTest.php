<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\ConsoleCoverageRenderer;
use Studio\Gesso\Coverage\EndpointCoverageState;
use Studio\Gesso\Coverage\ResponseCoverageState;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\Tests\Helpers\SdkExerciseCoverageResultFixture;

use function explode;

class ConsoleCoverageRendererTest extends TestCase
{
    #[Test]
    public function render_returns_empty_string_for_empty_results(): void
    {
        $this->assertSame('', ConsoleCoverageRenderer::render([]));
    }

    #[Test]
    public function default_mode_renders_endpoint_summaries_without_sub_rows(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 3),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results);

        $this->assertStringContainsString('[front] endpoints: 1/1 fully covered (100%)', $output);
        $this->assertStringContainsString('responses: 1/1 covered (100%)', $output);
        $this->assertStringContainsString('✓ GET /v1/pets', $output);
        // No sub-rows in DEFAULT mode.
        $this->assertStringNotContainsString('200  application/json', $output);
    }

    #[Test]
    public function all_mode_renders_sub_rows_for_every_endpoint(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 3),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('200', $output);
        $this->assertStringContainsString('application/json', $output);
        $this->assertStringContainsString('[3]', $output);
    }

    #[Test]
    public function uncovered_only_mode_omits_sub_rows_for_fully_covered(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                    self::endpoint('POST /v1/pets', 'partial', responses: [
                        self::row('201', 'application/json', 'validated', hits: 1),
                        self::row('422', 'application/problem+json', 'uncovered'),
                    ], coveredResponseCount: 1, totalResponseCount: 2),
                ],
                endpointTotal: 2,
                endpointFullyCovered: 1,
                endpointPartial: 1,
                responseTotal: 3,
                responseCovered: 2,
                responseUncovered: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::UNCOVERED_ONLY);

        $this->assertStringContainsString('✓ GET /v1/pets', $output);
        $this->assertStringContainsString('◐ POST /v1/pets', $output);
        $this->assertStringContainsString('422', $output);
        $this->assertStringContainsString('uncovered', $output);
        // Pin the per-row validated suppression on the partial endpoint:
        // POST /v1/pets has a validated 201:application/json sub-row that
        // must NOT appear in UNCOVERED_ONLY mode (only the uncovered 422 row).
        $this->assertStringNotContainsString('201    application/json', $output);
    }

    #[Test]
    public function partial_endpoint_uses_orange_diamond_marker(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('POST /v1/pets', 'partial', responses: [
                        self::row('201', 'application/json', 'validated', hits: 1),
                        self::row('422', 'application/problem+json', 'uncovered'),
                    ], coveredResponseCount: 1, totalResponseCount: 2),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 2,
                responseCovered: 1,
                responseUncovered: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('◐ POST /v1/pets', $output);
        $this->assertStringContainsString('1/2 responses', $output);
    }

    #[Test]
    public function skipped_response_uses_warning_marker_with_skip_reason(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('DELETE /v1/pets/{petId}', 'partial', responses: [
                        self::row('204', '*', 'validated', hits: 2),
                        self::row('5XX', '*', 'skipped', hits: 1, skipReason: 'status 503 matched skip pattern 5\d\d'),
                    ], coveredResponseCount: 1, skippedResponseCount: 1, totalResponseCount: 2),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 2,
                responseCovered: 1,
                responseSkipped: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('⚠', $output);
        $this->assertStringContainsString('5XX', $output);
        $this->assertStringContainsString('skipped: status 503 matched skip pattern 5\d\d', $output);
        $this->assertStringContainsString('1/2 responses, 1 skipped', $output);
    }

    #[Test]
    public function uncovered_endpoint_marker(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/health', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointUncovered: 1,
                responseTotal: 1,
                responseUncovered: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('✗ GET /v1/health', $output);
    }

    #[Test]
    public function active_only_collapses_inactive_spec_to_single_line(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                ],
                endpointTotal: 373,
                endpointUncovered: 373,
                responseTotal: 894,
                responseUncovered: 894,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ACTIVE_ONLY);

        $this->assertStringContainsString('[front] no test activity (373 endpoints, 894 responses in spec)', $output);
        // Inactive specs must not render the per-endpoint summary block.
        $this->assertStringNotContainsString('endpoints: 0/373 fully covered', $output);
        $this->assertStringNotContainsString('✗ GET /v1/pets', $output);
    }

    #[Test]
    public function active_only_renders_full_block_for_active_spec(): void
    {
        $results = [
            'admin' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v2/admin/early_accesses', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ACTIVE_ONLY);

        $this->assertStringContainsString('[admin] endpoints: 1/1 fully covered (100%)', $output);
        $this->assertStringContainsString('✓ GET /v2/admin/early_accesses', $output);
        $this->assertStringNotContainsString('no test activity', $output);
    }

    #[Test]
    public function active_only_treats_request_only_endpoint_as_active(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/health', 'request-only', requestReached: true, totalResponseCount: 0),
                ],
                endpointTotal: 1,
                endpointRequestOnly: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ACTIVE_ONLY);

        $this->assertStringNotContainsString('no test activity', $output);
        $this->assertStringContainsString('· GET /v1/health', $output);
    }

    #[Test]
    public function active_only_treats_skipped_only_spec_as_active(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('DELETE /v1/pets/{petId}', 'partial', responses: [
                        self::row('5XX', '*', 'skipped', hits: 1, skipReason: 'status 503 matched skip pattern 5\d\d'),
                    ], skippedResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 1,
                responseSkipped: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ACTIVE_ONLY);

        $this->assertStringNotContainsString('no test activity', $output);
        $this->assertStringContainsString('[front] endpoints', $output);
        // Pin that the endpoint row actually rendered (not just the header).
        // A regression that emitted the summary block but skipped
        // renderEndpoints would otherwise pass.
        $this->assertStringContainsString('DELETE /v1/pets/{petId}', $output);
    }

    #[Test]
    public function active_only_collapses_every_spec_when_all_inactive(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                ],
                endpointTotal: 10,
                endpointUncovered: 10,
                responseTotal: 20,
                responseUncovered: 20,
            ),
            'admin' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v2/admin/early_accesses', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                ],
                endpointTotal: 5,
                endpointUncovered: 5,
                responseTotal: 8,
                responseUncovered: 8,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ACTIVE_ONLY);

        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $output);
        $this->assertStringContainsString('[front] no test activity (10 endpoints, 20 responses in spec)', $output);
        $this->assertStringContainsString('[admin] no test activity (5 endpoints, 8 responses in spec)', $output);
        // No legend or per-endpoint rows when every spec is collapsed.
        $this->assertStringNotContainsString('Legend:', $output);
        $this->assertStringNotContainsString('GET /v1/pets', $output);
        $this->assertStringNotContainsString('GET /v2/admin/early_accesses', $output);
    }

    #[Test]
    public function active_only_returns_empty_string_for_empty_results(): void
    {
        $this->assertSame('', ConsoleCoverageRenderer::render([], ConsoleOutput::ACTIVE_ONLY));
    }

    #[Test]
    public function active_only_mixes_collapsed_and_full_specs_across_specs(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                ],
                endpointTotal: 373,
                endpointUncovered: 373,
                responseTotal: 894,
                responseUncovered: 894,
            ),
            'admin' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v2/admin/early_accesses', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 72,
                endpointFullyCovered: 1,
                endpointUncovered: 71,
                responseTotal: 100,
                responseCovered: 1,
                responseUncovered: 99,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ACTIVE_ONLY);

        $this->assertStringContainsString('[front] no test activity (373 endpoints, 894 responses in spec)', $output);
        $this->assertStringContainsString('[admin] endpoints: 1/72 fully covered', $output);
        $this->assertStringContainsString('✓ GET /v2/admin/early_accesses', $output);
        // The inactive spec's endpoint rows must not leak into the report.
        $this->assertStringNotContainsString('GET /v1/pets', $output);
    }

    #[Test]
    public function unexpected_observation_appears_with_bang_prefix(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'partial', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1, unexpectedObservations: [
                        ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
                    ]),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $output = ConsoleCoverageRenderer::render($results, ConsoleOutput::ALL);

        $this->assertStringContainsString('! 418', $output);
        $this->assertStringContainsString('unexpected (not in spec)', $output);
    }

    #[Test]
    public function all_mode_renders_sdk_exercise_summary_rows_and_unexpected_observations(): void
    {
        $output = ConsoleCoverageRenderer::render(
            [],
            ConsoleOutput::ALL,
            ['front' => SdkExerciseCoverageResultFixture::result()],
        );

        $this->assertStringContainsString('[front] SDK responses: 1/2 exercised (50%), 1 unexercised', $output);
        $this->assertStringContainsString('✓ GET /v1/sdk-pets  200  application/json  [2]', $output);
        $this->assertStringContainsString('✗ GET /v1/sdk-pets  422  application/problem+json  unexercised', $output);
        $this->assertStringContainsString('! GET /v1/orphan  418  application/json  unexpected [3]', $output);
    }

    /**
     * @param list<array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}> $endpoints
     *
     * @return array{endpoints: list<array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}>, endpointTotal: int, endpointFullyCovered: int, endpointPartial: int, endpointUncovered: int, endpointRequestOnly: int, responseTotal: int, responseCovered: int, responseSkipped: int, responseUncovered: int}
     */
    private static function coverage(
        array $endpoints,
        int $endpointTotal = 0,
        int $endpointFullyCovered = 0,
        int $endpointPartial = 0,
        int $endpointUncovered = 0,
        int $endpointRequestOnly = 0,
        int $responseTotal = 0,
        int $responseCovered = 0,
        int $responseSkipped = 0,
        int $responseUncovered = 0,
    ): array {
        return [
            'endpoints' => $endpoints,
            'endpointTotal' => $endpointTotal,
            'endpointFullyCovered' => $endpointFullyCovered,
            'endpointPartial' => $endpointPartial,
            'endpointUncovered' => $endpointUncovered,
            'endpointRequestOnly' => $endpointRequestOnly,
            'responseTotal' => $responseTotal,
            'responseCovered' => $responseCovered,
            'responseSkipped' => $responseSkipped,
            'responseUncovered' => $responseUncovered,
        ];
    }

    /**
     * @param list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}> $responses
     * @param list<array{statusKey: string, contentTypeKey: string}> $unexpectedObservations
     *
     * @return array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}
     */
    private static function endpoint(
        string $endpoint,
        string $state,
        bool $requestReached = false,
        ?string $operationId = null,
        array $responses = [],
        int $coveredResponseCount = 0,
        int $skippedResponseCount = 0,
        int $totalResponseCount = 0,
        array $unexpectedObservations = [],
    ): array {
        [$method, $path] = explode(' ', $endpoint, 2);

        return [
            'endpoint' => $endpoint,
            'method' => $method,
            'path' => $path,
            'operationId' => $operationId,
            'state' => EndpointCoverageState::from($state),
            'requestReached' => $requestReached,
            'responses' => $responses,
            'coveredResponseCount' => $coveredResponseCount,
            'skippedResponseCount' => $skippedResponseCount,
            'totalResponseCount' => $totalResponseCount,
            'unexpectedObservations' => $unexpectedObservations,
        ];
    }

    /**
     * @return array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}
     */
    private static function row(
        string $statusKey,
        string $contentTypeKey,
        string $state,
        int $hits = 0,
        ?string $skipReason = null,
    ): array {
        return [
            'statusKey' => $statusKey,
            'contentTypeKey' => $contentTypeKey,
            'state' => ResponseCoverageState::from($state),
            'hits' => $hits,
            'skipReason' => $skipReason,
        ];
    }
}
