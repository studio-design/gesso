<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Symfony;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OpenApiSpec('petstore-3.0')]
final class OpenApiAssertionsTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        OpenApiCoverageTracker::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function valid_response_passes_validation(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    #[OpenApiSpec('openapi-3.2')]
    public function openapi_32_plain_operation_works_through_symfony_adapter(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido']]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('GET /v1/pets', $covered['openapi-3.2'] ?? []);
    }

    #[Test]
    #[OpenApiSpec('unsupported-version')]
    public function unsupported_openapi_version_fails_before_response_assertion(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new Response('', 200);

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage("Unsupported OpenAPI version: '3.3.0' (string)");

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function invalid_response_fails_validation(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['wrong_key' => 'value']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('spec: petstore-3.0');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function empty_204_response_passes_validation(): void
    {
        $request = Request::create('/v1/pets/123', 'DELETE');
        $response = new Response('', 204);

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function skipped_5xx_response_passes_validation(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['anything' => true], 500);

        // 5xx matches the default skip pattern: body validation is skipped and
        // the result stays valid even though the body is off-schema.
        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function extra_skip_response_code_skips_body_validation(): void
    {
        $request = Request::create('/v1/pets', 'POST');
        $response = new JsonResponse(['wrong_key' => 'value'], 409);

        $this->assertResponseMatchesOpenApiSchema($request, $response, ['409']);
    }

    #[Test]
    public function successful_validation_records_response_coverage(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido']]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function valid_request_passes_validation(): void
    {
        $request = Request::create('/v1/pets', 'GET');

        $this->assertRequestMatchesOpenApiSchema($request);
    }

    #[Test]
    public function invalid_request_body_fails_validation(): void
    {
        $request = Request::create(
            '/v1/pets',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed for POST /v1/pets');

        $this->assertRequestMatchesOpenApiSchema($request);
    }

    #[Test]
    public function unsupported_http_method_fails_with_clear_message(): void
    {
        $request = Request::create('/v1/pets', 'TRACE');
        $response = new JsonResponse(['data' => []]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('unsupported HTTP method');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function successful_request_records_request_coverage(): void
    {
        $request = Request::create('/v1/pets', 'GET');

        $this->assertRequestMatchesOpenApiSchema($request);

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function streamed_response_fails_with_buffered_response_message(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new StreamedResponse(static function (): void {
            echo '{"data":[]}';
        });

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('requires buffered responses');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function malformed_json_response_body_fails_with_clear_message(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new Response('{"data": [', 200, ['Content-Type' => 'application/json']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Response body could not be parsed as JSON');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function malformed_json_request_body_without_content_type_adds_hint(): void
    {
        // Request::create() forces a Content-Type on POST bodies; drop it so
        // the no-Content-Type hint branch in extractSymfonyJsonBody() runs.
        $request = Request::create('/v1/pets', 'POST', [], [], [], [], '{"name": ');
        $request->headers->remove('Content-Type');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('no Content-Type header was present on the request');

        $this->assertRequestMatchesOpenApiSchema($request);
    }

    #[Test]
    public function literal_null_response_body_without_content_type_fails_loudly(): void
    {
        // Issue #246: a response body of the literal JSON `null` with no
        // Content-Type is type-checked against the schema, not silently read
        // as an absent body. GET /v1/pets declares a `type: object` 200
        // schema, so a null body is a contract violation surfaced as a schema
        // type error rather than the misleading "Response body is empty".
        $request = Request::create('/v1/pets', 'GET');
        $response = new Response('null', 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must match the type');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function scalar_response_body_without_content_type_is_type_checked(): void
    {
        // Issue #246: a scalar JSON body (here the integer `123`) is decoded
        // and type-checked against the schema rather than being treated as no
        // body. Regression guard — the Symfony adapter's `mixed` body shape
        // already handled scalars; this pins that the #246 fix keeps it so.
        $request = Request::create('/v1/pets', 'GET');
        $response = new Response('123', 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must match the type');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function literal_null_request_body_without_content_type_fails_loudly(): void
    {
        // Issue #246: a request body of the literal JSON `null` with no
        // Content-Type is type-checked against the requestBody schema. POST
        // /v1/pets requires a `type: object` body, so a null body fails
        // loudly. Request::create() forces a Content-Type on POST bodies;
        // drop it so the no-Content-Type path runs.
        $request = Request::create('/v1/pets', 'POST', [], [], [], [], 'null');
        $request->headers->remove('Content-Type');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must match the type');

        $this->assertRequestMatchesOpenApiSchema($request);
    }

    #[Test]
    public function non_json_response_body_passes_as_null_body(): void
    {
        $request = Request::create('/v1/pets/123', 'DELETE');
        $response = new Response('<html><body>done</body></html>', 204, ['Content-Type' => 'text/html']);

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function content_type_containing_json_substring_is_not_decoded_as_json(): void
    {
        // Issue #251: a Content-Type that merely contains the substring "json"
        // (e.g. application/jsonsomethingweird) is NOT a JSON media type. The
        // adapter defers to ContentTypeMatcher::isJsonContentType() — the same
        // strict check the validator uses — so a non-JSON body is left
        // undecoded and the validator surfaces its clean "Content-Type is not
        // defined" diagnostic instead of a misleading "could not be parsed as
        // JSON" parse error.
        $request = Request::create('/v1/pets', 'GET');
        $response = new Response(
            'not json at all',
            200,
            ['Content-Type' => 'application/jsonsomethingweird'],
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            "Response Content-Type 'application/jsonsomethingweird' is not defined",
        );

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function json_content_type_with_charset_is_validated(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido']]]);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function extra_skip_response_code_does_not_leak_into_later_calls(): void
    {
        $request = Request::create('/v1/pets', 'POST');

        // The per-call ['409'] skip uses a one-off validator and must not
        // pollute the cached validator used by the next call.
        $this->assertResponseMatchesOpenApiSchema(
            $request,
            new JsonResponse(['wrong_key' => 'value'], 409),
            ['409'],
        );

        $this->expectException(AssertionFailedError::class);
        $this->assertResponseMatchesOpenApiSchema(
            $request,
            new JsonResponse(['wrong_key' => 'value'], 409),
        );
    }

    #[Test]
    #[OpenApiSpec('request-validation-skip')]
    public function invalid_request_with_documented_4xx_status_is_downgraded(): void
    {
        // POST /exact-422 requires a `name`; an empty body fails request
        // validation, but a documented 422 response downgrades it to skipped.
        $request = Request::create('/exact-422', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertRequestMatchesOpenApiSchema($request, 422);
    }

    #[Test]
    #[OpenApiSpec('request-validation-skip')]
    public function invalid_request_with_undocumented_status_still_fails(): void
    {
        // /no-4xx documents only 200 and 500 — a 422 response is undocumented,
        // so the request-validation failure is NOT downgraded.
        $request = Request::create('/no-4xx', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed for POST /no-4xx');

        $this->assertRequestMatchesOpenApiSchema($request, 422);
    }

    #[Test]
    #[OpenApiSpec('request-validation-skip')]
    public function invalid_request_with_2xx_status_is_not_downgraded(): void
    {
        // 200 does not match the documented-4xx skip pattern, so the failure stands.
        $request = Request::create('/exact-422', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->expectException(AssertionFailedError::class);
        $this->assertRequestMatchesOpenApiSchema($request, 200);
    }
}
