<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[OpenApiSpec('petstore-3.0')]
final class OpenApiAssertionsBaselineGenerateTest extends TestCase
{
    use OpenApiAssertions;
    private ViolationBaselineCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $this->collector = new ViolationBaselineCollector();
        ViolationBaselineCollector::setCurrent($this->collector);
    }

    protected function tearDown(): void
    {
        ViolationBaselineCollector::resetCurrent();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_failing_response_assertion_is_demoted_and_its_fingerprint_recorded(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $this->assertTrue($this->collector->baseline()->contains(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/id',
            'type',
        )));
    }

    #[Test]
    public function a_failing_request_assertion_is_demoted_and_its_fingerprint_recorded(): void
    {
        $request = Request::create('/v1/pets/search?limit=not-an-integer', 'GET');

        $this->assertRequestMatchesOpenApiSchema($request);

        $this->assertSame(1, $this->collector->baseline()->count());
        $this->assertTrue($this->collector->baseline()->contains(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            null,
            null,
            'request.parameter.query',
            null,
            null,
        )));
    }

    #[Test]
    public function a_valid_response_records_nothing(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido']]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $this->assertSame(0, $this->collector->baseline()->count());
    }
}
