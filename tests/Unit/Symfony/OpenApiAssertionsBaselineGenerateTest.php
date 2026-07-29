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
use Symfony\Component\HttpFoundation\Response;

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
            parameter: 'limit',
        )));
    }

    #[Test]
    public function a_generation_run_is_not_truncated_by_the_default_max_errors_cap(): void
    {
        // 20 id violations followed by one name violation: with the default
        // maxErrors=20 cap the name violation would be dropped and later
        // surface as "new" despite existing at generation time.
        $data = [];
        for ($i = 0; $i < 20; $i++) {
            $data[] = ['id' => 'not-an-integer', 'name' => 'Fido'];
        }
        $data[] = ['id' => 1, 'name' => 123];
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => $data]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $this->assertTrue($this->collector->baseline()->contains(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/name',
            'type',
        )), 'the 21st violation must be recorded during a generation run');
    }

    #[Test]
    public function an_undecodable_response_body_is_demoted_and_recorded(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new Response('{invalid', 200, ['Content-Type' => 'application/json']);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $this->assertTrue($this->collector->baseline()->contains(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            null,
            null,
            'response.body',
            null,
            null,
        )));
    }

    #[Test]
    public function an_undecodable_request_body_is_demoted_and_recorded(): void
    {
        $request = Request::create(
            '/v1/pets',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{invalid',
        );

        $this->assertRequestMatchesOpenApiSchema($request);

        $this->assertTrue($this->collector->baseline()->contains(new ViolationFingerprint(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            null,
            null,
            'request.body',
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
