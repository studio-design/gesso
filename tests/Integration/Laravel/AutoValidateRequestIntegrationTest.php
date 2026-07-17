<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function dirname;

/**
 * Complements the ValidatesOpenApiSchemaAutoValidateRequestTest unit test by
 * exercising the full `$this->postJson()` → Laravel kernel →
 * `MakesHttpRequests::createTestResponse` → trait override pipeline under a
 * real Testbench app. Unit tests drive the hook directly; this file proves
 * the hook actually fires when a Laravel HTTP helper dispatches a request.
 */
class AutoValidateRequestIntegrationTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(dirname(__DIR__, 2) . '/fixtures/specs');
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

    #[Test]
    public function auto_validate_request_true_accepts_valid_post_body(): void
    {
        config()->set('gesso.auto_validate_request', true);

        $response = $this->postJson('/v1/pets', ['name' => 'Buddy']);
        $response->assertCreated();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('POST /v1/pets', $covered['petstore-3.0'] ?? []);
    }

    #[Test]
    public function auto_validate_request_true_raises_on_invalid_post_body(): void
    {
        // Missing required `name` field — the canonical request-side drift.
        // Proves the hook survives the Laravel HTTP helper chain.
        config()->set('gesso.auto_validate_request', true);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->postJson('/v1/pets', ['not_name' => 'x']);
    }

    #[Test]
    public function auto_validate_request_false_does_not_validate_invalid_body(): void
    {
        config()->set('gesso.auto_validate_request', false);

        $response = $this->postJson('/v1/pets', ['not_name' => 'x']);
        $response->assertCreated();

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function without_request_validation_skips_next_call_end_to_end(): void
    {
        // Fluent chain survives Laravel's dispatcher → createTestResponse
        // hook. Mirrors `without_validation_skips_next_http_call_only` for
        // the request side.
        config()->set('gesso.auto_validate_request', true);

        $response = $this->withoutRequestValidation()->postJson('/v1/pets', ['not_name' => 'x']);
        $response->assertCreated();

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function without_request_validation_flag_resets_after_one_call_end_to_end(): void
    {
        config()->set('gesso.auto_validate_request', true);

        $this->withoutRequestValidation()
            ->postJson('/v1/pets', ['not_name' => 'x'])
            ->assertCreated();

        // Second identical call must re-validate — flag is per-call.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->postJson('/v1/pets', ['not_name' => 'x']);
    }

    #[Test]
    public function auto_inject_dummy_bearer_passes_bearer_endpoint_without_real_header(): void
    {
        // End-to-end for #69's second half: the test sets no Authorization,
        // but `auto_inject_dummy_bearer=true` fills it in for the validator
        // so security validation does not false-fail.
        config()->set('gesso.auto_validate_request', true);
        config()->set('gesso.auto_inject_dummy_bearer', true);

        $response = $this->get('/v1/secure/bearer');
        $response->assertOk();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('GET /v1/secure/bearer', $covered['petstore-3.0'] ?? []);
    }

    #[Test]
    public function auto_inject_off_still_fails_bearer_endpoint_without_header(): void
    {
        // Default off: the test-author must set a real Authorization header
        // or opt into auto-inject. Confirms the feature is gated.
        config()->set('gesso.auto_validate_request', true);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Authorization header is missing');

        $this->get('/v1/secure/bearer');
    }

    #[Test]
    public function both_hooks_enabled_runs_request_before_response_and_records_coverage_once(): void
    {
        // With both auto_assert and auto_validate_request on, the trait must
        // (1) run request validation first so the skipNextRequestValidation
        // flag is consumed at the right boundary, (2) record coverage only
        // once per (spec,method,path) despite both hooks calling the tracker,
        // and (3) both validations must see a consistent request/response.
        config()->set('gesso.auto_assert', true);
        config()->set('gesso.auto_validate_request', true);

        $response = $this->postJson('/v1/pets', ['name' => 'Buddy']);
        $response->assertCreated();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('POST /v1/pets', $covered['petstore-3.0']);
        // Tracker de-dupes by (spec,method,path) — exactly one entry, not two.
        $this->assertCount(1, $covered['petstore-3.0']);
    }

    #[Test]
    public function both_hooks_enabled_request_failure_takes_precedence(): void
    {
        // If both hooks are on and the request is invalid, the request-side
        // assertion fires first (before the response hook runs), so the
        // surfaced error is the request one. Response drift on the same call
        // does not override this.
        config()->set('gesso.auto_assert', true);
        config()->set('gesso.auto_validate_request', true);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->postJson('/v1/pets', ['not_name' => 'x']);
    }

    #[Test]
    public function required_multipart_body_accepts_an_uploaded_file(): void
    {
        config()->set('gesso.auto_validate_request', true);
        config()->set('gesso.default_spec', 'non-json-content-schema');

        $response = $this
            ->withHeader('Content-Type', 'multipart/form-data')
            ->post('/multipart-required', [
                'font_file' => UploadedFile::fake()->create('font.woff', 10, 'font/woff'),
            ]);

        $response->assertNoContent();

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey(
            'POST /multipart-required',
            $covered['non-json-content-schema'] ?? [],
        );
    }

    #[Test]
    public function required_multipart_body_rejects_an_empty_request(): void
    {
        config()->set('gesso.auto_validate_request', true);
        config()->set('gesso.default_spec', 'non-json-content-schema');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Request body is empty');

        $this
            ->withHeader('Content-Type', 'multipart/form-data')
            ->post('/multipart-required');
    }

    #[Test]
    public function required_form_body_accepts_parsed_parameters(): void
    {
        config()->set('gesso.auto_validate_request', true);
        config()->set('gesso.default_spec', 'non-json-content-schema');

        $response = $this
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->post('/form-required', ['name' => 'Fido']);

        $response->assertNoContent();
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [GessoServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::post('/v1/pets', static fn() => response()->json(
            ['data' => ['id' => 42, 'name' => 'Buddy', 'tag' => null]],
            201,
        ));

        Route::post('/multipart-required', static fn() => response()->noContent());
        Route::post('/form-required', static fn() => response()->noContent());

        // Bearer-protected endpoint in the spec. Route implementation is
        // auth-free in the test app — the library validates against the
        // *spec*'s security declaration, not Laravel's actual middleware.
        Route::get('/v1/secure/bearer', static fn() => response()->json(null));
    }
}
