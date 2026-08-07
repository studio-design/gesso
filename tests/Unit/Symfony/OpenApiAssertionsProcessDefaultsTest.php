<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issue #502 review: the process-wide validation-policy defaults exist for
 * the framework-agnostic path only. The Symfony adapter owns its skip set —
 * a phpunit.xml `skip_response_codes` parameter must not silently
 * reconfigure Symfony response assertions.
 */
#[OpenApiSpec('petstore-3.0')]
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
    public function process_default_skip_response_codes_do_not_reach_the_symfony_adapter(): void
    {
        ValidationPolicyDefaults::configure(skipResponseCodes: ['200']);

        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['wrong_key' => 'value']);

        // Must FAIL, not skip: the adapter pins its own default skip set.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('spec: petstore-3.0');

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }
}
