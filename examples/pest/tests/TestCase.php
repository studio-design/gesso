<?php

declare(strict_types=1);

namespace Examples\Pest\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Laravel\OpenApiContractTestingServiceProvider;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function dirname;

/**
 * Base test case wired for the Pest plugin example. Mirrors what a real
 * Laravel project's `Tests\TestCase` looks like once the package is
 * installed: extend the framework harness, mix in `ValidatesOpenApiSchema`,
 * and configure the spec loader + default spec in `setUp()`.
 *
 * Real projects will already have most of this — the only library-specific
 * bits are `use ValidatesOpenApiSchema;`, the `OpenApiSpecLoader::configure`
 * call, and the `default_spec` config line. The Pest plugin layers on top
 * of this trait without further setup.
 */
class TestCase extends TestbenchTestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();

        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(dirname(__DIR__) . '/openapi');
        OpenApiCoverageTracker::reset();

        config()->set('openapi-contract-testing.default_spec', 'petstore');
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();

        parent::tearDown();
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [OpenApiContractTestingServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::get('/v1/pets', static fn() => response()->json([
            'data' => [
                ['id' => 1, 'name' => 'Fido', 'tag' => null],
                ['id' => 2, 'name' => 'Buddy', 'tag' => 'good-boy'],
            ],
        ]));

        Route::post('/v1/pets', static fn() => response()->json(
            ['data' => ['id' => 42, 'name' => request()->json('name'), 'tag' => null]],
            201,
        ));
    }
}
