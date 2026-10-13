<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function substr_count;

/**
 * Issue #563: the Symfony hook defaults follow the shared validation policy
 * (gesso.php / extension parameters). No `#[OpenApiSpec]` here on purpose:
 * the spec name itself comes from the shared `default_spec`.
 */
final class OpenApiAssertionsProcessDefaultsTest extends TestCase
{
    use OpenApiAssertions {
        openApiSkipResponseCodes as private sharedSkipResponseCodes;
    }

    /** @var null|string[] */
    private ?array $skipResponseCodesOverride = null;

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
    public function shared_skip_response_codes_reach_the_symfony_adapter(): void
    {
        ValidationPolicyDefaults::configure(skipResponseCodes: ['200'], defaultSpec: 'petstore-3.0');

        $this->assertResponseMatchesOpenApiSchema(Request::create('/v1/pets', 'GET'), new JsonResponse(['wrong_key' => 'value']));

        $this->assertArrayHasKey('GET /v1/pets', OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);
    }

    #[Test]
    public function shared_max_errors_cap_symfony_failures(): void
    {
        ValidationPolicyDefaults::configure(maxErrors: 1, defaultSpec: 'petstore-3.0');
        $items = [];
        for ($i = 1; $i <= 30; $i++) {
            $items[] = ['id' => 'not-an-int-' . $i, 'name' => $i];
        }

        try {
            $this->assertResponseMatchesOpenApiSchema(Request::create('/v1/pets', 'GET'), new JsonResponse(['data' => $items]));
            $this->fail('Expected schema validation to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertLessThan(10, substr_count($e->getMessage(), "\n"));
        }
    }

    #[Test]
    public function shared_default_spec_resolves_without_attribute_or_override(): void
    {
        ValidationPolicyDefaults::configure(defaultSpec: 'petstore-3.0');

        $this->assertResponseMatchesOpenApiSchema(Request::create('/v1/pets', 'GET'), new JsonResponse(['data' => []]));

        $this->assertArrayHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function an_override_still_wins(): void
    {
        ValidationPolicyDefaults::configure(skipResponseCodes: ['200'], defaultSpec: 'petstore-3.0');
        $this->skipResponseCodesOverride = [];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('spec: petstore-3.0');

        $this->assertResponseMatchesOpenApiSchema(Request::create('/v1/pets', 'GET'), new JsonResponse(['wrong_key' => 'value']));
    }

    #[Test]
    public function per_call_extra_codes_add_to_the_shared_set(): void
    {
        ValidationPolicyDefaults::configure(skipResponseCodes: [], defaultSpec: 'petstore-3.0');

        $this->assertResponseMatchesOpenApiSchema(
            Request::create('/v1/pets', 'GET'),
            new JsonResponse(['wrong_key' => 'value']),
            ['200'],
        );

        $this->assertArrayHasKey('GET /v1/pets', OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);
    }

    #[Test]
    public function skip_request_validation_codes_default_to_422_and_400_until_configured(): void
    {
        $this->assertSame(['422', '400'], $this->openApiSkipRequestValidationResponseCodes());

        ValidationPolicyDefaults::configure(skipRequestValidationResponseCodes: []);

        $this->assertSame([], $this->openApiSkipRequestValidationResponseCodes());
    }

    /** @return string[] */
    protected function openApiSkipResponseCodes(): array
    {
        return $this->skipResponseCodesOverride ?? $this->sharedSkipResponseCodes();
    }
}
