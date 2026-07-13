<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Fuzz;

use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Fuzz\ExplorationCaseKind;
use Studio\OpenApiContractTesting\Fuzz\ExplorationCases;
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;
use Studio\OpenApiContractTesting\Fuzz\OpenApiEndpointExplorer;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function json_decode;
use function json_encode;
use function str_repeat;

class OpenApiEndpointExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function rejects_zero_cases(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 0);
    }

    #[Test]
    public function rejects_unsupported_openapi_version(): void
    {
        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage("Unsupported OpenAPI version: '3.3.0' (string)");

        OpenApiEndpointExplorer::explore('unsupported-version', 'GET', '/v1/pets', cases: 1);
    }

    #[Test]
    public function rejects_unknown_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not declared');

        OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/does/not/exist', cases: 1);
    }

    #[Test]
    public function rejects_method_not_on_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation');

        // POST /v1/pets/{petId} is not declared in petstore-3.0
        OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets/{petId}', cases: 1);
    }

    #[Test]
    public function returns_exploration_cases_collection(): void
    {
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 3, seed: 1);

        $this->assertInstanceOf(ExplorationCases::class, $cases);
        $this->assertCount(3, $cases);
        foreach ($cases as $case) {
            $this->assertInstanceOf(ExploredCase::class, $case);
            $this->assertSame(HttpMethod::POST, $case->method);
            $this->assertSame('/v1/pets', $case->matchedPath);
        }
    }

    #[Test]
    public function explores_openapi_32_query_operation(): void
    {
        $cases = OpenApiEndpointExplorer::explore('openapi-3.2', 'QUERY', '/v1/search', cases: 2, seed: 1);

        foreach ($cases as $case) {
            $this->assertSame(HttpMethod::QUERY, $case->method);
            $this->assertSame('/v1/search', $case->matchedPath);
            $this->assertIsArray($case->body);
            $this->assertArrayHasKey('term', $case->body);
        }
    }

    #[Test]
    public function generates_request_body_conforming_to_spec(): void
    {
        // POST /v1/pets requires { name: string, tag?: string|null }
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 5, seed: 7);

        $bodySchema = (object) [
            'type' => 'object',
            'required' => ['name'],
            'properties' => (object) [
                'name' => (object) ['type' => 'string'],
                'tag' => (object) ['type' => ['string', 'null']],
            ],
        ];
        $validator = new Validator();

        foreach ($cases as $case) {
            $this->assertIsArray($case->body, 'POST /v1/pets must produce an object body');
            $bodyJson = json_decode((string) json_encode($case->body));
            $result = $validator->validate($bodyJson, $bodySchema);
            $this->assertTrue($result->isValid(), 'generated body must satisfy spec schema');
        }
    }

    #[Test]
    public function generates_query_parameter_values(): void
    {
        // POST /v1/pets has a `dryRun` boolean query parameter.
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 4, seed: 1);

        $sawValue = false;
        foreach ($cases as $case) {
            if (isset($case->query['dryRun'])) {
                $this->assertIsBool($case->query['dryRun']);
                $sawValue = true;
            }
        }
        $this->assertTrue($sawValue, 'at least one case should generate the dryRun query param');
    }

    #[Test]
    public function generates_fixed_quantifier_pattern_for_query_parameter(): void
    {
        $cases = OpenApiEndpointExplorer::explore(
            'fuzz-fixed-quantifier-query',
            'GET',
            '/oauth/callback',
            cases: 1,
            seed: 1,
        );

        foreach ($cases as $case) {
            $this->assertSame(str_repeat('a', 64), $case->query['state']);
        }
    }

    #[Test]
    public function generates_path_parameters_for_template_form(): void
    {
        // /v1/pets/{petId} has a path param `petId` of type integer.
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'GET', '/v1/pets/{petId}', cases: 2, seed: 1);

        foreach ($cases as $case) {
            $this->assertSame('/v1/pets/{petId}', $case->matchedPath);
            $this->assertArrayHasKey('petId', $case->pathParams);
        }
    }

    #[Test]
    public function resolves_concrete_uri_to_spec_template(): void
    {
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'GET', '/v1/pets/123', cases: 1, seed: 1);

        $this->assertCount(1, $cases);
        foreach ($cases as $case) {
            $this->assertSame('/v1/pets/{petId}', $case->matchedPath);
        }
    }

    #[Test]
    public function null_body_when_operation_has_no_request_body(): void
    {
        // GET /v1/pets has no requestBody.
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'GET', '/v1/pets', cases: 2, seed: 1);

        foreach ($cases as $case) {
            $this->assertNull($case->body);
        }
    }

    #[Test]
    public function honors_strip_prefixes_for_concrete_uri(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs', stripPrefixes: ['/api']);

        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'GET', '/api/v1/pets/123', cases: 1, seed: 1);

        $this->assertCount(1, $cases);
        foreach ($cases as $case) {
            $this->assertSame('/v1/pets/{petId}', $case->matchedPath);
        }
    }

    #[Test]
    public function throws_when_required_request_body_has_no_schema(): void
    {
        // PUT /v1/pets/{petId} in petstore-3.0 declares `application/json: {}`
        // (required:true but no schema). Without a loud failure, every fuzzed
        // case would 4xx and the user would have no signal back to the
        // explorer.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requestBody.required: true');

        OpenApiEndpointExplorer::explore('petstore-3.0', 'PUT', '/v1/pets/{petId}', cases: 1);
    }

    #[Test]
    public function rejects_unsupported_http_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported HTTP method 'TRACE'");

        OpenApiEndpointExplorer::explore('petstore-3.0', 'TRACE', '/v1/pets', cases: 1);
    }

    #[Test]
    public function exposes_method_as_http_method_enum(): void
    {
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'post', '/v1/pets', cases: 1, seed: 1);

        $this->assertCount(1, $cases);
        foreach ($cases as $case) {
            $this->assertSame(HttpMethod::POST, $case->method);
        }
    }

    #[Test]
    public function negative_exploration_requires_expected_status_classes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected HTTP status class');

        OpenApiEndpointExplorer::exploreInvalid('petstore-3.0', 'POST', '/v1/pets', [], cases: 1);
    }

    #[Test]
    public function negative_exploration_exposes_target_and_replay_metadata(): void
    {
        $cases = OpenApiEndpointExplorer::exploreInvalid(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            expectedStatusClasses: [4],
            cases: 3,
            seed: 17,
        );

        foreach ($cases as $index => $case) {
            $this->assertSame(ExplorationCaseKind::Invalid, $case->kind);
            $this->assertNotNull($case->targetKeyword);
            $this->assertSame([4], $case->expectedStatusClasses);
            $this->assertSame(17, $case->seed);
            $this->assertSame($index, $case->caseIndex);
            $this->assertNotSame('', $case->replayToken());
        }
    }
}
