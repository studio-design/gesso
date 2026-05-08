<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\EndpointCoverageState;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Coverage\ResponseCoverageState;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiValidationResult;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

class ResponseValidationTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiResponseValidator();
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function full_pipeline_v30_validate_and_track_coverage(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        );
        $this->assertTrue($result->isValid());

        $this->recordResult('petstore-3.0', 'GET', $result);

        $result2 = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            201,
            ['data' => ['id' => 2, 'name' => 'Whiskers', 'tag' => 'cat']],
        );
        $this->assertTrue($result2->isValid());
        $this->recordResult('petstore-3.0', 'POST', $result2);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $this->assertSame(24, $coverage['endpointTotal']);
        $this->assertGreaterThanOrEqual(2, $coverage['endpointFullyCovered'] + $coverage['endpointPartial']);

        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        $this->assertSame(EndpointCoverageState::Partial, $endpoints['GET /v1/pets']['state']);
        // POST /v1/pets has multiple declared responses (201, 409 ×2, 415);
        // recording only 201 leaves the others uncovered → partial state.
        $this->assertSame(EndpointCoverageState::Partial, $endpoints['POST /v1/pets']['state']);
        $this->assertSame(EndpointCoverageState::Uncovered, $endpoints['GET /v1/health']['state']);
    }

    #[Test]
    public function full_pipeline_v31_validate_and_track_coverage(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        );
        $this->assertTrue($result->isValid());

        $this->recordResult('petstore-3.1', 'GET', $result);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.1');
        $this->assertSame(27, $coverage['endpointTotal']);
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        $this->assertContains($endpoints['GET /v1/pets']['state'], [EndpointCoverageState::Partial, EndpointCoverageState::AllCovered]);
    }

    #[Test]
    public function non_json_endpoint_records_skipped_when_no_content_type_supplied(): void
    {
        // The spec for GET /v1/logout 200 declares only `text/html` — no JSON
        // schema engine to validate against. Without an explicit response
        // Content-Type, the validator cannot even check spec presence; it
        // returns Skipped so coverage reflects that no validation actually
        // occurred (vs the pre-#111 silent Success that would have inflated
        // coverage of the `text/html` declaration).
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/logout',
            200,
            '<html><body>Logged out</body></html>',
        );
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());

        $this->recordResult('petstore-3.0', 'GET', $result);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        $this->assertSame(EndpointCoverageState::Partial, $endpoints['GET /v1/logout']['state']);
        $this->assertSame(1, $endpoints['GET /v1/logout']['skippedResponseCount']);
    }

    #[Test]
    public function non_json_endpoint_with_explicit_content_type_marks_validated(): void
    {
        // Same endpoint, but the caller supplies the actual response
        // Content-Type. The body validator can confirm `text/html` is in
        // the spec's content map and credits the coverage row as validated.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/logout',
            200,
            '<html><body>Logged out</body></html>',
            'text/html',
        );
        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());

        $this->recordResult('petstore-3.0', 'GET', $result);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        $this->assertSame(EndpointCoverageState::AllCovered, $endpoints['GET /v1/logout']['state']);
    }

    #[Test]
    public function content_negotiation_non_json_response_succeeds_and_records_coverage(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/html',
        );
        $this->assertTrue($result->isValid());

        $this->recordResult('petstore-3.0', 'POST', $result);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        // text/html is one of two declared 409 content-types; the other (application/json)
        // remains uncovered, so the endpoint as a whole is partial.
        $this->assertSame(EndpointCoverageState::Partial, $endpoints['POST /v1/pets']['state']);
    }

    #[Test]
    public function response_500_marks_skipped_response_with_range_reconciliation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['anything' => 'goes'],
        );
        $this->recordResult('petstore-3.0', 'GET', $result);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        $this->assertSame(EndpointCoverageState::Partial, $endpoints['GET /v1/pets']['state']);
        // petstore-3.0 declares `500: application/json` literally — recording
        // matches it exactly, so it shows up as `skipped` (not in
        // unexpectedObservations).
        $hadSkipped = false;
        foreach ($endpoints['GET /v1/pets']['responses'] as $row) {
            if ($row['statusKey'] === '500' && $row['state'] === ResponseCoverageState::Skipped) {
                $hadSkipped = true;

                break;
            }
        }
        $this->assertTrue($hadSkipped, 'Expected a skipped row for status 500');
    }

    #[Test]
    public function response_200_then_500_keeps_pair_validated_independently(): void
    {
        $ok = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        );
        $this->recordResult('petstore-3.0', 'GET', $ok);

        $skip = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['anything' => 'goes'],
        );
        $this->recordResult('petstore-3.0', 'GET', $skip);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        $rowsByKey = [];
        foreach ($endpoints['GET /v1/pets']['responses'] as $row) {
            $rowsByKey[$row['statusKey'] . ':' . $row['contentTypeKey']] = $row;
        }

        // 200:application/json validated independently of 500 skipped.
        $this->assertSame(ResponseCoverageState::Validated, $rowsByKey['200:application/json']['state']);
        $this->assertSame(ResponseCoverageState::Skipped, $rowsByKey['500:application/json']['state']);
    }

    #[Test]
    public function response_500_then_200_keeps_pair_validated_independently(): void
    {
        $skip = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['anything' => 'goes'],
        );
        $this->recordResult('petstore-3.0', 'GET', $skip);

        $ok = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        );
        $this->recordResult('petstore-3.0', 'GET', $ok);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        $rowsByKey = [];
        foreach ($endpoints['GET /v1/pets']['responses'] as $row) {
            $rowsByKey[$row['statusKey'] . ':' . $row['contentTypeKey']] = $row;
        }

        $this->assertSame(ResponseCoverageState::Validated, $rowsByKey['200:application/json']['state']);
        $this->assertSame(ResponseCoverageState::Skipped, $rowsByKey['500:application/json']['state']);
    }

    #[Test]
    public function invalid_response_produces_descriptive_errors(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['wrong_key' => 'value'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
        $this->assertNotEmpty($result->errorMessage());
    }

    /**
     * Mirror what {@see ValidatesOpenApiSchema}
     * does so the integration test exercises the same coverage path.
     */
    private function recordResult(
        string $specName,
        string $method,
        OpenApiValidationResult $result,
    ): void {
        if ($result->matchedPath() === null) {
            return;
        }

        OpenApiCoverageTracker::recordResponse(
            $specName,
            $method,
            $result->matchedPath(),
            $result->matchedStatusCode() ?? '0',
            $result->matchedContentType(),
            schemaValidated: !$result->isSkipped(),
            skipReason: $result->skipReason(),
        );
    }

    /**
     * @param list<array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}> $endpoints
     *
     * @return array<string, array{endpoint: string, method: string, path: string, operationId: ?string, state: EndpointCoverageState, requestReached: bool, responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>, coveredResponseCount: int, skippedResponseCount: int, totalResponseCount: int, unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>}>
     */
    private function indexEndpoints(array $endpoints): array
    {
        $indexed = [];
        foreach ($endpoints as $endpoint) {
            $indexed[$endpoint['endpoint']] = $endpoint;
        }

        return $indexed;
    }
}
