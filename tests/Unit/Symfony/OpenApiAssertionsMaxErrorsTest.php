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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function substr_count;

/**
 * Confirms the overridable `openApiMaxErrors()` hook is threaded into the
 * underlying validator: capping it at 1 must collapse a body with dozens of
 * schema violations down to a single reported error.
 */
#[OpenApiSpec('petstore-3.0')]
final class OpenApiAssertionsMaxErrorsTest extends TestCase
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
    public function open_api_max_errors_override_caps_reported_errors(): void
    {
        // 30 items, each with a wrong-typed id and name — 60 schema errors
        // uncapped. With openApiMaxErrors() pinned to 1 the failure message
        // must stay short.
        $items = [];
        for ($i = 1; $i <= 30; $i++) {
            $items[] = ['id' => 'not-an-int-' . $i, 'name' => $i];
        }

        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => $items]);

        $caught = null;

        try {
            $this->assertOpenApiResponse($response, $request);
        } catch (AssertionFailedError $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected schema validation to fail.');
        // Uncapped this message would carry ~60 newline-separated errors;
        // the cap keeps it to a single error (a handful of lines at most).
        $this->assertLessThan(10, substr_count($caught->getMessage(), "\n"));
    }

    protected function openApiMaxErrors(): int
    {
        return 1;
    }
}
