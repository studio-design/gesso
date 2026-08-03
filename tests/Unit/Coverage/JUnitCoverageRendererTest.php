<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Studio\Gesso\Coverage\EndpointCoverageState;
use Studio\Gesso\Coverage\JUnitCoverageRenderer;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\ResponseCoverageState;
use Studio\Gesso\Tests\Helpers\SdkExerciseCoverageResultFixture;

use function explode;
use function simplexml_load_string;

/**
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 */
class JUnitCoverageRendererTest extends TestCase
{
    #[Test]
    public function render_returns_empty_string_for_empty_results(): void
    {
        $this->assertSame('', JUnitCoverageRenderer::render([]));
    }

    #[Test]
    public function render_validated_response_emits_testcase_without_failure(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets', 'all-covered', requestReached: true, operationId: 'listPets', responses: [
                self::row('200', 'application/json', 'validated', hits: 3),
            ], coveredResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $sx = $this->parse($xml);
        $testcases = $sx->xpath('//testcase');
        $this->assertCount(1, $testcases);

        $tc = $testcases[0];
        $this->assertSame('openapi.coverage.front', (string) $tc['classname']);
        $this->assertSame('GET /v1/pets [200 application/json]', (string) $tc['name']);
        $this->assertSame('0', (string) $tc['time']);
        $this->assertSame([], $tc->xpath('failure') ?: []);
        $this->assertSame([], $tc->xpath('skipped') ?: []);
    }

    #[Test]
    public function render_uncovered_response_emits_failure_element(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('GET /v1/health', 'uncovered', responses: [
                self::row('200', 'application/json', 'uncovered'),
            ], totalResponseCount: 1),
            endpointTotal: 1,
            endpointUncovered: 1,
            responseTotal: 1,
            responseUncovered: 1,
        );

        $sx = $this->parse($xml);
        $failures = $sx->xpath('//testcase/failure');
        $this->assertCount(1, $failures);
        $this->assertSame('UncoveredResponse', (string) $failures[0]['type']);
        $this->assertStringContainsString('200 application/json', (string) $failures[0]['message']);
        $this->assertStringContainsString('GET /v1/health', (string) $failures[0]['message']);
    }

    #[Test]
    public function render_skipped_response_emits_skipped_element_with_reason(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('DELETE /v1/pets/{petId}', 'partial', responses: [
                self::row(
                    '503',
                    'application/json',
                    'skipped',
                    hits: 1,
                    skipReason: 'status 503 matched skip pattern 5\d\d',
                ),
            ], skippedResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointPartial: 1,
            responseTotal: 1,
            responseSkipped: 1,
        );

        $sx = $this->parse($xml);
        $skipped = $sx->xpath('//testcase/skipped');
        $this->assertCount(1, $skipped);
        $this->assertStringContainsString('5\d\d', (string) $skipped[0]['message']);
    }

    #[Test]
    public function render_endpoint_with_no_response_definitions_emits_synthetic_skipped(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('GET /v1/health', 'request-only', requestReached: true),
            endpointTotal: 1,
            endpointRequestOnly: 1,
        );

        $sx = $this->parse($xml);
        $testcases = $sx->xpath('//testcase');
        $this->assertCount(1, $testcases);
        $skipped = $testcases[0]->xpath('skipped');
        $this->assertCount(1, $skipped);
        $this->assertStringContainsString('no response definitions', (string) $skipped[0]['message']);
    }

    #[Test]
    public function render_unexpected_observation_emits_failure_testcase(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('POST /v1/pets', 'partial', responses: [
                self::row('201', 'application/json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1, unexpectedObservations: [
                ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
            ]),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $sx = $this->parse($xml);
        $failures = $sx->xpath('//testcase/failure[@type="UnexpectedObservation"]');
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('418', (string) $failures[0]['message']);
    }

    #[Test]
    public function render_includes_hits_and_operation_id_in_system_out(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets', 'all-covered', requestReached: true, operationId: 'listPets', responses: [
                self::row('200', 'application/json', 'validated', hits: 7),
            ], coveredResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $sx = $this->parse($xml);
        $systemOut = $sx->xpath('//testcase/system-out');
        $this->assertCount(1, $systemOut);
        $text = (string) $systemOut[0];
        $this->assertStringContainsString('hits=7', $text);
        $this->assertStringContainsString('operationId=listPets', $text);
    }

    #[Test]
    public function render_omits_operation_id_when_missing(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets', 'all-covered', requestReached: true, responses: [
                self::row('200', 'application/json', 'validated', hits: 2),
            ], coveredResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $sx = $this->parse($xml);
        $systemOut = $sx->xpath('//testcase/system-out');
        $this->assertCount(1, $systemOut);
        $this->assertStringNotContainsString('operationId=', (string) $systemOut[0]);
    }

    #[Test]
    public function render_uses_dotted_classname_for_sonarqube_compat(): void
    {
        $xml = $this->renderOne(
            'pet-store',
            self::endpoint('GET /v1/pets', 'all-covered', requestReached: true, responses: [
                self::row('200', 'application/json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $sx = $this->parse($xml);
        $tc = $sx->xpath('//testcase')[0];
        $this->assertSame('openapi.coverage.pet-store', (string) $tc['classname']);
    }

    #[Test]
    public function render_wraps_in_testsuites_root_with_time_zero(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets', 'all-covered', requestReached: true, responses: [
                self::row('200', 'application/json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $sx = $this->parse($xml);
        $this->assertSame('testsuites', $sx->getName());
        $this->assertSame('openapi-contract-coverage', (string) $sx['name']);
        $this->assertSame('0', (string) $sx['time']);

        $suite = $sx->xpath('testsuite')[0];
        $this->assertSame('front', (string) $suite['name']);
        $this->assertSame('0', (string) $suite['time']);

        $tc = $sx->xpath('//testcase')[0];
        $this->assertSame('0', (string) $tc['time']);
    }

    #[Test]
    public function render_aggregates_counts_at_testsuites_and_testsuite_level(): void
    {
        $results = [
            'front' => self::coverage(
                endpoints: [
                    self::endpoint('GET /v1/pets', 'all-covered', requestReached: true, responses: [
                        self::row('200', 'application/json', 'validated', hits: 1),
                    ], coveredResponseCount: 1, totalResponseCount: 1),
                    self::endpoint('POST /v1/pets', 'partial', responses: [
                        self::row('201', 'application/json', 'validated', hits: 1),
                        self::row('422', 'application/problem+json', 'uncovered'),
                    ], coveredResponseCount: 1, totalResponseCount: 2),
                    self::endpoint('DELETE /v1/pets/{id}', 'partial', responses: [
                        self::row('204', 'application/json', 'skipped', hits: 1, skipReason: 'manual skip'),
                    ], skippedResponseCount: 1, totalResponseCount: 1),
                ],
                endpointTotal: 3,
                endpointFullyCovered: 1,
                endpointPartial: 2,
                responseTotal: 4,
                responseCovered: 2,
                responseSkipped: 1,
                responseUncovered: 1,
            ),
        ];

        $xml = JUnitCoverageRenderer::render($results);
        $sx = $this->parse($xml);

        $this->assertSame('4', (string) $sx['tests']);
        $this->assertSame('1', (string) $sx['failures']);
        $this->assertSame('1', (string) $sx['skipped']);

        $suite = $sx->xpath('testsuite')[0];
        $this->assertSame('4', (string) $suite['tests']);
        $this->assertSame('1', (string) $suite['failures']);
        $this->assertSame('1', (string) $suite['skipped']);
    }

    #[Test]
    public function render_xml_escapes_special_characters_in_attributes(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('GET /v1/items<&"\'', 'uncovered', responses: [
                self::row('200', 'application/json', 'uncovered'),
            ], totalResponseCount: 1),
            endpointTotal: 1,
            endpointUncovered: 1,
            responseTotal: 1,
            responseUncovered: 1,
        );

        // Round-trip via SimpleXML proves the document is well-formed.
        $sx = $this->parse($xml);
        $tc = $sx->xpath('//testcase')[0];
        $this->assertStringContainsString('<&"\'', (string) $tc['name']);
    }

    #[Test]
    public function render_includes_xml_declaration_and_utf8_encoding(): void
    {
        $xml = $this->renderOne(
            'front',
            self::endpoint('GET /v1/pets', 'all-covered', requestReached: true, responses: [
                self::row('200', 'application/json', 'validated', hits: 1),
            ], coveredResponseCount: 1, totalResponseCount: 1),
            endpointTotal: 1,
            endpointFullyCovered: 1,
            responseTotal: 1,
            responseCovered: 1,
        );

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"', $xml);
    }

    #[Test]
    public function render_maps_sdk_schemas_to_exercised_unexercised_and_unexpected_cases(): void
    {
        $xml = JUnitCoverageRenderer::render(
            [],
            ['front' => SdkExerciseCoverageResultFixture::result()],
        );
        $sx = $this->parse($xml);
        $cases = $sx->xpath('//testcase[@classname="front.sdk-exercise"]');

        $this->assertCount(3, $cases);
        $this->assertSame('3', (string) $sx['tests']);
        $this->assertSame('2', (string) $sx['failures']);
        $this->assertCount(0, $cases[0]->xpath('failure'));
        $this->assertSame('UnexercisedSdkResponseSchema', (string) $cases[1]->failure['type']);
        $this->assertSame('UnexpectedSdkExerciseObservation', (string) $cases[2]->failure['type']);
        $this->assertStringContainsString('hits=2', (string) $cases[0]->{'system-out'});
        $this->assertStringContainsString('operationId=listSdkPets', (string) $cases[0]->{'system-out'});
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

    /**
     * Convenience: render a single-endpoint coverage result for a single spec.
     *
     * @param EndpointSummary $endpoint
     */
    private function renderOne(
        string $specName,
        array $endpoint,
        int $endpointTotal = 0,
        int $endpointFullyCovered = 0,
        int $endpointPartial = 0,
        int $endpointUncovered = 0,
        int $endpointRequestOnly = 0,
        int $responseTotal = 0,
        int $responseCovered = 0,
        int $responseSkipped = 0,
        int $responseUncovered = 0,
    ): string {
        $results = [
            $specName => self::coverage(
                endpoints: [$endpoint],
                endpointTotal: $endpointTotal,
                endpointFullyCovered: $endpointFullyCovered,
                endpointPartial: $endpointPartial,
                endpointUncovered: $endpointUncovered,
                endpointRequestOnly: $endpointRequestOnly,
                responseTotal: $responseTotal,
                responseCovered: $responseCovered,
                responseSkipped: $responseSkipped,
                responseUncovered: $responseUncovered,
            ),
        ];

        return JUnitCoverageRenderer::render($results);
    }

    private function parse(string $xml): SimpleXMLElement
    {
        $sx = simplexml_load_string($xml);
        if ($sx === false) {
            $this->fail('Rendered XML is not parseable: ' . $xml);
        }

        return $sx;
    }
}
