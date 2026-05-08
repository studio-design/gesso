<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Attribute\SkipOpenApi;
use Studio\OpenApiContractTesting\Coverage\EndpointCoverageState;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Symfony\Component\HttpFoundation\Request;

use function json_encode;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Covers the request-side auto-validation hook introduced by issue #69. The
 * hook mirrors {@see ValidatesOpenApiSchema::maybeAutoAssertOpenApiSchema()}
 * for requests: same per-request flag consumption, same #[SkipOpenApi]
 * opt-out, same config-driven on/off.
 *
 * End-to-end integration through Laravel's HTTP helpers lives in
 * AutoValidateRequestIntegrationTest. These unit tests drive the hook
 * directly so failure modes can be pinned without a full Testbench app.
 */
class ValidatesOpenApiSchemaAutoValidateRequestTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'openapi-contract-testing.default_spec' => 'petstore-3.0',
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
    public function config_file_defaults_auto_validate_request_to_false(): void
    {
        $config = require __DIR__ . '/../../src/Laravel/config.php';

        $this->assertArrayHasKey('auto_validate_request', $config);
        $this->assertFalse($config['auto_validate_request']);
    }

    #[Test]
    public function auto_validate_request_true_passes_valid_post_body(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['name' => 'Fido']);

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');

        // Valid request records coverage under the matched path.
        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('POST /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function request_side_records_as_validated_not_skipped_only(): void
    {
        // Guards the default-arg contract at the trait level: a future
        // refactor that forwards `!isSkipped()` from a request result (or
        // adds a request-side skip concept) would silently flip every
        // request-only endpoint to skipped-only without this assertion.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['name' => 'Fido']);

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');

        // Request-side recording uses recordRequest() so the endpoint is
        // marked request-only — request validation has no response context to
        // mark response-level coverage. This is by design (#111): only the
        // response hook records (status, content-type) granularity.
        $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
        $endpoint = null;
        foreach ($coverage['endpoints'] as $summary) {
            if ($summary['endpoint'] === 'POST /v1/pets') {
                $endpoint = $summary;

                break;
            }
        }
        $this->assertNotNull($endpoint);
        $this->assertTrue($endpoint['requestReached']);
        $this->assertSame(EndpointCoverageState::RequestOnly, $endpoint['state']);
        $this->assertSame(0, $endpoint['skippedResponseCount']);
    }

    #[Test]
    public function auto_validate_request_true_raises_on_invalid_body(): void
    {
        // /v1/pets POST requires `name` per the spec. Sending a body without
        // it is the canonical request-side contract drift.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['not_name' => 'x']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');
    }

    #[Test]
    public function auto_validate_request_true_raises_on_missing_bearer(): void
    {
        // Without auto-inject, a bearer-protected endpoint called without any
        // Authorization header must fail request validation — proves that the
        // security-side of the validator is wired through.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Authorization header is missing');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');
    }

    #[Test]
    public function auto_validate_request_false_does_not_validate(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = false;

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['not_name' => 'x']);

        // No exception even though the body is invalid.
        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function auto_validate_request_not_set_defaults_to_skip(): void
    {
        $request = $this->makeJsonRequest('POST', '/v1/pets', ['not_name' => 'x']);

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function without_request_validation_skips_next_call(): void
    {
        // Flag must actually suppress validation now that the hook is live —
        // previously this was forward-looking and had no observable effect.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['not_name' => 'x']);

        $this->withoutRequestValidation();
        // Does not throw despite invalid body.
        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function without_request_validation_flag_resets_after_one_call(): void
    {
        // Core guarantee from #41 — same semantics apply to the request side.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $invalidRequest = $this->makeJsonRequest('POST', '/v1/pets', ['not_name' => 'x']);

        $this->withoutRequestValidation();
        $this->maybeAutoValidateOpenApiRequest($invalidRequest, HttpMethod::POST, '/v1/pets');

        // Second identical call must re-validate.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->maybeAutoValidateOpenApiRequest($invalidRequest, HttpMethod::POST, '/v1/pets');
    }

    #[Test]
    public function skip_flag_consumed_even_when_auto_validate_request_disabled(): void
    {
        // Flag tracks the HTTP call boundary; consumption must happen whether
        // or not validation ran. Otherwise, flipping auto_validate_request
        // from off → on mid-test would silently apply the stale flag.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = false;

        $this->withoutRequestValidation();
        $this->maybeAutoValidateOpenApiRequest(
            $this->makeJsonRequest('POST', '/v1/pets', ['name' => 'ok']),
            HttpMethod::POST,
            '/v1/pets',
        );

        // Re-enable and send an invalid body — must throw, proving the flag
        // did not carry over from the previous (config-disabled) call.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $this->expectException(AssertionFailedError::class);
        $this->maybeAutoValidateOpenApiRequest(
            $this->makeJsonRequest('POST', '/v1/pets', ['not_name' => 'x']),
            HttpMethod::POST,
            '/v1/pets',
        );
    }

    #[Test]
    #[SkipOpenApi(reason: 'intentional contract violation')]
    public function skip_open_api_attribute_opts_method_out_of_request_validation(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['not_name' => 'x']);

        // Does not throw, does not record coverage — parallels attribute
        // behavior on the response side.
        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function null_request_is_noop(): void
    {
        // Defensive: createTestResponse receives a nullable $request from
        // Laravel. If null is ever passed through, the hook must not crash.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $this->maybeAutoValidateOpenApiRequest(null, null, null);

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function null_method_is_noop(): void
    {
        // HttpMethod::tryFrom() returns null for unrecognized verbs (e.g. a
        // hypothetical LINK / UNLINK). The hook must not try to validate.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['name' => 'ok']);

        $this->maybeAutoValidateOpenApiRequest($request, null, '/v1/pets');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    public function auto_validate_request_accepts_truthy_string(): void
    {
        // `'auto_validate_request' => env('X')` is the idiomatic Laravel path
        // and yields "true" (string) — must be coerced just like auto_assert.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = 'true';

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['name' => 'ok']);

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');

        $this->assertArrayHasKey('POST /v1/pets', OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);
    }

    #[Test]
    public function auto_validate_request_with_non_bool_value_fails_loudly(): void
    {
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = 'yolo';

        $request = $this->makeJsonRequest('POST', '/v1/pets', ['name' => 'ok']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('auto_validate_request must be a boolean');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');
    }

    #[Test]
    public function unmatched_path_fails_validation(): void
    {
        // OpenApiRequestValidator itself produces the "No matching path" error;
        // this test pins that the trait surfaces it as an assertion failure
        // rather than swallowing it.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = $this->makeJsonRequest('POST', '/does/not/exist', ['name' => 'ok']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No matching path');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/does/not/exist');
    }

    #[Test]
    public function empty_spec_name_fails_loudly_on_request_side(): void
    {
        // Mirrors the response-side guard: returning `''` from openApiSpec()
        // is the misconfigured-defaults case. The trait must shout with an
        // actionable hint rather than silently skipping validation.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = '';

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('openApiSpec() must return a non-empty spec name');

        $this->maybeAutoValidateOpenApiRequest(
            $this->makeJsonRequest('POST', '/v1/pets', ['name' => 'ok']),
            HttpMethod::POST,
            '/v1/pets',
        );
    }

    #[Test]
    public function malformed_json_body_with_content_type_fails_with_clear_message(): void
    {
        // json_decode with JSON_THROW_ON_ERROR raises JsonException on broken
        // bodies; the trait converts it to a PHPUnit failure so the test
        // author sees what happened instead of a mid-stack exception.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = Request::create(
            '/v1/pets',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{not valid json',
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Request body could not be parsed as JSON');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');
    }

    #[Test]
    public function malformed_json_body_without_content_type_adds_hint(): void
    {
        // Missing Content-Type on a non-empty body falls through to the JSON
        // decode path (documented lenient behavior). If it fails, the error
        // message appends a hint that the header was absent so the user
        // knows to set it. Symfony's Request::create auto-sets Content-Type
        // to x-www-form-urlencoded when a body is provided, so we explicitly
        // clear it to reach the "empty Content-Type" branch of extractRequestBody().
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = Request::create('/v1/pets', 'POST', [], [], [], [], '{not valid json');
        $request->headers->remove('Content-Type');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('no Content-Type header was present on the request');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');
    }

    #[Test]
    public function non_json_content_type_body_is_treated_as_null(): void
    {
        // Regression guard: a form-urlencoded body must not be handed to the
        // validator as raw content — it returns `null` so the validator's
        // body-schema check runs against "no JSON body" and surfaces the
        // right error rather than a coercion one.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;

        $request = Request::create(
            '/v1/pets',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            'name=fido',
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/v1/pets');
    }

    // ========================================
    // Issue #179: skip_request_validation_response_codes
    // ========================================

    #[Test]
    public function config_file_defaults_skip_request_validation_response_codes_to_422_and_400(): void
    {
        // Issue #179 default: opted-in users get 422/400 downgrades for free.
        // Pinning the literal default here so the value cannot drift silently.
        $config = require __DIR__ . '/../../src/Laravel/config.php';

        $this->assertArrayHasKey('skip_request_validation_response_codes', $config);
        $this->assertSame(['422', '400'], $config['skip_request_validation_response_codes']);
    }

    #[Test]
    public function invalid_request_with_documented_4xx_response_is_skipped_not_failed(): void
    {
        // The end-to-end goal of #179: an invalid POST body that yields a
        // documented 422 must not surface as a request-validation failure
        // when auto_validate_request is on with the default 422/400 skip set.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'request-validation-skip';

        $request = $this->makeJsonRequest('POST', '/exact-422', []); // missing required `name`

        // Must NOT throw — the documented 422 response downgrades the failure.
        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/exact-422', 422);

        // Coverage still records the endpoint as touched on the request side.
        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('request-validation-skip', $covered);
        $this->assertArrayHasKey('POST /exact-422', $covered['request-validation-skip']);
    }

    #[Test]
    public function invalid_request_with_undocumented_4xx_response_still_fails(): void
    {
        // /no-4xx documents only 200/500. A 422 there is a spec gap, and the
        // downgrade must NOT swallow it — the test author needs to see that
        // their impl is returning a status the spec never declared.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'request-validation-skip';

        $request = $this->makeJsonRequest('POST', '/no-4xx', []);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/no-4xx', 422);
    }

    #[Test]
    public function downgrade_off_when_skip_request_validation_response_codes_empty(): void
    {
        // Opt-out: empty array disables the feature so strict request-side
        // validation is preserved.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'request-validation-skip';
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.skip_request_validation_response_codes'] = [];

        $request = $this->makeJsonRequest('POST', '/exact-422', []);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/exact-422', 422);
    }

    #[Test]
    public function two_hundred_response_does_not_trigger_downgrade(): void
    {
        // Common bug shape this guards against: a 200-expected test starts
        // returning 200 for an invalid body (impl missed the validation).
        // The downgrade must NOT fire on 200; the failure must still fail.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'request-validation-skip';

        $request = $this->makeJsonRequest('POST', '/exact-422', []);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/exact-422', 200);
    }

    #[Test]
    public function records_request_skip_reason_in_coverage_for_downgraded_failure(): void
    {
        // Issue #179 ゴール: coverage records "request validation skipped due
        // to documented 4xx response" so the spec-coverage report still
        // reflects that the endpoint was exercised, but with a skip reason
        // attached rather than a clean validated state.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'request-validation-skip';

        $request = $this->makeJsonRequest('POST', '/exact-422', []);
        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/exact-422', 422);

        $state = OpenApiCoverageTracker::exportState();
        $endpoint = $state['specs']['request-validation-skip']['POST /exact-422'] ?? null;
        $this->assertNotNull($endpoint);
        $this->assertTrue($endpoint['requestReached']);
        $this->assertArrayHasKey('requestSkipReason', $endpoint);
        $this->assertNotNull($endpoint['requestSkipReason']);
        $this->assertStringContainsString('422', (string) $endpoint['requestSkipReason']);
    }

    #[Test]
    public function request_validator_cache_invalidates_when_skip_codes_config_changes(): void
    {
        // The trait's cache is keyed on (maxErrors, skipRequestValidationResponseCodes).
        // When the second value changes mid-test (e.g. one test toggles it off
        // for strict assertions), the cached validator must be rebuilt or the
        // new config silently won't take effect.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'request-validation-skip';

        // First call: default ['422', '400'] — invalid body + documented 422
        // response is downgraded silently (no exception).
        $request = $this->makeJsonRequest('POST', '/exact-422', []);
        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/exact-422', 422);

        // Toggle to strict mode mid-test — the cached validator must be
        // rebuilt with the new (empty) skip set so the next call fails loud.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.skip_request_validation_response_codes'] = [];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        $this->maybeAutoValidateOpenApiRequest(
            $this->makeJsonRequest('POST', '/exact-422', []),
            HttpMethod::POST,
            '/exact-422',
            422,
        );
    }

    #[Test]
    public function backward_compat_call_without_response_status_still_works(): void
    {
        // Direct callers (test harnesses, framework adapters not yet
        // forwarding response status) keep working — the trait method's new
        // 4th arg is optional and defaults to null, so the old 3-arg shape
        // continues to validate without downgrade.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_validate_request'] = true;
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = 'request-validation-skip';

        $request = $this->makeJsonRequest('POST', '/exact-422', []);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenAPI request validation failed');

        // 3-arg call (no responseStatusCode) — must behave exactly like before #179.
        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::POST, '/exact-422');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makeJsonRequest(string $method, string $path, array $body): Request
    {
        return Request::create(
            $path,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
