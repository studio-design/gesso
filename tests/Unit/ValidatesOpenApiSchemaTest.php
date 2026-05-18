<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Tests\Helpers\CreatesTestResponse;

use function json_encode;

class ValidatesOpenApiSchemaTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function empty_body_204_response_passes_validation(): void
    {
        $response = $this->makeTestResponse('', 204);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::DELETE,
            '/v1/pets/123',
        );
    }

    #[Test]
    public function empty_body_200_without_content_schema_passes_validation(): void
    {
        $response = $this->makeTestResponse('', 200);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/health',
        );
    }

    #[Test]
    public function json_body_response_passes_validation(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function invalid_json_body_fails_validation(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->expectException(AssertionFailedError::class);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function empty_body_fails_when_spec_requires_json_schema(): void
    {
        $response = $this->makeTestResponse('', 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Response body is empty');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function validation_failure_message_includes_spec_name(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('spec: petstore-3.0');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function successful_validation_records_coverage(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function literal_null_response_body_is_type_checked(): void
    {
        // Issue #246: a response body of the literal JSON `null` is
        // type-checked against the schema instead of being read as an absent
        // body. GET /v1/pets declares a `type: object` 200 schema, so a null
        // body fails with a schema type error. Before the fix the Laravel
        // adapter decoded through TestResponse::json(), whose "null decode ==
        // invalid JSON" heuristic raised a misleading framework failure.
        $response = $this->makeTestResponse('null', 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must match the type');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function literal_null_response_body_with_json_content_type_is_type_checked(): void
    {
        // Issue #246: the literal-null fix applies on the explicit-Content-Type
        // path too, not only when the header is absent. A response with
        // `Content-Type: application/json` and a `null` body is type-checked
        // against GET /v1/pets' `type: object` 200 schema and fails.
        $response = $this->makeTestResponse('null', 200, ['Content-Type' => 'application/json']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must match the type');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function scalar_response_body_is_type_checked(): void
    {
        // Issue #246: a scalar JSON body (the integer `123`) reaches the
        // validator and is type-checked. Before the fix the body extractor's
        // `?array` return type raised a TypeError on a non-array decoded
        // body; the Laravel and Symfony adapters now behave identically.
        $response = $this->makeTestResponse('123', 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must match the type');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function non_json_html_body_passes_as_null_body(): void
    {
        $response = $this->makeTestResponse(
            '<html><body>Done</body></html>',
            204,
            ['Content-Type' => 'text/html'],
        );

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::DELETE,
            '/v1/pets/123',
        );
    }

    #[Test]
    public function non_json_body_fails_with_content_type_mismatch(): void
    {
        $response = $this->makeTestResponse(
            '<html><body>OK</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Response Content-Type 'text/html' is not defined for");

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function json_content_type_response_still_validates(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Buddy', 'tag' => 'dog']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200, ['Content-Type' => 'application/json']);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function json_content_type_with_charset_validates(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Buddy', 'tag' => 'dog']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200, ['Content-Type' => 'application/json; charset=utf-8']);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function vendor_json_content_type_validates(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Buddy', 'tag' => 'dog']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200, ['Content-Type' => 'application/vnd.api+json']);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function missing_content_type_header_still_parses_json(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Rex', 'tag' => 'dog']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function json_content_type_in_spec_with_mixed_content_types_validates_schema(): void
    {
        $body = (string) json_encode(['error' => 'Pet already exists'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 409, ['Content-Type' => 'application/json']);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::POST,
            '/v1/pets',
        );
    }

    #[Test]
    public function non_json_content_type_in_spec_with_mixed_content_types_passes(): void
    {
        $response = $this->makeTestResponse(
            '<html><body>Conflict</body></html>',
            409,
            ['Content-Type' => 'text/html'],
        );

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::POST,
            '/v1/pets',
        );
    }

    protected function openApiSpec(): string
    {
        return 'petstore-3.0';
    }
}
