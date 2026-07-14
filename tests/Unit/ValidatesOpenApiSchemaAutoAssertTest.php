<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;

use function count;
use function json_encode;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaAutoAssertTest extends TestCase
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
    public function config_file_defaults_auto_assert_to_false(): void
    {
        $config = require __DIR__ . '/../../src/Laravel/config.php';

        $this->assertArrayHasKey('auto_assert', $config);
        $this->assertFalse($config['auto_assert']);
    }

    #[Test]
    public function auto_assert_true_validates_valid_response_without_error(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = true;

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function auto_assert_true_raises_assertion_error_for_invalid_response(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = true;

        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->expectException(AssertionFailedError::class);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
    }

    #[Test]
    public function auto_assert_false_skips_validation_for_invalid_response(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = false;

        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayNotHasKey('petstore-3.0', $covered);
    }

    #[Test]
    public function auto_assert_not_set_skips_validation_for_invalid_response(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayNotHasKey('petstore-3.0', $covered);
    }

    #[Test]
    public function auto_assert_with_non_bool_value_fails_loudly(): void
    {
        // A user who mis-configures auto_assert (e.g. via env without cast)
        // should see a loud failure, not a silent skip.
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = 'yolo';

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('auto_assert must be a boolean');

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
    }

    #[Test]
    public function auto_assert_with_truthy_string_validates(): void
    {
        // env('X') returns strings; "true" must be treated as truthy so that
        // `'auto_assert' => env('AUTO_ASSERT')` (the idiomatic Laravel
        // pattern) works without an explicit cast.
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = 'true';

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertArrayHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function double_manual_assert_with_same_signature_is_idempotent(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertCount(1, $covered['petstore-3.0']);
    }

    #[Test]
    public function manual_then_auto_assert_with_same_signature_is_idempotent(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = true;

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
        $countBefore = count(OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertCount($countBefore, OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);
    }

    #[Test]
    public function auto_then_manual_assert_with_same_signature_does_not_duplicate_coverage(): void
    {
        // After a successful auto-assert, a manual call with the matching
        // (method, path) signature must no-op — no second validator run,
        // no second coverage entry.
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = true;

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertCount(1, OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);
    }

    #[Test]
    public function manual_assert_with_different_method_path_re_validates_same_response(): void
    {
        // Idempotency is keyed on (spec, method, path) — a second call with
        // different (method, path) must re-validate, not silently no-op.
        // We prove this by making the second call one that SHOULD fail:
        // if idempotency wrongly skipped it, no exception would be raised.
        //
        // The empty 204 body validates against DELETE /v1/pets/{petId} but
        // does NOT satisfy GET /v1/pets (which expects a JSON body). Before
        // the tuple-keyed fix, this second call was silently skipped.
        $response = $this->makeTestResponse('', 204);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::DELETE, '/v1/pets/123');

        $this->expectException(AssertionFailedError::class);
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
    }

    #[Test]
    public function auto_assert_does_not_fail_on_undocumented_5xx(): void
    {
        // End-to-end guard for the 5xx default skip under auto_assert: a
        // production-style 503 that the spec does not document must pass
        // through the full createTestResponse → maybeAutoAssert → validator
        // chain without raising AssertionFailedError, and the endpoint must
        // still be recorded as covered.
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = true;

        $body = (string) json_encode(['error' => 'service unavailable'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 503);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function auto_assert_still_fails_5xx_when_skip_disabled(): void
    {
        // Opting out of skip via config restores the "not defined" failure
        // under auto_assert — confirms the cache-key extension makes the
        // config change observable without a manual cache reset.
        $GLOBALS['__openapi_testing_config']['gesso.auto_assert'] = true;
        $GLOBALS['__openapi_testing_config']['gesso.skip_response_codes'] = [];

        $body = (string) json_encode(['error' => 'service unavailable'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 503);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Status code 503 not defined');

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
    }
}
