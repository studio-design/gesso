<?php

declare(strict_types=1);

namespace Examples\Pest\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function dirname;

/**
 * Base test case wired for the Pest plugin example. Mirrors what a real
 * Laravel project's `Tests\TestCase` looks like once the package is
 * installed: extend the framework harness, mix in `ValidatesOpenApiSchema`,
 * and configure the spec loader + default spec in `setUp()`.
 *
 * Library-specific lines for a real project to copy:
 *  - `use ValidatesOpenApiSchema;` on the class.
 *  - `OpenApiSpecLoader::configure(...)` once at boot (here in setUp; in a
 *    real project typically driven by the PHPUnit extension reading
 *    `spec.base_path` from gesso.php — see gesso.php beside phpunit.xml.dist).
 *  - The spec to validate against: `spec.default` in a real project's root
 *    gesso.php; here the `openApiSpec()` override below, because Testbench
 *    boots its own skeleton app that does not see this directory's gesso.php.
 *  - The static-state resets in `setUp()` / `tearDown()`
 *    (`OpenApiSpecLoader::reset`, `OpenApiCoverageTracker::reset`).
 *    Orchestra Testbench shares these
 *    statics across every `it(...)` in the file, so a real project running
 *    on a fresh per-test process boundary may not need them — but they
 *    are defensive defaults a copy-paste user is unlikely to regret.
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

        // `auto_assert` and `auto_validate_request` are deliberately left at
        // their package defaults (false) so the example showcases the
        // explicit `expect(...)->toMatchOpenApi*Schema()` form. Set
        // `laravel.auto_assert` / `laravel.auto_validate_request` to true in
        // your real project's gesso.php to validate every Laravel HTTP helper
        // call automatically — the Pest expectations still work alongside.
    }

    protected function openApiSpec(): string
    {
        return 'petstore';
    }

    protected function tearDown(): void
    {
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
        // Closure routes keep the example focused. In your real Laravel
        // project these would typically be controller actions:
        //   Route::get('/v1/pets', [PetController::class, 'index']);
        // The library validates the response after Laravel dispatches —
        // independent of how the route resolved — so the contract tests
        // look identical either way.
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

        // /v1/health is documented in petstore.json with a 200 response only.
        // The route deliberately returns 503 so the example can demonstrate
        // the skipResponseCodes named argument on toMatchOpenApiResponseSchema().
        Route::get('/v1/health', static fn() => response()->json(
            ['error' => 'service unavailable'],
            503,
        ));
    }
}
