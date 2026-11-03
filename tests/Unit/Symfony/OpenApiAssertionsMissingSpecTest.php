<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * No `#[OpenApiSpec]` attribute and no `openApiSpec()` override — the spec
 * resolver yields the empty string, which the adapter must reject with an
 * actionable message instead of a confusing spec-file-not-found error.
 */
final class OpenApiAssertionsMissingSpecTest extends TestCase
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
    public function response_assertion_fails_when_no_spec_is_configured(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => []]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No OpenAPI spec is configured');

        $this->assertOpenApiResponse($response, $request);
    }

    #[Test]
    public function request_assertion_fails_when_no_spec_is_configured(): void
    {
        $request = Request::create('/v1/pets', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No OpenAPI spec is configured');

        $this->assertOpenApiRequest($request);
    }
}
