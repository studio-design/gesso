<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[OpenApiSpec('petstore-3.0')]
final class OpenApiAssertionsBaselineEnforceTest extends TestCase
{
    use OpenApiAssertions;
    private ViolationBaseline $baseline;
    private ViolationBaselineEnforcer $enforcer;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $this->baseline = new ViolationBaseline();
        $this->enforcer = new ViolationBaselineEnforcer($this->baseline);
        ViolationBaselineEnforcer::setCurrent($this->enforcer);
    }

    protected function tearDown(): void
    {
        ViolationBaselineEnforcer::resetCurrent();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_fully_baselined_failing_response_is_suppressed_and_hit(): void
    {
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/id',
            'type',
        ));

        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $this->assertSame(1, $this->enforcer->hitCount());
        $this->assertSame([], $this->enforcer->staleEntries());
    }

    #[Test]
    public function a_new_violation_fails_with_the_full_unmodified_message(): void
    {
        try {
            $this->assertResponseMatchesOpenApiSchema(
                Request::create('/v1/pets', 'GET'),
                new JsonResponse(['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]]),
            );
            $this->fail('Expected the assertion to fail on the unbaselined violation.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('OpenAPI schema validation failed for GET /v1/pets', $e->getMessage());
        }
    }

    #[Test]
    public function a_mix_of_baselined_and_new_violations_fails_but_still_marks_hits(): void
    {
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/id',
            'type',
        ));

        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 'not-an-integer', 'name' => 123]]]);

        try {
            $this->assertResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the assertion to fail on the unbaselined /data/*/name violation.');
        } catch (AssertionFailedError) {
            // The baselined /data/*/id entry occurred, so it is not stale.
            $this->assertSame(1, $this->enforcer->hitCount());
            $this->assertSame([], $this->enforcer->staleEntries());
        }
    }

    #[Test]
    public function a_fully_baselined_failing_request_is_suppressed(): void
    {
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            null,
            null,
            'request.parameter.query',
            null,
            'required',
            parameter: 'limit',
        ));

        $this->assertRequestMatchesOpenApiSchema(Request::create('/v1/pets/search', 'GET'));

        $this->assertSame(1, $this->enforcer->hitCount());
    }

    #[Test]
    public function a_different_violation_kind_on_a_baselined_parameter_still_fails(): void
    {
        // Baselined: `limit` required-but-missing. Observed: `limit` type
        // mismatch. The keyword is part of the identity, so no suppression.
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            null,
            null,
            'request.parameter.query',
            null,
            'required',
            parameter: 'limit',
        ));

        $this->expectException(AssertionFailedError::class);

        $this->assertRequestMatchesOpenApiSchema(Request::create('/v1/pets/search?limit=not-an-integer', 'GET'));
    }

    #[Test]
    public function a_baselined_decode_failure_is_suppressed_and_validation_continues(): void
    {
        // Only the decode fingerprint is baselined — exactly what a
        // generation run records for this response. The "body is empty"
        // verdict against the absent placeholder is an artifact and must not
        // need its own baseline entry.
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            null,
            null,
            'response.body',
            null,
            null,
        ));

        $request = Request::create('/v1/pets', 'GET');
        $response = new Response('{invalid', 200, ['Content-Type' => 'application/json']);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $this->assertSame(1, $this->enforcer->hitCount());
    }

    #[Test]
    public function a_baselined_decode_failure_does_not_mask_a_new_violation(): void
    {
        // Decode failure on an unknown path: the decode fingerprint is
        // baselined but the request-context violation is not — the assertion
        // must still fail.
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/unknown',
            null,
            null,
            'response.body',
            null,
            null,
        ));

        $request = Request::create('/v1/unknown', 'GET');
        $response = new Response('{invalid', 418, ['Content-Type' => 'application/json']);

        $this->expectException(AssertionFailedError::class);

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function an_unbaselined_decode_failure_raises_the_original_decode_error(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new Response('{invalid', 200, ['Content-Type' => 'application/json']);

        try {
            $this->assertResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the decode failure to surface.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('could not be parsed as JSON', $e->getMessage());
        }
    }

    #[Test]
    public function a_valid_response_passes_and_leaves_the_baseline_stale(): void
    {
        $stale = new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/id',
            'type',
        );
        $this->baseline->add($stale);

        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido']]]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $this->assertSame(0, $this->enforcer->hitCount());
        $this->assertSame([$stale], $this->enforcer->staleEntries());
    }
}
