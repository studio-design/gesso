<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const E_USER_WARNING;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Request\SecurityValidator;
use Symfony\Component\HttpFoundation\Request;

use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Covers the `auto_inject_dummy_bearer` branch of request validation. The
 * inject path is a spec-driven convenience for tests that authenticate via
 * actingAs() or middleware bypass and therefore never set a real
 * Authorization header — without the inject, every bearer-protected endpoint
 * would false-fail the security check.
 */
class ValidatesOpenApiSchemaAutoInjectBearerTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        SecurityValidator::resetWarningStateForTesting();
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.default_spec' => 'petstore-3.0',
            'gesso.auto_validate_request' => true,
        ];
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        SecurityValidator::resetWarningStateForTesting();
        parent::tearDown();
    }

    #[Test]
    public function config_file_defaults_auto_inject_dummy_bearer_to_false(): void
    {
        $config = require __DIR__ . '/../../src/Laravel/config.php';

        $this->assertArrayHasKey('auto_inject_dummy_bearer', $config);
        $this->assertFalse($config['auto_inject_dummy_bearer']);
    }

    #[Test]
    public function inject_true_satisfies_bearer_endpoint_without_real_header(): void
    {
        // /v1/secure/bearer requires `bearerAuth`. With the inject flag on,
        // the validator's view of the request gets a dummy Bearer token even
        // though the Symfony Request has none — security check passes.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        $this->assertArrayHasKey(
            'GET /v1/secure/bearer',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_false_still_fails_bearer_endpoint_without_header(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = false;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Authorization header is missing');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');
    }

    #[Test]
    public function inject_does_not_override_user_supplied_authorization(): void
    {
        // If the test has already set Authorization (e.g. a malformed value
        // to deliberately exercise a failure path), the inject must leave it
        // alone so the test's intent wins. Here we set an invalid value and
        // assert the validator sees it.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create(
            '/v1/secure/bearer',
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Malformed'],
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Authorization header does not contain a 'Bearer <token>' credential");

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');
    }

    #[Test]
    public function inject_is_noop_on_apikey_only_endpoint(): void
    {
        // Inject is bearer-only by design. An apiKey endpoint still fails
        // with the apiKey-specific message so the user is directed to the
        // right fix (set the api key), not a misleading "bearer missing".
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/apikey-header', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("api key 'X-API-Key' is missing from the header");

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-header');
    }

    #[Test]
    public function inject_is_noop_on_oauth2_only_endpoint(): void
    {
        // oauth2-only endpoints are classified as Unsupported, so the
        // requirement entry is skipped; injecting bearer would be a lie.
        // Validation must pass on its own (skipped). The silent-pass now
        // also fires a one-shot E_USER_WARNING; suppress locally because
        // SecurityValidatorTest covers warning contents.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/oauth2-only', 'GET');

        set_error_handler(
            static fn(int $errno, string $errstr): bool => $errno === E_USER_WARNING &&
                str_starts_with($errstr, '[security]'),
        );

        try {
            $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/oauth2-only');
        } finally {
            restore_error_handler();
        }

        $this->assertArrayHasKey(
            'GET /v1/secure/oauth2-only',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_in_and_requirement_still_surfaces_apikey_error(): void
    {
        // /v1/secure/and requires bearer AND apiKey in a single entry.
        // Injecting bearer alone cannot satisfy the entry, but the surfaced
        // error narrows to the apiKey (the actionable one) rather than both.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/and', 'GET');

        try {
            $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/and');
            $this->fail('Expected AssertionFailedError');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString("api key 'X-API-Key' is missing", $message);
            $this->assertStringNotContainsString('Authorization header is missing', $message);
        }
    }

    #[Test]
    public function inject_flag_alone_without_auto_validate_request_does_nothing(): void
    {
        // Inject is a sub-feature of request validation — with validation
        // off, the inject flag must not run the validator as a side effect.
        // Proves the feature flags compose independently.
        $GLOBALS['__openapi_testing_config']['gesso.auto_validate_request'] = false;
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function inject_flag_with_non_bool_value_fails_loudly(): void
    {
        // Same three-way coercion that auto_assert / auto_validate_request
        // use — wiring regression guard for the shared resolveBoolConfig()
        // helper. A typo'd env value must not silently disable the feature.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = 'yolo';

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('auto_inject_dummy_bearer must be a boolean');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');
    }

    #[Test]
    public function inject_swallows_runtime_exception_from_spec_load_and_lets_validator_surface_it(): void
    {
        // RuntimeException from OpenApiSpecLoader::load() (unreadable file,
        // malformed JSON, unsupported extension, etc.) is caught inside
        // shouldAutoInjectDummyBearer() so the inject path returns false.
        // The validator loads the same spec immediately after and re-raises
        // the error — confirming "one failure, not a cascade" doctrine.
        // Uses a spec name with no corresponding file to trigger the real
        // RuntimeException path.
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'nonexistent-spec-name';
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->expectException(RuntimeException::class);

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');
    }

    #[Test]
    public function inject_respects_existing_lowercase_authorization_header(): void
    {
        // Symfony's HeaderBag normalizes to lowercase. The inject must see
        // either case and not overwrite — regression guard for the
        // case-insensitive lookup used in shouldAutoInjectDummyBearer().
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create(
            '/v1/secure/bearer',
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer real-token.from-test'],
        );

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        // Valid bearer → covered. The dummy was not substituted.
        $this->assertArrayHasKey(
            'GET /v1/secure/bearer',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }
}
