<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Laravel;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Fuzz\ExplorationCaseKind;
use Studio\Gesso\Fuzz\ExplorationCases;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\GeneratedResponseCases;
use Studio\Gesso\Fuzz\OpenApiResponseSpecExploration;
use Studio\Gesso\Fuzz\OpenApiSpecExploration;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ExploresOpenApiEndpoint;
use Studio\Gesso\Spec\OpenApiSpecLoader;

// Re-use the namespace-level config() mock so config()-based fallback works.
require_once __DIR__ . '/../../Helpers/LaravelConfigMock.php';

#[OpenApiSpec('petstore-3.0')]
class ExploresOpenApiEndpointTest extends TestCase
{
    use ExploresOpenApiEndpoint;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        $GLOBALS['__openapi_testing_config'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function returns_collection_with_default_cases(): void
    {
        $cases = $this->exploreEndpoint('POST', '/v1/pets');

        $this->assertInstanceOf(ExplorationCases::class, $cases);
        $this->assertCount(30, $cases);
    }

    #[Test]
    public function honors_explicit_cases_argument(): void
    {
        $cases = $this->exploreEndpoint('POST', '/v1/pets', cases: 5, seed: 1);

        $this->assertCount(5, $cases);
    }

    #[Test]
    public function exposes_negative_endpoint_exploration(): void
    {
        $cases = $this->exploreInvalidEndpoint('POST', '/v1/pets', [4], cases: 2, seed: 1);

        $this->assertCount(2, $cases);
        foreach ($cases as $case) {
            $this->assertSame(ExplorationCaseKind::Invalid, $case->kind);
            $this->assertSame([4], $case->expectedStatusClasses);
        }
    }

    #[Test]
    public function exposes_response_schema_exploration(): void
    {
        $cases = $this->exploreResponseSchema('GET', '/v1/pets', 200, seed: 1);

        $this->assertInstanceOf(GeneratedResponseCases::class, $cases);
        $this->assertNotCount(0, $cases);
        foreach ($cases as $case) {
            $this->assertSame(200, $case->status);
            $this->assertSame('application/json', $case->contentType);
        }
    }

    #[Test]
    #[OpenApiSpec('sdk-roundtrip')]
    public function response_exploration_honors_method_spec_attribute(): void
    {
        $cases = $this->exploreResponseSchema(
            'POST',
            '/oauth/introspect',
            200,
            seed: 1,
            extraCases: 2,
        );

        $this->assertCount(6, $cases);
    }

    #[Test]
    public function response_resolution_failure_routes_through_fail_explore(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('outcome=StatusNotDeclared');

        $this->exploreResponseSchema('GET', '/v1/pets', 201);
    }

    #[Test]
    public function creates_and_runs_whole_spec_exploration_plan(): void
    {
        $plan = $this->exploreSpec(casesPerOperation: 2, seed: 11);

        $this->assertInstanceOf(OpenApiSpecExploration::class, $plan);
        $summary = $plan
            ->includeOperations(['healthCheck'])
            ->dispatchUsing(static fn(ExploredCase $case): ExploredCase => $case)
            ->assertResponses();

        $this->assertSame(1, $summary->executedOperations);
        $this->assertSame(2, $summary->executedCases);
    }

    #[Test]
    #[OpenApiSpec('sdk-roundtrip-plan')]
    public function exposes_the_spec_wide_response_round_trip_plan(): void
    {
        $plan = $this->exploreResponseSpec(seed: 11, extraCases: 1);

        $this->assertInstanceOf(OpenApiResponseSpecExploration::class, $plan);
        $summary = $plan
            ->includeOperations(['createPet'])
            ->mapResponse(
                'createPet',
                201,
                static fn(GeneratedResponseCase $case): mixed => $case->bodyAsObject(),
                static fn(mixed $value): mixed => $value,
            )
            ->assertRoundTrips();

        $this->assertSame(1, $summary->executedOperations);
        $this->assertGreaterThanOrEqual(2, $summary->executedCases);
    }

    #[Test]
    public function each_runs_callback_per_case(): void
    {
        $hits = 0;
        $this->exploreEndpoint('POST', '/v1/pets', cases: 4, seed: 1)
            ->each(function (ExploredCase $case) use (&$hits): void {
                $this->assertSame(HttpMethod::POST, $case->method);
                $hits++;
            });

        $this->assertSame(4, $hits);
    }

    #[Test]
    public function fails_with_clear_message_when_spec_name_empty(): void
    {
        // The bare subclass deliberately lacks #[OpenApiSpec] and an
        // openApiSpec() override, so the resolver falls through to the
        // empty-string fallback that the trait must surface clearly. We
        // construct it with one of its own method names so the resolver's
        // ReflectionMethod($this, $name) lookup can resolve the running
        // method without finding an attribute (the marker method has none).
        $instance = new ExploresOpenApiEndpointWithoutSpec('runUnannotatedMarker');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('openApiSpec() must return a non-empty spec name');

        $instance->runUnannotatedMarker();
    }

    #[Test]
    public function fails_with_clear_message_when_path_unknown(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not declared');

        $this->exploreEndpoint('POST', '/does/not/exist');
    }

    #[Test]
    #[OpenApiSpec('petstore-3.1')]
    public function method_attribute_overrides_class_attribute(): void
    {
        // The class is annotated with petstore-3.0; the method attribute must
        // win and point the explorer at petstore-3.1. We verify that no
        // exception is raised when resolving against the 3.1 fixture (which
        // also declares POST /v1/pets).
        $cases = $this->exploreEndpoint('POST', '/v1/pets', cases: 1, seed: 1);

        $this->assertCount(1, $cases);
    }

    #[Test]
    #[OpenApiSpec('does-not-exist')]
    public function spec_loader_failure_routes_through_fail_explore(): void
    {
        // Without the broadened catch in ExploresOpenApiEndpoint, the spec
        // loader's RuntimeException would leak as a raw stack trace instead
        // of a clean PHPUnit assertion.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('does-not-exist');

        $this->exploreEndpoint('POST', '/v1/pets', cases: 1);
    }

    #[Test]
    #[OpenApiSpec('does-not-exist')]
    public function response_spec_loader_failure_routes_through_fail_explore(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('does-not-exist');

        $this->exploreResponseSchema('GET', '/v1/pets', 200);
    }
}

class ExploresOpenApiEndpointWithoutSpec extends TestCase
{
    use ExploresOpenApiEndpoint;

    public function runUnannotatedMarker(): void
    {
        $this->exploreEndpoint('POST', '/v1/pets');
    }
}
