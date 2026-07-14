<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

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

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Covers `auto_inject_dummy_credentials` — the superset of
 * `auto_inject_dummy_bearer` that also fills in apiKey-in-header,
 * apiKey-in-cookie and apiKey-in-query schemes. The point of these tests is
 * the contract that consumer-side `actingAs()` tests no longer false-fail on
 * security checks for non-bearer scheme endpoints, while honouring real
 * credentials when the test bothers to set them.
 */
class ValidatesOpenApiSchemaAutoInjectCredentialsTest extends TestCase
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
    public function config_file_defaults_auto_inject_dummy_credentials_to_false(): void
    {
        $config = require __DIR__ . '/../../src/Laravel/config.php';

        $this->assertArrayHasKey('auto_inject_dummy_credentials', $config);
        $this->assertFalse($config['auto_inject_dummy_credentials']);
    }

    #[Test]
    public function inject_credentials_satisfies_apikey_cookie_endpoint_without_real_cookie(): void
    {
        // /v1/secure/apikey-cookie requires `session_id` cookie. With the
        // credentials inject flag on, the validator's view gets a dummy cookie
        // value even though Symfony Request has none — security passes.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-cookie', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-cookie',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_satisfies_apikey_header_endpoint_without_real_header(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-header', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-header');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-header',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_satisfies_apikey_query_endpoint_without_real_query(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-query', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-query');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-query',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_still_satisfies_bearer_endpoint(): void
    {
        // Upward compatibility with the legacy `auto_inject_dummy_bearer`
        // behavior: the new flag is a strict superset, so plain bearer
        // endpoints continue to work without setting the legacy flag.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        $this->assertArrayHasKey(
            'GET /v1/secure/bearer',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_does_not_override_existing_cookie_value(): void
    {
        // If the test sets the cookie explicitly (even to a deliberately
        // invalid empty-equivalent value the spec rejects), the inject must
        // leave it alone so the test's intent wins.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create(
            '/v1/secure/apikey-cookie',
            'GET',
            [],
            ['session_id' => 'real-session-from-test'],
        );

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-cookie',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_does_not_override_existing_apikey_header_value(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create(
            '/v1/secure/apikey-header',
            'GET',
            [],
            [],
            [],
            ['HTTP_X_API_KEY' => 'real-key-from-test'],
        );

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-header');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-header',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_does_not_override_existing_apikey_query_value(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-query?api_key=real-key-from-test', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-query');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-query',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_treats_empty_cookie_value_as_absent_and_injects(): void
    {
        // SecurityValidator::checkApiKeySatisfied() treats `value === ''` as
        // missing. The inject path must agree, otherwise an empty cookie left
        // over from a prior request would silently disable the inject and
        // re-introduce the false-fail this feature exists to prevent.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create(
            '/v1/secure/apikey-cookie',
            'GET',
            [],
            ['session_id' => ''],
        );

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-cookie',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_treats_empty_apikey_header_value_as_absent_and_injects(): void
    {
        // Header bag stores values as `list<?string>` and the
        // slotIsAlreadyPopulated array branch peels the first element. An
        // empty-string entry must collapse to "absent" the same way the
        // cookie path does, otherwise `array_key_first` regressions or a
        // tightening of the empty check could silently re-introduce the
        // false-fail.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create(
            '/v1/secure/apikey-header',
            'GET',
            [],
            [],
            [],
            ['HTTP_X_API_KEY' => ''],
        );

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-header');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-header',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_treats_empty_apikey_query_value_as_absent_and_injects(): void
    {
        // Query bag stores values as plain strings (scalar branch in
        // slotIsAlreadyPopulated). `?api_key=` produces an empty-string entry
        // that must be treated as absent so the inject still fires.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-query?api_key=', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-query');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-query',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_handles_and_entry_with_bearer_and_apikey(): void
    {
        // /v1/secure/and requires both bearer AND apiKey-header in a single
        // requirement (AND semantics). Legacy bearer-only inject leaves the
        // apiKey error in place; the credentials inject fills both, so the
        // entry is satisfied and the endpoint passes.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/and', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/and');

        $this->assertArrayHasKey(
            'GET /v1/secure/and',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_handles_and_entry_with_multiple_apikey_locations(): void
    {
        // /v1/secure/and-multi-apikey requires bearer AND apiKey-header AND
        // apiKey-cookie in a single requirement. Exercises the dedup path
        // (different `in` values for distinct schemes) end-to-end through
        // the trait — introspector unit tests cover spec-walking shape, this
        // test pins the actual write across header bag + cookie bag in one
        // call.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/and-multi-apikey', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/and-multi-apikey');

        $this->assertArrayHasKey(
            'GET /v1/secure/and-multi-apikey',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }

    #[Test]
    public function inject_credentials_without_auto_validate_request_is_noop(): void
    {
        // The credentials inject is a sub-feature of request validation —
        // with validation off, the inject flag must not run the validator as
        // a side effect.
        $GLOBALS['__openapi_testing_config']['gesso.auto_validate_request'] = false;
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-cookie', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function inject_credentials_does_not_mutate_symfony_request_cookies_or_query(): void
    {
        // Inject is a validator-view-only rewrite — the framework-side Request
        // bag must remain untouched so subsequent middleware / assertions see
        // the same input the test set up.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-cookie', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');

        $this->assertSame([], $request->cookies->all());
        $this->assertSame([], $request->query->all());
        $this->assertNull($request->headers->get('X-API-Key'));
        $this->assertNull($request->headers->get('Authorization'));
    }

    #[Test]
    public function inject_credentials_with_non_bool_value_fails_loudly(): void
    {
        // Same three-way coercion guard as the other auto_* config flags.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = 'yolo';

        $request = Request::create('/v1/secure/apikey-cookie', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('auto_inject_dummy_credentials must be a boolean');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');
    }

    #[Test]
    public function legacy_bearer_flag_alone_does_not_inject_apikey_credentials(): void
    {
        // Backward-compat regression guard: when only the legacy
        // `auto_inject_dummy_bearer` is on, apiKey endpoints must still fail
        // with the apiKey-specific message — the legacy flag's narrower
        // scope is preserved exactly.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = false;

        $request = Request::create('/v1/secure/apikey-cookie', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("api key 'session_id' is missing from the cookie");

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');
    }

    #[Test]
    public function inject_credentials_swallows_runtime_exception_and_lets_validator_surface_it(): void
    {
        // RuntimeException from OpenApiSpecLoader::load() is caught inside
        // resolveAutoInjectCredentials() so the inject path returns []. The
        // validator loads the same spec immediately after and re-raises the
        // error — pinning that contract here so a refactor that decouples
        // inject and validate (silently making the spec error invisible)
        // gets caught in CI.
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'nonexistent-spec-name';
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-cookie', 'GET');

        $this->expectException(RuntimeException::class);

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');
    }

    #[Test]
    public function credentials_flag_takes_precedence_when_both_flags_set(): void
    {
        // Both flags on → superset wins, apiKey endpoints pass too. The two
        // flags coexist: setting credentials does not require unsetting the
        // legacy one, so a consumer that toggles the new flag without also
        // touching the old one still gets the new behavior.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/apikey-cookie', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/apikey-cookie');

        $this->assertArrayHasKey(
            'GET /v1/secure/apikey-cookie',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
        );
    }
}
