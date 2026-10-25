<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use const PHP_URL_PATH;
use const PHP_URL_QUERY;

use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Fuzz\ExplorationCaseKind;
use Studio\Gesso\Fuzz\ExplorationCases;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\OpenApiEndpointExplorer;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_unique;
use function count;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_split;

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
    public function generates_required_property_named_security_with_empty_schema(): void
    {
        $cases = OpenApiEndpointExplorer::explore(
            'fuzz-empty-security-property',
            'POST',
            '/payload',
            cases: 1,
            seed: 1,
        );

        foreach ($cases as $case) {
            $this->assertIsArray($case->body);
            $this->assertArrayHasKey('security', $case->body);
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
    public function generated_boolean_query_round_trips_through_uri_and_request_validation(): void
    {
        $cases = OpenApiEndpointExplorer::explore('petstore-3.0', 'POST', '/v1/pets', cases: 2, seed: 1);
        $validator = new OpenApiRequestValidator();
        $serialisedValues = [];

        foreach ($cases as $case) {
            $uri = $case->uri();
            $path = parse_url($uri, PHP_URL_PATH);
            $queryString = parse_url($uri, PHP_URL_QUERY);
            $this->assertIsString($path);
            $this->assertIsString($queryString);

            $query = [];
            parse_str($queryString, $query);
            $serialisedValues[] = $query['dryRun'] ?? null;

            $result = $validator->validate(
                'petstore-3.0',
                'POST',
                $path,
                $query,
                [],
                $case->body,
                'application/json',
            );

            $this->assertTrue($result->isValid(), $result->errorMessage());
        }

        $this->assertSame(['false', 'true'], $serialisedValues);
    }

    #[Test]
    public function generates_fixed_quantifier_pattern_for_query_parameter(): void
    {
        $first = OpenApiEndpointExplorer::explore(
            'fuzz-fixed-quantifier-query',
            'GET',
            '/oauth/callback',
            cases: 3,
            seed: 42,
        );
        $replayed = OpenApiEndpointExplorer::explore(
            'fuzz-fixed-quantifier-query',
            'GET',
            '/oauth/callback',
            cases: 3,
            seed: 42,
        );

        $states = [];
        $validator = new OpenApiRequestValidator();
        foreach ($first as $case) {
            $states[] = $case->query['state'];
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $case->query['state']);
            $uri = $case->uri();
            $path = parse_url($uri, PHP_URL_PATH);
            $queryString = parse_url($uri, PHP_URL_QUERY);
            $this->assertIsString($path);
            $this->assertIsString($queryString);
            $query = [];
            parse_str($queryString, $query);
            $result = $validator->validate(
                'fuzz-fixed-quantifier-query',
                'GET',
                $path,
                $query,
                [],
                null,
            );
            $this->assertTrue($result->isValid(), $result->errorMessage());
        }

        $replayedStates = [];
        foreach ($replayed as $case) {
            $replayedStates[] = $case->query['state'];
        }

        $this->assertSame($states, $replayedStates);
        $this->assertCount(3, array_unique($states));
        $this->assertGreaterThan(1, count(array_unique(str_split($states[1]))));
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
    public function generated_boolean_path_round_trips_through_uri_and_request_validation(): void
    {
        $cases = OpenApiEndpointExplorer::explore(
            'fuzz-boolean-path',
            'GET',
            '/flags/{enabled}',
            cases: 2,
            seed: 1,
        );
        $validator = new OpenApiRequestValidator();
        $uris = [];

        foreach ($cases as $case) {
            $uri = $case->uri();
            $uris[] = $uri;
            $result = $validator->validate('fuzz-boolean-path', 'GET', $uri, [], [], null);

            $this->assertTrue($result->isValid(), $result->errorMessage());
        }

        $this->assertSame(['/flags/false', '/flags/true'], $uris);
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

    #[Test]
    public function explores_an_operation_whose_only_body_media_type_is_form_urlencoded(): void
    {
        // Issue #498: generated form cases must validate as the form they are.
        $validator = new OpenApiRequestValidator();

        foreach (OpenApiEndpointExplorer::explore('contract-checks', 'POST', '/oauth/token', 3, seed: 7) as $case) {
            $this->assertSame('application/x-www-form-urlencoded', $case->contentType);
            $this->assertIsString($case->bodyAsFormFields()['grant_type'] ?? null);

            $result = $validator->validate('contract-checks', 'POST', '/oauth/token', [], [], $case->bodyAsString(), $case->contentType);
            $this->assertTrue($result->isValid(), $result->errorMessage());
            $this->assertFalse($result->isSkipped());
        }
    }

    #[Test]
    public function prefers_json_over_a_form_media_type(): void
    {
        foreach (OpenApiEndpointExplorer::explore('contract-checks', 'POST', '/oauth/form-or-json', 1, seed: 7) as $case) {
            $this->assertSame('application/json', $case->contentType);
        }
    }

    #[Test]
    public function still_throws_for_a_required_multipart_only_body(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requestBody\.required: true.*multipart\/form-data/s');

        OpenApiEndpointExplorer::explore('contract-checks', 'POST', '/uploads/multipart-only', 1);
    }

    #[Test]
    public function every_invalid_form_case_is_rejected_on_the_wire(): void
    {
        // A form carries strings and drops empty / null fields, so a mutation
        // that is invalid as JSON can be valid as a form (`"42"` is a fine
        // string, `tags=[]` disappears). Only the ones that survive are kept.
        $validator = new OpenApiRequestValidator();
        $targets = [];
        foreach (['/oauth/revoke', '/oauth/form-shapes'] as $path) {
            foreach (OpenApiEndpointExplorer::exploreInvalid('contract-checks', 'POST', $path, [4], 30, seed: 7) as $case) {
                $this->assertSame('application/x-www-form-urlencoded', $case->contentType);
                if ($case->body !== null) {
                    $result = $validator->validate('contract-checks', 'POST', $path, [], ['X-Api-Key' => 'k'], $case->bodyAsString(), $case->contentType);
                    $this->assertFalse($result->isValid(), "{$path} {$case->targetPointer} {$case->targetKeyword}");
                }
                $targets[] = $case->targetPointer . ' ' . $case->targetKeyword;
            }
        }

        $this->assertContains('body/token maxLength', $targets);
        $this->assertContains('body/token_type_hint enum', $targets);
        $this->assertNotContains('body/tags minItems', $targets);
    }

    #[Test]
    public function keeps_an_empty_object_field_an_object_on_the_wire(): void
    {
        $validator = new OpenApiRequestValidator();
        foreach (OpenApiEndpointExplorer::explore('contract-checks', 'POST', '/oauth/form-shapes', 5, seed: 7) as $case) {
            $result = $validator->validate('contract-checks', 'POST', '/oauth/form-shapes', [], [], $case->bodyAsString(), $case->contentType);
            $this->assertTrue($result->isValid(), $result->errorMessage());
            $this->assertStringContainsString('meta=%7B', $case->bodyAsString());
        }
    }

    #[Test]
    public function throws_for_a_valid_form_body_a_form_cannot_express(): void
    {
        // A required list that must stay empty vanishes from a form.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('once form-encoded');

        OpenApiEndpointExplorer::explore('contract-checks', 'POST', '/oauth/form-required-empty-list', 1, seed: 7);
    }

    #[Test]
    public function throws_for_a_required_form_body_that_serializes_to_nothing(): void
    {
        // An empty form body reaches the validator as absent, so a required
        // one cannot be sent — even though `{}` satisfies the schema.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('once form-encoded');

        OpenApiEndpointExplorer::explore('contract-checks', 'POST', '/oauth/form-required-empty-object', 1, seed: 7);
    }

    #[Test]
    public function throws_for_a_required_null_form_body(): void
    {
        // A null form body goes out empty, which the validator reads as a
        // missing required body — even though the schema accepts null.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('once form-encoded');

        OpenApiEndpointExplorer::explore('contract-checks', 'POST', '/oauth/form-required-nullable', 5, seed: 7);
    }
}
