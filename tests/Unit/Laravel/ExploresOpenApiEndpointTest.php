<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Laravel;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;
use Studio\OpenApiContractTesting\Fuzz\ExplorationCases;
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;
use Studio\OpenApiContractTesting\Fuzz\OpenApiSpecExploration;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ExploresOpenApiEndpoint;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

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
}

class ExploresOpenApiEndpointWithoutSpec extends TestCase
{
    use ExploresOpenApiEndpoint;

    public function runUnannotatedMarker(): void
    {
        $this->exploreEndpoint('POST', '/v1/pets');
    }
}
