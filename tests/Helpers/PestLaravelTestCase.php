<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Helpers;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function dirname;

/**
 * Orchestra Testbench harness used by `tests/Integration/Pest/ExpectationsTest.php`.
 * Mirrors `tests/Integration/Laravel/AutoAssertIntegrationTest` minus the
 * PHPUnit `#[Test]` attribute layer — Pest binds tests via `uses(...)->in()`
 * and reuses the routes/spec config defined here for every `it(...)` block.
 *
 * Lives under `tests/Helpers/` so it stays outside Pest's discovery path
 * (`pest tests/Integration/Pest`) and outside the PHPUnit Integration suite.
 * Both runners reach it via PSR-4 instead.
 */
class PestLaravelTestCase extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(dirname(__DIR__) . '/fixtures/specs');
        OpenApiCoverageTracker::reset();
        config()->set('gesso.default_spec', 'petstore-3.0');
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
        return [GessoServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::get('/v1/pets', static function () {
            $bad = request()->query('bad') === '1';

            return response()->json(
                $bad
                    ? ['wrong_key' => 'value']
                    : ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            );
        });

        Route::post('/v1/pets', static fn() => response()->json(
            ['data' => ['id' => 42, 'name' => 'Buddy', 'tag' => null]],
            201,
        ));

        Route::get('/v1/health', static fn() => response()->json(
            ['error' => 'service unavailable'],
            503,
        ));
    }
}
