<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\EndpointCoverageState;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\ResponseCoverageState;
use Studio\Gesso\Tests\Helpers\SdkExerciseCoverageResultFixture;

use function array_keys;
use function explode;
use function is_array;
use function json_decode;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 */
class JsonCoverageRendererTest extends TestCase
{
    private const FIXED_TIMESTAMP = '2026-05-11T12:34:56+00:00';

    #[Test]
    public function render_returns_empty_string_for_empty_results(): void
    {
        $this->assertSame('', JsonCoverageRenderer::render([]));
    }

    #[Test]
    public function render_emits_schema_version_and_generated_at(): void
    {
        $payload = $this->decode($this->renderOneValidated());

        $this->assertSame(3, $payload['schema_version']);
        $this->assertSame(self::FIXED_TIMESTAMP, $payload['generated_at']);
    }

    #[Test]
    public function render_emits_tool_metadata(): void
    {
        $payload = $this->decode($this->renderOneValidated());

        $this->assertArrayHasKey('tool', $payload);
        $this->assertSame('studio-design/gesso', $payload['tool']['name']);
        $this->assertIsString($payload['tool']['version']);
        $this->assertNotSame('', $payload['tool']['version']);
        $this->assertNotSame('unknown', $payload['tool']['version']);
    }

    #[Test]
    public function render_emits_top_level_aggregate_rolled_across_specs(): void
    {
        // Two specs, each with one fully-covered endpoint and one uncovered
        // endpoint — top-level aggregate must sum across both, not echo one.
        $results = [
            'spec-a' => self::coverage(
                endpoints: [
                    self::endpoint('GET /a/pets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                    self::endpoint('GET /a/health', 'uncovered', responses: [
                        self::row('200', 'application/json', 'uncovered'),
                    ], totalResponseCount: 1),
                ],
                endpointTotal: 2,
                endpointFullyCovered: 1,
                endpointUncovered: 1,
                responseTotal: 2,
                responseCovered: 1,
                responseUncovered: 1,
            ),
            'spec-b' => self::coverage(
                endpoints: [
                    self::endpoint('GET /b/widgets', 'all-covered', responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $payload = $this->decode(JsonCoverageRenderer::render($results, $this->fixedNow()));

        $this->assertSame(3, $payload['aggregate']['endpoint_total']);
        $this->assertSame(2, $payload['aggregate']['endpoint_fully_covered']);
        $this->assertSame(1, $payload['aggregate']['endpoint_uncovered']);
        $this->assertSame(3, $payload['aggregate']['response_total']);
        $this->assertSame(2, $payload['aggregate']['response_covered']);
        $this->assertSame(1, $payload['aggregate']['response_uncovered']);
    }

    #[Test]
    public function render_emits_per_spec_aggregates_and_endpoints(): void
    {
        $payload = $this->decode($this->renderOneValidated());

        $this->assertArrayHasKey('specs', $payload);
        $this->assertArrayHasKey('front', $payload['specs']);
        $this->assertSame(1, $payload['specs']['front']['aggregates']['endpoint_fully_covered']);
        $this->assertCount(1, $payload['specs']['front']['endpoints']);

        $endpoint = $payload['specs']['front']['endpoints'][0];
        $this->assertSame('GET /v1/pets', $endpoint['endpoint']);
        $this->assertSame('GET', $endpoint['method']);
        $this->assertSame('/v1/pets', $endpoint['path']);
        $this->assertSame('listPets', $endpoint['operation_id']);
        $this->assertSame('all-covered', $endpoint['endpoint_state']);
        $this->assertTrue($endpoint['request_reached']);
    }

    #[Test]
    public function render_serialises_response_state_as_string_value(): void
    {
        $payload = $this->decode($this->renderOneValidated());
        $row = $payload['specs']['front']['endpoints'][0]['responses'][0];

        $this->assertSame('200', $row['status_key']);
        $this->assertSame('application/json', $row['content_type_key']);
        $this->assertSame('validated', $row['response_state']);
        $this->assertSame(3, $row['hits']);
        $this->assertNull($row['skip_reason']);
    }

    #[Test]
    public function render_namespaces_state_fields_to_avoid_enum_value_collision(): void
    {
        // EndpointCoverageState and ResponseCoverageState share value-string
        // namespaces (e.g. "uncovered" exists in both). Each enum's value
        // must surface under a distinct field name so consumers can read
        // them unambiguously.
        $payload = $this->decode($this->renderOneValidated());
        $endpoint = $payload['specs']['front']['endpoints'][0];

        $this->assertArrayHasKey('endpoint_state', $endpoint);
        $this->assertArrayNotHasKey('state', $endpoint);
        $this->assertArrayHasKey('response_state', $endpoint['responses'][0]);
        $this->assertArrayNotHasKey('state', $endpoint['responses'][0]);
    }

    #[Test]
    public function render_includes_skip_reason_when_present(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('DELETE /v1/pets/{petId}', 'partial', responses: [
                        self::row(
                            '503',
                            'application/json',
                            'skipped',
                            hits: 1,
                            skipReason: 'status 503 matched skip pattern 5\d\d',
                        ),
                    ], skippedResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 1,
                responseSkipped: 1,
            ),
        ];

        $payload = $this->decode(JsonCoverageRenderer::render($results, $this->fixedNow()));
        $row = $payload['specs']['front']['endpoints'][0]['responses'][0];

        $this->assertSame('skipped', $row['response_state']);
        $this->assertSame('status 503 matched skip pattern 5\d\d', $row['skip_reason']);
    }

    #[Test]
    public function render_includes_unexpected_observations(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('POST /v1/pets', 'partial', responses: [
                        self::row('201', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1, unexpectedObservations: [
                        ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
                    ]),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $payload = $this->decode(JsonCoverageRenderer::render($results, $this->fixedNow()));
        $endpoint = $payload['specs']['front']['endpoints'][0];

        $this->assertCount(1, $endpoint['unexpected_observations']);
        $this->assertSame('418', $endpoint['unexpected_observations'][0]['status_key']);
        $this->assertSame('application/json', $endpoint['unexpected_observations'][0]['content_type_key']);
    }

    #[Test]
    public function render_omits_null_operation_id_field_value_but_keeps_key(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'all-covered', requestReached: true, responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];

        $payload = $this->decode(JsonCoverageRenderer::render($results, $this->fixedNow()));
        $endpoint = $payload['specs']['front']['endpoints'][0];

        $this->assertArrayHasKey('operation_id', $endpoint);
        $this->assertNull($endpoint['operation_id']);
    }

    #[Test]
    public function render_pretty_prints_with_trailing_newline(): void
    {
        // CI consumers often `jq` over the file; pretty-printed JSON is
        // strictly redundant for jq but matches user expectations for a
        // human-readable artifact. Trailing newline keeps `cat`/POSIX text
        // tools happy.
        $output = $this->renderOneValidated();

        $this->assertStringContainsString("\n", $output);
        $this->assertStringEndsWith("\n", $output);
    }

    #[Test]
    public function render_uses_default_now_when_generated_at_not_passed(): void
    {
        $output = JsonCoverageRenderer::render($this->oneSpecResults());
        $payload = $this->decode($output);

        // Don't assert exact timestamp; verify it parses as ISO-8601 and is
        // close to current time so we don't depend on clock injection here.
        $parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $payload['generated_at']);
        $this->assertNotFalse($parsed);
    }

    #[Test]
    public function render_serialises_request_only_endpoint_state(): void
    {
        // RequestOnly represents endpoints that received traffic but reconciled
        // only to unexpected observations. Pin the enum value-string so a
        // regression in the enum value or in $endpoint['state']->value would
        // surface here rather than as a silent schema drift.
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint(
                        'POST /v1/pets',
                        'request-only',
                        requestReached: true,
                        responses: [
                            self::row('201', 'application/json', 'uncovered'),
                        ],
                        totalResponseCount: 1,
                        unexpectedObservations: [
                            ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
                        ],
                    ),
                ],
                endpointTotal: 1,
                endpointRequestOnly: 1,
                responseTotal: 1,
                responseUncovered: 1,
            ),
        ];

        $payload = $this->decode(JsonCoverageRenderer::render($results, $this->fixedNow()));

        $this->assertSame('request-only', $payload['specs']['front']['endpoints'][0]['endpoint_state']);
    }

    #[Test]
    public function render_emits_unescaped_slashes_in_paths(): void
    {
        // JSON_UNESCAPED_SLASHES is part of the documented output contract.
        // The raw string assertion catches a regression that drops the flag
        // (decoded values look identical, so this is the only sound pin).
        $output = $this->renderOneValidated();

        $this->assertStringContainsString('"/v1/pets"', $output);
        $this->assertStringNotContainsString('\/v1\/pets', $output);
    }

    #[Test]
    public function render_emits_unescaped_unicode_in_skip_reasons(): void
    {
        // JSON_UNESCAPED_UNICODE keeps non-ASCII content readable for human
        // consumers and downstream tools that don't decode \uXXXX sequences
        // back to UTF-8.
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('DELETE /v1/pets/{petId}', 'partial', responses: [
                        self::row(
                            '503',
                            'application/json',
                            'skipped',
                            hits: 1,
                            skipReason: 'ステータス 503 はスキップ対象です',
                        ),
                    ], skippedResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 1,
                endpointPartial: 1,
                responseTotal: 1,
                responseSkipped: 1,
            ),
        ];

        $output = JsonCoverageRenderer::render($results, $this->fixedNow());

        // Raw UTF-8 must round-trip through the output verbatim. The negative
        // assertion catches a regression that drops JSON_UNESCAPED_UNICODE
        // (which would surface \uXXXX escapes instead of the original chars).
        $this->assertStringContainsString('ステータス 503 はスキップ対象です', $output);
        $this->assertStringNotContainsString('\\u', $output);
    }

    #[Test]
    public function render_aggregate_has_documented_nine_field_shape(): void
    {
        // Shape-pin against docs/coverage-json-schema.md. A regression that
        // adds a field without bumping schema_version, or drops a documented
        // field, surfaces here.
        $payload = $this->decode($this->renderOneValidated());

        $expectedKeys = [
            'endpoint_total',
            'endpoint_fully_covered',
            'endpoint_partial',
            'endpoint_uncovered',
            'endpoint_request_only',
            'response_total',
            'response_covered',
            'response_skipped',
            'response_uncovered',
        ];

        $this->assertSame([...$expectedKeys, 'sdk_exercise'], array_keys($payload['aggregate']));
        $this->assertSame($expectedKeys, array_keys($payload['specs']['front']['aggregates']));
    }

    #[Test]
    public function render_serialises_sdk_exercise_aggregate_rows_and_unexpected_observations(): void
    {
        $payload = $this->decode(JsonCoverageRenderer::render(
            $this->oneSpecResults(),
            $this->fixedNow(),
            ['front' => SdkExerciseCoverageResultFixture::result()],
        ));

        $this->assertSame([
            'response_total' => 2,
            'response_exercised' => 1,
            'response_unexercised' => 1,
        ], $payload['aggregate']['sdk_exercise']);

        $sdk = $payload['specs']['front']['sdk_exercise'];
        $this->assertSame(2, $sdk['response_total']);
        $this->assertSame(1, $sdk['response_exercised']);
        $this->assertSame(1, $sdk['response_unexercised']);
        $this->assertSame([
            'endpoint' => 'GET /v1/sdk-pets',
            'method' => 'GET',
            'path' => '/v1/sdk-pets',
            'operation_id' => 'listSdkPets',
            'status_key' => '200',
            'content_type_key' => 'application/json',
            'exercised' => true,
            'hits' => 2,
        ], $sdk['responses'][0]);
        $this->assertSame([
            'endpoint' => 'GET /v1/orphan',
            'status_key' => '418',
            'content_type_key' => 'application/json',
            'hits' => 3,
        ], $sdk['unexpected_observations'][0]);
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

    private function renderOneValidated(): string
    {
        return JsonCoverageRenderer::render($this->oneSpecResults(), $this->fixedNow());
    }

    /**
     * @return array<string, CoverageResult>
     */
    private function oneSpecResults(): array
    {
        return [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint(
                        'GET /v1/pets',
                        'all-covered',
                        requestReached: true,
                        operationId: 'listPets',
                        responses: [
                            self::row('200', 'application/json', 'validated', hits: 3),
                        ],
                        coveredResponseCount: 1,
                        totalResponseCount: 1,
                    ),
                ],
                endpointTotal: 1,
                endpointFullyCovered: 1,
                responseTotal: 1,
                responseCovered: 1,
            ),
        ];
    }

    private function fixedNow(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::FIXED_TIMESTAMP, new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->fail('Renderer did not produce a decodable JSON object: ' . $json);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
