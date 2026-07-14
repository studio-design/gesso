<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\SkipOpenApi;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;

use function json_encode;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Covers the per-request skip API from issue #41:
 * `withoutValidation()`, `withoutRequestValidation()`, `withoutResponseValidation()`.
 *
 * The flag is consumed on the next auto-assert attempt (simulated here by a
 * direct call to maybeAutoAssertOpenApiSchema, which is what createTestResponse
 * delegates to). End-to-end integration through Laravel's HTTP helpers lives
 * in AutoAssertIntegrationTest.
 */
class ValidatesOpenApiSchemaWithoutValidationTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.default_spec' => 'petstore-3.0',
            'gesso.auto_assert' => true,
        ];
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function without_validation_skips_next_auto_assert(): void
    {
        // Body intentionally violates the spec. If the flag is not honored,
        // maybeAutoAssertOpenApiSchema would raise AssertionFailedError.
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->withoutValidation();
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        // Flag skipped validation AND coverage recording — matching how
        // #[SkipOpenApi] behaves, so per-request opt-out is consistent with
        // the attribute-level opt-out.
        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function without_response_validation_skips_next_auto_assert(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->withoutResponseValidation();
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function without_request_validation_does_not_skip_response_auto_assert(): void
    {
        // withoutRequestValidation is a forward-looking hook — it wires up the
        // flag consumption path so #43's request-side validator can read it,
        // but must NOT suppress the response-side auto-assert that already
        // exists today.
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->withoutRequestValidation();
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        // Response auto-assert ran → coverage recorded.
        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function without_validation_resets_after_one_call(): void
    {
        // Core guarantee from issue #41: the flag covers exactly the next
        // HTTP call, then resets. A second call with an invalid body must
        // fail — proving the flag did NOT persist.
        $validBody = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $invalidBody = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);

        $this->withoutValidation();
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse($validBody, 200),
            HttpMethod::GET,
            '/v1/pets',
        );

        // Flag is now consumed — second call re-validates.
        $this->expectException(AssertionFailedError::class);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse($invalidBody, 200),
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function without_validation_returns_self_for_fluent_chain(): void
    {
        // Lets tests write `$this->withoutValidation()->get('/v1/pets')` — the
        // primary ergonomic reason for having these methods at all.
        $this->assertSame($this, $this->withoutValidation());
        $this->assertSame($this, $this->withoutRequestValidation());
        $this->assertSame($this, $this->withoutResponseValidation());
    }

    #[Test]
    public function without_validation_does_not_affect_explicit_assert(): void
    {
        // Explicit assertResponseMatchesOpenApiSchema() is the user's direct
        // intent. It must run regardless of the skip flag — matching
        // #[SkipOpenApi]'s convention. The flag is consumed only on the
        // HTTP-call boundary (maybeAutoAssertOpenApiSchema).
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->withoutValidation();

        $this->expectException(AssertionFailedError::class);
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
    }

    #[Test]
    public function explicit_assert_after_without_validation_skip_still_validates_and_records(): void
    {
        // The skip flag suppresses auto-assert (including markValidated), so
        // a later explicit assertResponseMatchesOpenApiSchema() on the same
        // TestResponse must run validation from scratch and record coverage.
        // Without this, the WeakMap idempotency check could mistakenly swallow
        // the explicit call if the auto-assert path had silently marked the
        // response as validated despite skipping.
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->withoutValidation();
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        // Coverage not recorded by the skip.
        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());

        // Explicit call runs validation and records coverage.
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    #[SkipOpenApi]
    public function without_validation_combined_with_skip_attribute_both_agree_to_skip(): void
    {
        // Both the per-request flag and the class/method-level attribute
        // express "skip this". The flag is consumed before the attribute
        // check, so the attribute branch is never exercised — but the
        // observable result (no validation, no coverage) is identical. This
        // pins the ordering invariant: combining the two switches must not
        // produce divergent or surprising behavior.
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->withoutValidation();
        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function without_validation_consumes_flag_even_when_auto_assert_disabled(): void
    {
        // If the flag leaked past an auto_assert=false HTTP call, toggling
        // auto_assert on mid-test would silently skip the wrong request.
        // Consumption must happen at the boundary regardless of config state.
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = false;

        $this->withoutValidation();
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse('', 200),
            HttpMethod::GET,
            '/v1/pets',
        );

        // Now enable auto-assert and send an invalid body — must throw,
        // proving the flag was already consumed by the previous call.
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = true;
        $invalidBody = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);

        $this->expectException(AssertionFailedError::class);
        $this->maybeAutoAssertOpenApiSchema(
            $this->makeTestResponse($invalidBody, 200),
            HttpMethod::GET,
            '/v1/pets',
        );
    }
}
