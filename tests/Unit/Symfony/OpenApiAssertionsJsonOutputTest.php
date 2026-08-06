<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function explode;
use function json_decode;
use function putenv;

#[OpenApiSpec('petstore-3.0')]
final class OpenApiAssertionsJsonOutputTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        OpenApiCoverageTracker::reset();
        putenv('GESSO_VALIDATION_FORMAT');
        ValidationOutput::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        putenv('GESSO_VALIDATION_FORMAT');
        ValidationOutput::reset();
        parent::tearDown();
    }

    #[Test]
    public function response_assertion_failure_renders_the_json_document_after_the_header(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]]);

        try {
            $this->assertResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the Symfony assertion to fail.');
        } catch (AssertionFailedError $e) {
            [$header, $document] = explode("\n", $e->getMessage(), 2);

            $this->assertSame(
                'OpenAPI schema validation failed for GET /v1/pets (spec: petstore-3.0):',
                $header,
            );

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $decoded['schema_version']);
            $this->assertSame('failure', $decoded['outcome']);
            $this->assertSame('response.body', $decoded['issues'][0]['category']);
            $this->assertStringContainsString('curl -X GET', $decoded['reproduce_command']);
        }
    }

    #[Test]
    public function request_assertion_failure_renders_the_json_document_after_the_header(): void
    {
        putenv('GESSO_VALIDATION_FORMAT=json');
        $request = Request::create('/v1/pets/search?limit=not-an-integer', 'GET');

        try {
            $this->assertRequestMatchesOpenApiSchema($request);
            $this->fail('Expected the Symfony request assertion to fail.');
        } catch (AssertionFailedError $e) {
            [$header, $document] = explode("\n", $e->getMessage(), 2);

            $this->assertSame(
                'OpenAPI request validation failed for GET /v1/pets/search (spec: petstore-3.0):',
                $header,
            );

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('failure', $decoded['outcome']);
            $this->assertSame('request.parameter.query', $decoded['issues'][0]['category']);
        }
    }

    #[Test]
    public function text_mode_failure_keeps_the_phpunit_assertion_suffix(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]]);

        try {
            $this->assertResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the Symfony assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Reproduce: curl -X GET', $e->getMessage());
            $this->assertStringContainsString('Failed asserting that false is true.', $e->getMessage());
            $this->assertStringNotContainsString('schema_version', $e->getMessage());
        }
    }
}
