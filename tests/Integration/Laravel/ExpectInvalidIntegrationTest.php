<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function dirname;

class ExpectInvalidIntegrationTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(dirname(__DIR__, 2) . '/fixtures/specs');
        OpenApiCoverageTracker::reset();
        config()->set('gesso.spec.default', 'petstore-3.0');
        config()->set('gesso.laravel.auto_assert', true);
        config()->set('gesso.laravel.auto_validate_request', true);
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function an_invalid_request_passes_and_the_response_is_still_validated(): void
    {
        $this->expectInvalidRequest()->postJson('/v1/pets', ['not_name' => 'x'])->assertCreated();
    }

    #[Test]
    public function a_valid_request_fails_the_expectation(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected the request of POST /v1/pets (spec: petstore-3.0) to violate the OpenAPI spec, but it matched.');

        $this->expectInvalidRequest()->postJson('/v1/pets', ['name' => 'Buddy']);
    }

    #[Test]
    public function the_expectation_runs_with_auto_validate_request_off(): void
    {
        config()->set('gesso.laravel.auto_validate_request', false);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('but it matched');

        $this->expectInvalidRequest()->postJson('/v1/pets', ['name' => 'Buddy']);
    }

    #[Test]
    public function a_documented_status_does_not_hide_the_violation(): void
    {
        config()->set('gesso.validation.skip_request_validation_response_codes', ['409']);

        $this->expectInvalidRequest()->postJson('/v1/pets', ['conflict' => true])->assertConflict();
    }

    #[Test]
    public function the_expectation_covers_the_next_call_only(): void
    {
        $this->expectInvalidRequest()->postJson('/v1/pets', ['not_name' => 'x']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->postJson('/v1/pets', ['not_name' => 'x']);
    }

    #[Test]
    public function a_path_the_spec_does_not_define_is_invalid(): void
    {
        $this->expectInvalidRequest()->expectInvalidResponse()->getJson('/v1/not-in-spec')->assertOk();
    }

    #[Test]
    public function an_invalid_response_passes(): void
    {
        $this->expectInvalidResponse()->get('/v1/pets?bad=1')->assertOk();
    }

    #[Test]
    public function a_valid_response_fails_the_expectation(): void
    {
        config()->set('gesso.laravel.auto_assert', false);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected the response of GET /v1/pets (spec: petstore-3.0) to violate the OpenAPI spec, but it matched.');

        $this->expectInvalidResponse()->get('/v1/pets');
    }

    #[Test]
    public function a_skipped_response_fails_the_expectation(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('but it was skipped');

        $this->expectInvalidResponse()->skipResponseCode(200)->get('/v1/pets');
    }

    protected function getPackageProviders($app): array
    {
        return [GessoServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::get('/v1/pets', static fn() => response()->json(
            request()->query('bad') === '1'
                ? ['wrong_key' => 'value']
                : ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
        ));

        Route::get('/v1/not-in-spec', static fn() => response()->json([]));

        Route::post('/v1/pets', static fn() => request()->has('conflict')
            ? response()->json(['error' => 'conflict'], 409)
            : response()->json(['data' => ['id' => 42, 'name' => 'Buddy', 'tag' => null]], 201));
    }
}
