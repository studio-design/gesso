<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use const JSON_THROW_ON_ERROR;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiAssertions;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;

use function json_encode;
use function substr_count;

/**
 * Issue #563: the PSR-7 hook defaults follow the shared validation policy
 * (gesso.php / extension parameters). No `#[OpenApiSpec]` here on purpose:
 * the spec name itself comes from the shared `default_spec`.
 */
final class OpenApiAssertionsProcessDefaultsTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        OpenApiCoverageTracker::reset();
        ValidationPolicyDefaults::reset();
    }

    protected function tearDown(): void
    {
        ValidationPolicyDefaults::reset();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function shared_skip_response_codes_reach_the_psr7_adapter(): void
    {
        ValidationPolicyDefaults::configure(skipResponseCodes: ['200'], defaultSpec: 'petstore-3.0');

        $this->assertPsr7ResponseMatchesOpenApiSchema(
            new Request('GET', 'https://example.test/v1/pets'),
            new Response(200, ['Content-Type' => 'application/json'], '{"wrong_key":"value"}'),
        );

        $this->assertArrayHasKey('GET /v1/pets', OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);
    }

    #[Test]
    public function shared_max_errors_cap_psr7_failures(): void
    {
        ValidationPolicyDefaults::configure(maxErrors: 1, defaultSpec: 'petstore-3.0');
        $items = [];
        for ($i = 1; $i <= 30; $i++) {
            $items[] = ['id' => 'not-an-int-' . $i, 'name' => $i];
        }

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema(
                new Request('GET', 'https://example.test/v1/pets'),
                new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $items], JSON_THROW_ON_ERROR)),
            );
            $this->fail('Expected schema validation to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertLessThan(10, substr_count($e->getMessage(), "\n"));
        }
    }

    #[Test]
    public function shared_default_spec_resolves_without_attribute_or_override(): void
    {
        ValidationPolicyDefaults::configure(defaultSpec: 'petstore-3.0');

        $this->assertPsr7ResponseMatchesOpenApiSchema(
            new Request('GET', 'https://example.test/v1/pets'),
            new Response(200, ['Content-Type' => 'application/json'], '{"data":[]}'),
        );

        $this->assertArrayHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function missing_shared_default_spec_still_fails_loudly(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No OpenAPI spec is configured');

        $this->assertPsr7RequestMatchesOpenApiSchema(new Request('GET', 'https://example.test/v1/pets'));
    }

    #[Test]
    public function skip_request_validation_codes_default_to_422_and_400_until_configured(): void
    {
        $this->assertSame(['422', '400'], $this->openApiSkipRequestValidationResponseCodes());

        ValidationPolicyDefaults::configure(skipRequestValidationResponseCodes: []);

        $this->assertSame([], $this->openApiSkipRequestValidationResponseCodes());
    }
}
