<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\EndpointCoverageState;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\ResponseCoverageState;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

class ResponseValidationTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());
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
    public function non_json_endpoint_with_explicit_content_type_records_skipped(): void
    {
        // Same endpoint, but the caller supplies the actual response
        // Content-Type. The spec's `text/html` 200 entry declares a `schema`,
        // and OpenAPI permits a schema on any media type — but this engine
        // only evaluates JSON Schema, so the body cannot be checked. Issue
        // #254: matching the spec key must NOT credit the row as validated;
        // the result is Skipped and coverage records the skip against the
        // exact `text/html` media-type row (not the wildcard bucket).
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/logout',
            200,
            '<html><body>Logged out</body></html>',
            'text/html',
        );
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('text/html', $result->matchedContentType());
        $this->assertNotNull($result->skipReason());

        $this->recordResult('petstore-3.0', 'GET', $result);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        $logout = $endpoints['GET /v1/logout'];
        $this->assertSame(EndpointCoverageState::Partial, $logout['state']);
        $this->assertSame(1, $logout['skippedResponseCount']);

        // The skip is recorded against the concrete `text/html` row, so the
        // spec's declared media type is visibly accounted for as skipped.
        $textHtmlRow = null;
        foreach ($logout['responses'] as $row) {
            if ($row['contentTypeKey'] === 'text/html') {
                $textHtmlRow = $row;
            }
        }
        $this->assertNotNull($textHtmlRow);
        $this->assertSame(ResponseCoverageState::Skipped, $textHtmlRow['state']);
        $this->assertNotNull($textHtmlRow['skipReason']);
    }

    #[Test]
    public function content_negotiation_non_json_response_is_skipped_and_records_coverage(): void
    {
        // The 409 `text/html` entry declares a `schema`, so the matched
        // non-JSON Content-Type is Skipped (issue #254), not validated.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/html',
        );
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('text/html', $result->matchedContentType());

        $this->recordResult('petstore-3.0', 'POST', $result);

        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoints = $this->indexEndpoints($coverage['endpoints']);
        // text/html is one of two declared 409 content-types; it is recorded
        // as skipped (not validated), and the other (application/json)
        // remains uncovered, so the endpoint as a whole is partial.
        $this->assertSame(EndpointCoverageState::Partial, $endpoints['POST /v1/pets']['state']);

        $textHtmlRow = null;
        foreach ($endpoints['POST /v1/pets']['responses'] as $row) {
            if ($row['statusKey'] === '409' && $row['contentTypeKey'] === 'text/html') {
                $textHtmlRow = $row;
            }
        }
        $this->assertNotNull($textHtmlRow);
        $this->assertSame(ResponseCoverageState::Skipped, $textHtmlRow['state']);
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
