<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;

use function json_encode;

#[OpenApiSpec('response-headers')]
class ValidatesOpenApiSchemaResponseHeaderTest extends TestCase
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
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function passes_when_response_has_required_header(): void
    {
        $body = (string) json_encode(['id' => 1], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 201, [
            'Content-Type' => 'application/json',
            'Location' => 'https://example.com/pets/1',
            'X-RateLimit-Remaining' => '42',
        ]);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::POST, '/pets');
    }

    #[Test]
    public function fails_when_required_response_header_is_missing(): void
    {
        $body = (string) json_encode(['id' => 1], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 201, [
            'Content-Type' => 'application/json',
        ]);

        try {
            $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::POST, '/pets');
            $this->fail('expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('[response-header.Location]', $e->getMessage());
            $this->assertStringContainsString('required header is missing', $e->getMessage());
        }
    }

    #[Test]
    public function fails_when_response_header_violates_schema(): void
    {
        $body = (string) json_encode(['id' => 1], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 201, [
            'Content-Type' => 'application/json',
            'Location' => 'https://example.com/pets/1',
            'X-RateLimit-Remaining' => '-5',
        ]);

        try {
            $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::POST, '/pets');
            $this->fail('expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('[response-header.X-RateLimit-Remaining]', $e->getMessage());
        }
    }

    #[Test]
    public function fails_with_only_header_errors_when_body_is_valid(): void
    {
        // Pin that header validation runs INDEPENDENTLY of body validation.
        // Without this guarantee a regression that only triggers headers
        // when body fails would slip through — both prior tests have body
        // and header errors covarying.
        $body = (string) json_encode(['id' => 1], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 201, [
            'Content-Type' => 'application/json',
            // Location intentionally omitted; body is otherwise valid.
        ]);

        try {
            $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::POST, '/pets');
            $this->fail('expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('[response-header.Location]', $message);
            // No body-shaped errors should appear in the same failure.
            $this->assertStringNotContainsString('[/]', $message);
            $this->assertStringNotContainsString('[response-body]', $message);
        }
    }
}
