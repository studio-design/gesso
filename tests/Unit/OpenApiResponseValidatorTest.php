<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_map;
use function count;
use function implode;
use function range;
use function restore_error_handler;
use function set_error_handler;

class OpenApiResponseValidatorTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideSkip_boundary_casesCases(): iterable
    {
        // Pin the exact 5xx boundary so that a future tweak to the default
        // pattern (e.g. accidental `5\d+` instead of `5\d\d`) cannot silently
        // widen or narrow the skip window.
        yield '499 is not skipped' => [499, false];
        yield '500 is skipped' => [500, true];
        yield '599 is skipped' => [599, true];
        yield '600 is not skipped' => [600, false];
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideRange_keys_match_for_each_leading_digitCases(): iterable
    {
        yield '1XX matches 199' => [199, '1XX'];
        yield '2XX matches 299' => [299, '2XX'];
        yield '3XX matches 399' => [399, '3XX'];
        yield '4XX matches 499' => [499, '4XX'];
    }

    // ========================================
    // OAS 3.0 tests
    // ========================================

    #[Test]
    public function rejects_unsupported_openapi_version(): void
    {
        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage("Unsupported OpenAPI version: '3.3.0' (string)");

        $this->validator->validate('unsupported-version', 'GET', '/v1/pets', 200, null);
    }

    #[Test]
    public function root_json_schema_dialect_selects_draft_07_validation(): void
    {
        $valid = $this->validator->validate(
            'dialect-draft07',
            'GET',
            '/tuple',
            200,
            ['name', 42],
            'application/json',
        );
        $invalid = $this->validator->validate(
            'dialect-draft07',
            'GET',
            '/tuple',
            200,
            ['name', 'not-an-integer'],
            'application/json',
        );

        $this->assertTrue($valid->isValid(), $valid->errorMessage());
        $this->assertFalse($invalid->isValid());
    }

    #[Test]
    public function unsupported_root_json_schema_dialect_fails_explicitly(): void
    {
        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Unsupported JSON Schema dialect');

        $this->validator->validate('unsupported-dialect', 'GET', '/anything', 200, null);
    }

    #[Test]
    public function openapi_32_plain_and_new_operation_forms_are_validated(): void
    {
        $plain = $this->validator->validate(
            'openapi-3.2',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            'application/json',
        );
        $query = $this->validator->validate('openapi-3.2', 'QUERY', '/v1/search', 200, [], 'application/json');
        $custom = $this->validator->validate(
            'openapi-3.2',
            'COPY',
            '/v1/pets/1',
            200,
            ['id' => 2, 'name' => 'Copy'],
            'application/json',
        );

        $this->assertTrue($plain->isValid(), $plain->errorMessage());
        $this->assertTrue($query->isValid(), $query->errorMessage());
        $this->assertTrue($custom->isValid(), $custom->errorMessage());
    }

    #[Test]
    public function openapi_32_discriminator_default_mapping_handles_missing_and_unknown_values(): void
    {
        $missing = $this->validator->validate(
            'openapi-3.2',
            'GET',
            '/v1/pets/1',
            200,
            ['name' => 'Mystery'],
            'application/json',
        );
        $unknown = $this->validator->validate(
            'openapi-3.2',
            'GET',
            '/v1/pets/1',
            200,
            ['kind' => 'bird', 'name' => 'Tweety'],
            'application/json',
        );
        $invalidFallback = $this->validator->validate(
            'openapi-3.2',
            'GET',
            '/v1/pets/1',
            200,
            ['kind' => 'bird'],
            'application/json',
        );

        $this->assertTrue($missing->isValid(), $missing->errorMessage());
        $this->assertTrue($unknown->isValid(), $unknown->errorMessage());
        $this->assertFalse($invalidFallback->isValid());
    }

    #[Test]
    public function openapi_32_item_schema_response_is_explicitly_skipped(): void
    {
        $result = $this->validator->validate(
            'openapi-3.2',
            'GET',
            '/v1/events',
            200,
            "event: updated\n\ndata: {}\n\n",
            'text/event-stream',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('itemSchema', $result->skipReason() ?? '');
        $this->assertSame('text/event-stream', $result->matchedContentType());
    }

    #[Test]
    public function v30_valid_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 1, 'name' => 'Fido', 'tag' => 'dog'],
                    ['id' => 2, 'name' => 'Whiskers', 'tag' => null],
                ],
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_invalid_response_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 'not-an-int', 'name' => 'Fido'],
                ],
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_parameterized_path_matches(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/123',
            200,
            [
                'data' => [
                    'id' => 1,
                    'name' => 'Fido',
                    'tag' => null,
                    'owner' => null,
                ],
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function v30_no_content_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'DELETE',
            '/v1/pets/123',
            204,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function unknown_path_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/unknown',
            200,
            [],
        );

        $this->assertFalse($result->isValid());
        $error = $result->errors()[0];
        // The error must surface the request method (so a method-mismatch is
        // obvious at a glance) and include "did you mean?" suggestions drawn
        // from the spec's actual paths.
        $this->assertStringContainsString("No matching path found in 'petstore-3.0' spec for GET /v1/unknown", $error);
        $this->assertStringContainsString('closest spec paths:', $error);
        $this->assertStringContainsString('GET /v1/pets', $error);
    }

    #[Test]
    public function unknown_path_error_reveals_stripped_prefix(): void
    {
        // When the user configured strip_prefixes the matcher silently drops
        // the prefix before comparing — without this hint, a near-miss
        // (request `/api/v1/...` vs spec `/v1/...`) is invisible.
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs', ['/api']);

        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/api/v1/unknown',
            200,
            [],
        );

        $this->assertFalse($result->isValid());
        $error = $result->errors()[0];
        $this->assertStringContainsString("searched as: /v1/unknown (after stripping prefix '/api')", $error);
    }

    #[Test]
    public function path_not_found_error_renders_full_diagnostic_block(): void
    {
        // End-to-end pin of the path-not-found diagnostic wording. Locks the
        // indentation, line ordering, prefix-stripping callout, and the
        // (method, path) layout of the suggestion list so future formatting
        // tweaks surface as visible test diffs rather than silent UX drift.
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs', ['/api']);

        $result = $this->validator->validate(
            'path-suggester-pin',
            'GET',
            '/api/v2/admin/totally-nonexistent-endpoint-xyz',
            200,
            [],
        );

        $this->assertFalse($result->isValid());
        $expected = "No matching path found in 'path-suggester-pin' spec for GET /api/v2/admin/totally-nonexistent-endpoint-xyz\n"
            . "  searched as: /v2/admin/totally-nonexistent-endpoint-xyz (after stripping prefix '/api')\n"
            . "  closest spec paths:\n"
            . "    - GET /v2/admin/early_accesses\n"
            . "    - POST /v2/admin/early_accesses\n"
            . '    - GET /v2/admin/users/{user_id}';
        $this->assertSame($expected, $result->errors()[0]);
    }

    #[Test]
    public function undefined_method_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets',
            200,
            [],
        );

        $this->assertFalse($result->isValid());
        $error = $result->errors()[0];
        $this->assertStringContainsString('Method PATCH not defined', $error);
        // List the methods the spec actually defines for the matched path so
        // a method-mismatch typo (POST→PATCH, GET→POST) resolves in one read.
        $this->assertStringContainsString('Defined methods: GET, POST', $error);
    }

    #[Test]
    public function undefined_status_code_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            404,
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Status code 404 not defined', $result->errors()[0]);
    }

    // ========================================
    // Skip-by-status-code tests
    // ========================================

    #[Test]
    public function v30_500_response_is_skipped_by_default(): void
    {
        // petstore-3.0 defines 500 with an empty schema. The default skip
        // pattern must short-circuit even when the status code is defined,
        // so tests exercising production-only error paths stay green.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            ['error' => 'something went wrong'],
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_503_response_is_skipped_by_default(): void
    {
        // petstore-3.0 does NOT define 503. The default skip pattern must
        // suppress the normal "Status code 503 not defined" failure so that
        // unexpected 5xx in test environments doesn't produce extra noise.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            503,
            ['error' => 'service unavailable'],
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_499_response_is_not_skipped_by_default(): void
    {
        // 499 is outside the 5xx default pattern — validation should proceed
        // to the normal "Status code not defined" failure for this spec.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            499,
            ['error' => 'client closed request'],
        );

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertStringContainsString('Status code 499 not defined', $result->errors()[0]);
    }

    #[Test]
    public function v30_299_response_is_not_skipped_by_default(): void
    {
        // 299 is a 2xx, not in the default 5xx skip window. It must fall
        // through to the existing "not defined" failure.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            299,
            ['data' => []],
        );

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertStringContainsString('Status code 299 not defined', $result->errors()[0]);
    }

    #[Test]
    public function skip_response_codes_can_be_disabled(): void
    {
        // Opting out of the default skip list means 5xx behaves like any
        // other status code again — a 503 not in the spec becomes a failure.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            503,
            ['error' => 'service unavailable'],
        );

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertStringContainsString('Status code 503 not defined', $result->errors()[0]);
    }

    #[Test]
    public function custom_skip_response_codes_pattern(): void
    {
        // Users can widen the skip set to cover 4xx or a specific code.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: ['4\d\d', '5\d\d']);

        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            404,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
    }

    #[Test]
    public function skip_pattern_is_anchored(): void
    {
        // Anchoring matters: "20" must not match "201". Without anchors, a
        // pattern like "20" would accidentally skip any code starting with 20.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: ['20']);

        $result = $validator->validate(
            'content-without-schema',
            'GET',
            '/widgets',
            201,
            null,
        );

        $this->assertFalse($result->isSkipped());
        $this->assertTrue($result->isValid());
    }

    #[Test]
    #[DataProvider('provideSkip_boundary_casesCases')]
    public function skip_boundary_cases(int $statusCode, bool $expectSkipped): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            $statusCode,
            null,
        );

        $this->assertSame($expectSkipped, $result->isSkipped());
    }

    #[Test]
    public function v31_5xx_response_is_skipped_by_default(): void
    {
        // petstore-3.1 does NOT define 503; skip must still fire under OAS 3.1
        // so a future move of the skip check past version-specific schema
        // conversion doesn't silently regress.
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            503,
            ['error' => 'service unavailable'],
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function multiple_skip_patterns_are_ored(): void
    {
        // Regression guard: the foreach in matchingSkipPattern must try every
        // configured pattern, not short-circuit on the first.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: ['4\d\d', '5\d\d']);

        $result404 = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 404, null);
        $result500 = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 500, null);

        $this->assertTrue($result404->isSkipped());
        $this->assertTrue($result500->isSkipped());
    }

    #[Test]
    public function skip_reason_includes_matched_pattern(): void
    {
        // skipReason() carries enough detail to audit which configured pattern
        // fired, not just which status code triggered — useful when running
        // with multiple distinct patterns.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: ['4\d\d', '5\d\d']);

        $result = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 503, null);

        $this->assertTrue($result->isSkipped());
        $this->assertSame('status 503 matched skip pattern 5\d\d', $result->skipReason());
    }

    #[Test]
    public function numeric_string_pattern_key_survives_array_coercion(): void
    {
        // Regression guard: PHP coerces canonical-integer string array keys
        // (e.g. "503") to int keys. The internal skipPatterns map is keyed by
        // the raw user-supplied pattern, so a numeric pattern like "503"
        // lands under int key 503. Without a string cast at retrieval time,
        // skipReason() would return an int and violate its ?string contract.
        //
        // The Laravel adapter's skipResponseCode(int) path stringifies ints
        // to bare "503" before passing them in, which exposes exactly this
        // shape to compileSkipPatterns(). Pin the round-trip so a future
        // refactor that drops the (string) cast fails here, not at the
        // caller site where the root cause is less obvious.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: ['503']);

        $result = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 503, null);

        $this->assertTrue($result->isSkipped());
        $this->assertSame('status 503 matched skip pattern 503', $result->skipReason());
    }

    #[Test]
    public function invalid_skip_pattern_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('skipResponseCodes');
        $this->expectExceptionMessage('(unclosed');

        new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: ['(unclosed']);
    }

    #[Test]
    public function invalid_skip_pattern_error_includes_preg_detail(): void
    {
        try {
            new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: ['(unclosed']);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            // The message carries both the offending raw pattern and a
            // preg_last_error_msg() trailer (wording varies across PHP
            // versions — "Internal error", "missing )", etc.). Assert both
            // structural pieces are present rather than locking to a
            // specific PCRE wording.
            $this->assertStringContainsString('(unclosed', $e->getMessage());
            $this->assertMatchesRegularExpression('/: \S/', $e->getMessage());
        }
    }

    #[Test]
    public function empty_skip_pattern_is_rejected(): void
    {
        // An empty pattern is never legitimate for status-code matching
        // (status codes are always non-empty), so reject it at construction
        // instead of silently accepting a regex that matches nothing.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be an empty string');

        new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: ['']);
    }

    // ========================================
    // OAS 3.0 JSON-compatible content type tests
    // ========================================

    #[Test]
    public function v30_problem_json_valid_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            400,
            [
                'type' => 'https://example.com/bad-request',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Invalid query parameter',
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_problem_json_invalid_response_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            400,
            [
                'type' => 'https://example.com/bad-request',
                'title' => 'Bad Request',
                'status' => 'not-an-integer',
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_problem_json_empty_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            400,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Response body is empty', $result->errors()[0]);
    }

    #[Test]
    public function v30_non_json_content_type_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            415,
            '<error>Unsupported</error>',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_case_insensitive_content_type_matches(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            422,
            [
                'type' => 'https://example.com/validation-error',
                'title' => 'Validation Error',
                'status' => 422,
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_json_content_type_without_schema_skips_validation(): void
    {
        // The content-without-schema fixture declares application/json with no
        // schema on 201, outside the default 5xx skip window — so the default
        // validator reaches the schema-less branch directly.
        $result = $this->validator->validate(
            'content-without-schema',
            'GET',
            '/widgets',
            201,
            ['error' => 'something went wrong'],
        );

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertSame('/widgets', $result->matchedPath());
    }

    #[Test]
    public function v30_text_html_only_content_type_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/logout',
            200,
            '<html><body>Logged out</body></html>',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/logout', $result->matchedPath());
    }

    #[Test]
    public function v30_mixed_json_and_non_json_content_types_validates_json_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['error' => 'Pet already exists'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_mixed_content_types_with_invalid_json_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['wrong_key' => 'value'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    // ========================================
    // OAS 3.0 content negotiation tests (responseContentType parameter)
    // ========================================

    #[Test]
    public function v30_mixed_content_type_with_non_json_response_content_type_is_skipped(): void
    {
        // The 409 response declares both `application/json` and a `text/html`
        // entry that carries a `schema`. A `text/html` response matches the
        // latter — a non-JSON schema this engine cannot evaluate — so the
        // result is Skipped (issue #254), not a clean Success.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/html',
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
        $this->assertSame('text/html', $result->matchedContentType());
        $this->assertNotNull($result->skipReason());
    }

    #[Test]
    public function v30_mixed_content_type_with_json_response_content_type_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['error' => 'Pet already exists'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_mixed_content_type_with_json_response_content_type_and_invalid_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['wrong_key' => 'value'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_response_content_type_not_in_spec_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/plain',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Response Content-Type 'text/plain' is not defined for", $result->errors()[0]);
        $this->assertStringContainsString('text/html', $result->errors()[0]);
        $this->assertStringContainsString('application/json', $result->errors()[0]);
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_response_content_type_with_charset_matches_spec(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/html; charset=utf-8',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_json_response_content_type_with_charset_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['error' => 'Pet already exists'],
            'application/json; charset=utf-8',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_json_response_content_type_with_charset_and_invalid_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            ['wrong_key' => 'value'],
            'application/json; charset=utf-8',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_vendor_json_response_content_type_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            400,
            [
                'type' => 'https://example.com/bad-request',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Invalid query parameter',
            ],
            'application/problem+json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_case_insensitive_response_content_type_matches_spec(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'Text/HTML',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_null_response_content_type_preserves_existing_behavior(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Response body is empty', $result->errors()[0]);
    }

    #[Test]
    public function v30_non_json_only_spec_with_matching_response_content_type_is_skipped(): void
    {
        // The spec's `text/html` 200 entry declares a `schema`. Matching the
        // response Content-Type to that key does NOT make the body validated
        // — this engine cannot evaluate a non-JSON schema (issue #254), so
        // the result is Skipped (isValid() stays true) and carries the
        // matched media-type key for coverage.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/logout',
            200,
            null,
            'text/html',
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/logout', $result->matchedPath());
        $this->assertSame('text/html', $result->matchedContentType());
        $this->assertNotNull($result->skipReason());
    }

    #[Test]
    public function non_json_response_content_type_with_a_schema_is_skipped(): void
    {
        // Issue #254: the spec declares `text/plain` WITH a `schema` for the
        // 200 response. The response Content-Type matches that key, but a
        // non-JSON schema cannot be evaluated by this JSON-Schema engine —
        // the orchestrator must surface a Skipped result, not a clean
        // Success, so the unvalidated body is not miscounted.
        $result = $this->validator->validate(
            'non-json-content-schema',
            'GET',
            '/text-with-schema',
            200,
            'plain body',
            'text/plain',
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/text-with-schema', $result->matchedPath());
        $this->assertSame('text/plain', $result->matchedContentType());
        $this->assertNotNull($result->skipReason());
        $this->assertStringContainsString('JSON Schema engine only', (string) $result->skipReason());
    }

    #[Test]
    public function non_json_response_content_type_without_a_schema_succeeds(): void
    {
        // The `text/plain` 200 entry declares NO `schema` — there is nothing
        // to validate, so the body is silently accepted (Success, not
        // Skipped). This keeps coverage reports quiet for endpoints whose
        // spec genuinely has no schema to check.
        $result = $this->validator->validate(
            'non-json-content-schema',
            'GET',
            '/text-without-schema',
            200,
            'plain body',
            'text/plain',
        );

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertSame('/text-without-schema', $result->matchedPath());
    }

    #[Test]
    public function non_json_body_skip_does_not_mask_a_missing_required_header(): void
    {
        // Regression guard for the `&& $headerErrors === []` gate on the
        // issue #254 skip branch: the response body is skip-eligible (non-JSON
        // `text/plain` with a schema), but the spec also requires an
        // `X-Trace-Id` response header that the response omits. The header
        // failure must still fail the result loudly — the body skip must not
        // swallow it.
        $result = $this->validator->validate(
            'non-json-content-schema',
            'GET',
            '/text-with-schema-and-required-header',
            200,
            'plain body',
            'text/plain',
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertStringContainsString('X-Trace-Id', $result->errorMessage());
    }

    // ========================================
    // OAS 3.1 tests
    // ========================================

    #[Test]
    public function v31_valid_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 1, 'name' => 'Fido', 'tag' => 'dog'],
                    ['id' => 2, 'name' => 'Whiskers', 'tag' => null],
                ],
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v31_invalid_response_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 'not-an-int', 'name' => 'Fido'],
                ],
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v31_problem_json_valid_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets',
            400,
            [
                'type' => 'https://example.com/bad-request',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => null,
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v31_non_json_content_type_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'POST',
            '/v1/pets',
            415,
            '<error>Unsupported</error>',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v31_no_content_response_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'DELETE',
            '/v1/pets/123',
            204,
            null,
        );

        $this->assertTrue($result->isValid());
    }

    // ========================================
    // maxErrors tests
    // ========================================

    #[Test]
    public function default_max_errors_reports_multiple_errors(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 'not-an-int', 'name' => 123],
                    ['id' => 'also-not-an-int', 'name' => 456],
                ],
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertGreaterThan(1, count($result->errors()));
    }

    #[Test]
    public function max_errors_caps_reported_errors_to_configured_limit(): void
    {
        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );

        $capped = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), maxErrors: 5);
        $cappedResult = $capped->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => $items],
        );

        $unlimited = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), maxErrors: 0);
        $unlimitedResult = $unlimited->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => $items],
        );

        $this->assertFalse($cappedResult->isValid());
        $this->assertFalse($unlimitedResult->isValid());
        $this->assertLessThan(
            count($unlimitedResult->errors()),
            count($cappedResult->errors()),
        );
    }

    #[Test]
    public function max_errors_one_limits_to_single_error(): void
    {
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), maxErrors: 1);
        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 'not-an-int', 'name' => 123],
                    ['id' => 'also-not-an-int', 'name' => 456],
                ],
            ],
        );

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->errors());
    }

    #[Test]
    public function max_errors_two_reports_more_than_one_error(): void
    {
        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );

        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), maxErrors: 2);
        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => $items],
        );

        $this->assertFalse($result->isValid());
        $this->assertGreaterThan(1, count($result->errors()));
    }

    #[Test]
    public function max_errors_zero_reports_all_errors(): void
    {
        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );

        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), maxErrors: 0);
        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => $items],
        );

        $this->assertFalse($result->isValid());
        $this->assertGreaterThan(20, count($result->errors()));
    }

    #[Test]
    public function negative_max_errors_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxErrors must be 0 (unlimited) or a positive integer, got -1.');

        new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), maxErrors: -1);
    }

    // ========================================
    // Strip prefix tests
    // ========================================

    #[Test]
    public function v30_strip_prefixes_applied(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs', ['/api']);

        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/api/v1/pets',
            200,
            [
                'data' => [
                    ['id' => 1, 'name' => 'Fido'],
                ],
            ],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function validates_response_body_against_ref_backed_schema(): void
    {
        // End-to-end: loader resolves Pet -> Category -> Label refs, converter
        // drops OAS keys, opis validates the resolved schema. A bug in any
        // layer of the chain surfaces as a failure here.
        $result = $this->validator->validate(
            'refs-valid',
            'GET',
            '/pets',
            200,
            [
                ['id' => 1, 'name' => 'Fido', 'category' => ['id' => 7, 'label' => 'dog']],
            ],
        );

        $this->assertTrue($result->isValid(), 'errors: ' . implode(' | ', $result->errors()));
        $this->assertSame('/pets', $result->matchedPath());
    }

    #[Test]
    public function validates_response_body_with_ref_as_property_name(): void
    {
        // End-to-end: a spec that models a JSON Patch payload legally names a
        // property `$ref`. The resolver must not misread it as a Reference
        // Object, and the downstream converter + opis must accept a response
        // body that carries a `$ref` field.
        $result = $this->validator->validate(
            'refs-property-name',
            'GET',
            '/patches',
            200,
            [
                'op' => 'replace',
                'path' => '/a/b',
                '$ref' => '#/definitions/Foo',
            ],
        );

        $this->assertTrue($result->isValid(), 'errors: ' . implode(' | ', $result->errors()));
        $this->assertSame('/patches', $result->matchedPath());
    }

    // ========================================
    // readOnly / writeOnly enforcement
    // ========================================

    #[Test]
    public function response_body_containing_write_only_property_fails_validation(): void
    {
        // The spec marks `password` as writeOnly — the server must not include
        // it in a response.
        $result = $this->validator->validate(
            'readwrite',
            'POST',
            '/users',
            201,
            ['id' => 1, 'name' => 'Ada', 'password' => 'leaked-secret'],
        );

        $this->assertFalse($result->isValid(), 'writeOnly password should be rejected in response');
        $errorMessage = implode(' | ', $result->errors());
        $this->assertStringContainsString('password', $errorMessage);
    }

    #[Test]
    public function response_body_without_write_only_property_passes_even_when_spec_lists_it_required(): void
    {
        // The spec lists `password` in `required`, but since the property is
        // writeOnly the response side must treat it as absent — and a compliant
        // response that omits it should validate.
        $result = $this->validator->validate(
            'readwrite',
            'POST',
            '/users',
            201,
            ['id' => 1, 'name' => 'Ada'],
        );

        $this->assertTrue($result->isValid(), 'errors: ' . implode(' | ', $result->errors()));
    }

    #[Test]
    public function body_validator_exception_is_captured_as_boundary_error(): void
    {
        // Response-side symmetry for the request-side regression guard. The
        // fixture's 200 response schema carries the same malformed `pattern`
        // ("[unterminated") that opis rejects with InvalidKeywordException.
        // Without the inlined try/catch this would escape as an uncaught
        // throw; post-fix it is a structured [response-body] failure.
        $result = $this->validator->validate(
            'body-validator-throws',
            'POST',
            '/items/1',
            200,
            ['code' => 'x'],
            'application/json',
        );

        $this->assertFalse($result->isValid());

        $joined = implode(' | ', $result->errors());
        $this->assertStringContainsString('[response-body]', $joined);
        $this->assertStringContainsString('InvalidKeywordException', $joined);
        // Pin the absence of "(caused by ...)" when the thrown exception
        // has no previous — without this assertion the suffix-formatting
        // branch could regress to always emitting it (or omitting it
        // unconditionally) and tests would still pass.
        $this->assertStringNotContainsString('(caused by', $joined);
    }

    // ========================================
    // Response header validation
    // ========================================

    #[Test]
    public function null_response_headers_argument_preserves_legacy_body_only_behaviour(): void
    {
        // The spec defines a required Location header, but `null` opts
        // out of header validation entirely so the body-only path passes.
        $result = $this->validator->validate(
            'response-headers',
            'POST',
            '/pets',
            201,
            ['id' => 1],
            'application/json',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function detects_missing_required_response_header(): void
    {
        $result = $this->validator->validate(
            'response-headers',
            'POST',
            '/pets',
            201,
            ['id' => 1],
            'application/json',
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertContains(
            '[response-header.Location] required header is missing.',
            $result->errors(),
        );
    }

    #[Test]
    public function passes_when_required_header_is_present_and_optional_is_valid(): void
    {
        $result = $this->validator->validate(
            'response-headers',
            'POST',
            '/pets',
            201,
            ['id' => 1],
            'application/json',
            [
                'Location' => 'https://example.com/pets/1',
                'X-RateLimit-Remaining' => '42',
            ],
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function detects_optional_header_schema_violation(): void
    {
        $result = $this->validator->validate(
            'response-headers',
            'POST',
            '/pets',
            201,
            ['id' => 1],
            'application/json',
            [
                'Location' => 'https://example.com/pets/1',
                'X-RateLimit-Remaining' => '-5',
            ],
        );

        $this->assertFalse($result->isValid());
        $joined = implode(' | ', $result->errors());
        $this->assertStringContainsString('[response-header.X-RateLimit-Remaining]', $joined);
    }

    #[Test]
    public function combines_body_and_header_errors_in_single_result(): void
    {
        // Body fails (missing required `id`) AND headers fail (missing Location).
        // Both error sets must surface so the developer sees the full picture
        // in one assertion failure rather than chasing them sequentially.
        $result = $this->validator->validate(
            'response-headers',
            'POST',
            '/pets',
            201,
            ['name' => 'fido'],
            'application/json',
            [],
        );

        $this->assertFalse($result->isValid());
        $joined = implode(' | ', $result->errors());
        // Body errors use the opis JSON-Pointer prefix (`[/]`); header errors
        // use the `[response-header.<Name>]` prefix. Both categories must
        // appear so the developer sees the full picture in one assertion.
        $this->assertStringContainsString('id', $joined);
        $this->assertStringContainsString('[response-header.Location]', $joined);
    }

    #[Test]
    public function no_content_response_with_required_header_runs_header_validation(): void
    {
        // The body validator returns no errors for the empty `content`
        // block; this pins that the orchestrator nevertheless runs header
        // validation so 204 + required `Location` (a real-world POST/DELETE
        // pattern) doesn't silently bypass the contract.
        $result = $this->validator->validate(
            'response-headers-edge',
            'DELETE',
            '/items',
            204,
            null,
            null,
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertContains(
            '[response-header.X-Audit-Id] required header is missing.',
            $result->errors(),
        );
    }

    #[Test]
    public function skip_by_status_code_short_circuits_header_validation(): void
    {
        // The default 5xx skip suppresses both body and header checks —
        // covered endpoints that only ever return 5xx in tests should
        // not accumulate `[response-header]` errors against required-header
        // declarations.
        $result = $this->validator->validate(
            'response-headers-edge',
            'GET',
            '/items/never-thrown',
            500,
            null,
            null,
            [],
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
    }

    #[Test]
    public function header_validator_exception_is_caught_by_error_boundary(): void
    {
        // A spec with an unterminated regex pattern in a header schema
        // makes opis throw a SchemaException at validation time. The
        // ValidatorErrorBoundary wrap converts it to a `[response-header]`
        // error string instead of letting the exception abort the test.
        $result = $this->validator->validate(
            'response-headers-edge',
            'GET',
            '/items/throws',
            200,
            (object) [],
            'application/json',
            ['X-Bad-Pattern' => 'value'],
        );

        $this->assertFalse($result->isValid());
        $joined = implode(' | ', $result->errors());
        $this->assertStringContainsString('[response-header]', $joined);
    }

    #[Test]
    public function ref_resolved_header_definition_is_validated_against_inlined_schema(): void
    {
        // `$ref` headers are inlined by OpenApiRefResolver before validation.
        // Pin that the inlined schema (here, `format: uuid`) actually flows
        // through and fails on a non-UUID value.
        $result = $this->validator->validate(
            'response-headers-edge',
            'GET',
            '/items/ref-headers',
            200,
            (object) [],
            'application/json',
            ['X-Trace-Id' => 'not-a-uuid'],
        );

        $this->assertFalse($result->isValid());
        $joined = implode(' | ', $result->errors());
        $this->assertStringContainsString('[response-header.X-Trace-Id', $joined);
    }

    #[Test]
    public function v31_nullable_integer_header_accepts_clean_value(): void
    {
        // Pin the OAS 3.1 `type: ["integer", "null"]` path. The 3.0 fixture
        // suite doesn't exercise multi-type declarations, so without this
        // a regression in the version arg's flow-through would go silent.
        $result = $this->validator->validate(
            'response-headers-edge',
            'GET',
            '/items/v31',
            200,
            (object) [],
            'application/json',
            ['X-Tags-Count' => '5'],
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function malformed_headers_block_surfaces_as_spec_error(): void
    {
        // `headers: "string"` (a YAML/JSON authoring slip) used to
        // silently disable validation for the entire response. Now it
        // produces a `[response-header]` spec error so the spec author
        // notices.
        $result = $this->validator->validate(
            'response-headers-malformed',
            'GET',
            '/items/non-object',
            200,
            (object) [],
            'application/json',
            [],
        );

        $this->assertFalse($result->isValid());
        $joined = implode(' | ', $result->errors());
        $this->assertStringContainsString('[response-header]', $joined);
        $this->assertStringContainsString('must be an object', $joined);
    }

    #[Test]
    public function empty_headers_block_passes_validation(): void
    {
        // `headers: {}` is a legitimate "this response has no documented
        // headers" declaration. No errors should surface.
        $result = $this->validator->validate(
            'response-headers-malformed',
            'GET',
            '/items/empty',
            200,
            (object) [],
            'application/json',
            [],
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function scalar_header_entry_is_reported_per_header(): void
    {
        // A non-array header object inside an otherwise-valid headers map
        // surfaces a per-name error so the spec author can pinpoint which
        // entry needs fixing.
        $result = $this->validator->validate(
            'response-headers-malformed',
            'GET',
            '/items/scalar-entry',
            200,
            (object) [],
            'application/json',
            ['X-Misdefined' => 'whatever'],
        );

        $this->assertFalse($result->isValid());
        $joined = implode(' | ', $result->errors());
        $this->assertStringContainsString('[response-header.X-Misdefined]', $joined);
        $this->assertStringContainsString('must be an object', $joined);
    }

    // ========================================
    // Spec response key fallback: default + 5XX/range keys (#125, #126)
    // ========================================

    #[Test]
    public function default_response_key_validates_unenumerated_status(): void
    {
        // Spec declares only `200` and `default`. A 418 response should
        // validate against the `default` schema rather than failing with
        // "Status code 418 not defined".
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-default',
            418,
            ['error' => 'teapot'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('default', $result->matchedStatusCode());
        $this->assertSame('application/json', $result->matchedContentType());
    }

    #[Test]
    public function default_response_key_still_validates_schema_mismatch(): void
    {
        // Falling back to `default` does not bypass schema validation —
        // a body that doesn't match `default`'s schema still fails.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-default',
            418,
            ['wrong_key' => 'no error field'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('default', $result->matchedStatusCode());
    }

    #[Test]
    public function range_key_5xx_validates_503_response(): void
    {
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-5xx',
            503,
            ['title' => 'Service Unavailable'],
            'application/problem+json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('5XX', $result->matchedStatusCode());
        $this->assertSame('application/problem+json', $result->matchedContentType());
    }

    #[Test]
    public function exact_status_key_takes_precedence_over_range_key(): void
    {
        // Spec declares both `503` (specific) and `5XX` (range). 503 must
        // match the specific key — and the test pins it via the unique
        // `specific: true` field that only the 503 schema requires.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-exact-and-range',
            503,
            ['specific' => true],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('503', $result->matchedStatusCode());
    }

    #[Test]
    public function range_key_falls_through_to_5_x_x_for_other_5xx_codes(): void
    {
        // 599 isn't declared explicitly, so the 5XX range key matches.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-exact-and-range',
            599,
            ['anything' => 'goes'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('5XX', $result->matchedStatusCode());
    }

    #[Test]
    public function lowercase_range_key_5xx_is_accepted(): void
    {
        // OpenAPI permits both upper and lower case for the X in range keys
        // (`5XX` and `5xx` are both legal). The matched key preserves the
        // spec author's casing so coverage reports show what they wrote.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/lowercase-range',
            503,
            ['anything' => 'goes'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('5xx', $result->matchedStatusCode());
    }

    #[Test]
    public function range_key_does_not_match_outside_its_range(): void
    {
        // A 4xx response against a spec that only declares 5XX should still
        // fail (assuming default is not declared and skip patterns are off).
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-5xx',
            418,
            [],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Status code 418 not defined', $result->errors()[0]);
    }

    #[Test]
    public function exact_200_takes_precedence_over_default(): void
    {
        // /with-default-only declares both 200 and default. The exact
        // match must win — verified via a unique required field that
        // exists ONLY on the 200 schema.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-default-only',
            200,
            ['fromExact200' => true],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('200', $result->matchedStatusCode());
    }

    #[Test]
    public function range_key_takes_precedence_over_default_for_5xx(): void
    {
        // /with-range-and-default declares both 5XX and default. A 503
        // response must hit 5XX (range > default), verified via the
        // `from5xx` required field that only the 5XX schema requires.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-range-and-default',
            503,
            ['from5xx' => true],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('5XX', $result->matchedStatusCode());
    }

    #[Test]
    public function default_takes_over_for_non_5xx_when_range_only_covers_5xx(): void
    {
        // Same fixture, but a 418 (non-5xx). 5XX doesn't match → falls
        // through to default. Verified via `fromDefault` required field.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-range-and-default',
            418,
            ['fromDefault' => true],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('default', $result->matchedStatusCode());
    }

    #[Test]
    public function skip_pattern_preempts_default_fallback_for_5xx(): void
    {
        // Pin the order of operations: skip-by-status-code must run BEFORE
        // resolveResponseKey. A spec declaring only `default` + a 503
        // response with the default 5\d\d skip pattern enabled must yield
        // isSkipped() — NOT validated against `default`.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker()); // default skip = ['5\d\d']

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-default',
            503,
            ['error' => 'down'],
            'application/json',
        );

        $this->assertTrue($result->isSkipped());
        // Skip records the literal status, not the resolved spec key,
        // because skip happens before key resolution.
        $this->assertSame('503', $result->matchedStatusCode());
    }

    #[Test]
    #[DataProvider('provideRange_keys_match_for_each_leading_digitCases')]
    public function range_keys_match_for_each_leading_digit(int $status, string $expectedKey): void
    {
        // Pin every range-key class so a typo narrowing the regex to e.g.
        // ^5(?:XX|xx)$ would surface immediately. Status 5XX is exercised
        // by other tests; this provider covers 1XX-4XX. Skip patterns are
        // off so the lookup actually runs (default 5\d\d skip wouldn't fire
        // on these classes anyway).
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'spec-fallback',
            'GET',
            '/with-each-range',
            $status,
            new stdClass(),
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame($expectedKey, $result->matchedStatusCode());
    }

    #[Test]
    public function malformed_response_keys_emit_warning_when_default_fallback_fires(): void
    {
        // /with-typo-and-default has a `40` typo (looks like an attempted
        // truncated 404). When the literal status doesn't match exact or
        // range, and we're about to fall back to `default`, emit a warning
        // so the spec author notices the typo.
        $validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker(), skipResponseCodes: []);

        $captured = [];
        $previous = set_error_handler(static function (int $errno, string $message) use (&$captured): bool {
            $captured[] = $message;

            return true;
        });

        try {
            $result = $validator->validate(
                'spec-fallback',
                'GET',
                '/with-typo-and-default',
                404,
                new stdClass(),
                'application/json',
            );
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result->isValid());
        $this->assertSame('default', $result->matchedStatusCode());
        $joined = implode(' | ', $captured);
        $this->assertStringContainsString("response key '40'", $joined);
        $this->assertStringContainsString('typo', $joined);
    }

    // ========================================
    // matchedStatusCode / matchedContentType propagation (#111)
    // ========================================

    #[Test]
    public function success_propagates_matched_status_and_content(): void
    {
        // Coverage tracking depends on the validator threading the spec
        // status key + media-type key through the result so it can record
        // per-(status, content-type) granularity.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('200', $result->matchedStatusCode());
        $this->assertSame('application/json', $result->matchedContentType());
    }

    #[Test]
    public function failure_still_propagates_matched_status_and_content(): void
    {
        // Schema mismatches still pick a (status, content-type) — they got far
        // enough to know which response definition was being validated. Coverage
        // must record the partial hit even when validation failed.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 'not-an-int', 'name' => 'Fido']]],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('200', $result->matchedStatusCode());
        $this->assertSame('application/json', $result->matchedContentType());
    }

    #[Test]
    public function no_content_response_propagates_status_but_not_content_type(): void
    {
        // 204 responses have no `content` block, so matchedContentType is
        // null even though matchedStatusCode pins the status.
        $result = $this->validator->validate(
            'petstore-3.0',
            'DELETE',
            '/v1/pets/123',
            204,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('204', $result->matchedStatusCode());
        $this->assertNull($result->matchedContentType());
    }

    #[Test]
    public function skipped_response_propagates_literal_status_not_spec_key(): void
    {
        // Skip happens before the spec response map is consulted — coverage
        // tracking reconciles literal status against any 5XX/default key
        // declared in the spec at compute time (see OpenApiCoverageTracker).
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            503,
            null,
        );

        $this->assertTrue($result->isSkipped());
        $this->assertSame('503', $result->matchedStatusCode());
        $this->assertNull($result->matchedContentType());
    }

    #[Test]
    public function content_type_not_in_spec_failure_clears_matched_content(): void
    {
        // text/plain isn't in the 409 content map — validator returns failure
        // and matchedContentType is null because no spec key matched.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            409,
            null,
            'text/plain',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('409', $result->matchedStatusCode());
        $this->assertNull($result->matchedContentType());
    }

    #[Test]
    public function path_or_method_failure_does_not_set_matched_status(): void
    {
        // Failures earlier than status lookup can't pick a status key.
        $unknownPath = $this->validator->validate('petstore-3.0', 'GET', '/v1/unknown', 200, []);
        $undefinedMethod = $this->validator->validate('petstore-3.0', 'PATCH', '/v1/pets', 200, []);

        $this->assertNull($unknownPath->matchedStatusCode());
        $this->assertNull($unknownPath->matchedContentType());
        $this->assertNull($undefinedMethod->matchedStatusCode());
        $this->assertNull($undefinedMethod->matchedContentType());
    }

    #[Test]
    public function optional_header_with_no_schema_passes_silently_when_value_present(): void
    {
        // Documented behaviour: optional headers with no schema have
        // nothing to validate against. A garbage value flows through
        // without error because there is no contract to violate.
        $result = $this->validator->validate(
            'response-headers-edge',
            'GET',
            '/items/optional-no-schema',
            200,
            (object) [],
            'application/json',
            ['X-Loose' => 'literally-anything'],
        );

        $this->assertTrue($result->isValid());
    }

    // ========================================
    // DecodedBody envelope passed directly to validate() (issue #248)
    // ========================================

    #[Test]
    public function validate_accepts_decoded_body_envelope_passed_directly(): void
    {
        // The `mixed` body parameter accepts a DecodedBody envelope directly,
        // not just a bare value — the framework adapters rely on this. The
        // outcome must match the equivalent bare-value call
        // (v30_valid_response_passes). A regression that double-wrapped the
        // envelope in fromLegacy() would fail validation here.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            DecodedBody::present([
                'data' => [
                    ['id' => 1, 'name' => 'Fido', 'tag' => 'dog'],
                ],
            ]),
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function validate_type_checks_present_literal_null_body_envelope(): void
    {
        // A DecodedBody carrying a literal `null` is a PRESENT body — it must
        // be type-checked against the schema, not short-circuited as absent.
        // Against `type: object` it fails with a schema type error, NOT the
        // "Response body is empty" message a bare `null` (absent) would yield.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            DecodedBody::present(null),
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must match the type', $result->errorMessage());
        $this->assertStringNotContainsString('Response body is empty', $result->errorMessage());
    }

    #[Test]
    public function validate_treats_absent_body_envelope_like_a_bare_null(): void
    {
        // DecodedBody::absent() is equivalent to the legacy bare `null` — both
        // mean "no body on the wire" and yield the "Response body is empty"
        // failure against a response that declares a JSON schema.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            DecodedBody::absent(),
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Response body is empty', $result->errorMessage());
    }

    #[Test]
    public function malformed_response_content_block_returns_failure(): void
    {
        // `responses.200.content` is a scalar. Without the guard the scalar
        // reaches ResponseBodyValidator::validate()'s `array $content`
        // parameter and raises an uncaught TypeError (TypeError extends Error,
        // not RuntimeException, so validateBody()'s catch does not see it).
        // The guard surfaces a loud spec error instead, mirroring the
        // request-side `Malformed 'requestBody.content'` guard (issue #256).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/response-scalar-content',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'responses[200].content'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function malformed_response_content_media_type_entry_returns_failure(): void
    {
        // `responses.200.content["application/json"]` is a scalar. The
        // per-media-type guard in ResponseBodyValidator surfaces it as a spec
        // error, which the orchestrator turns into a Failure (issue #256).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/response-scalar-content-media-type',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"]\'',
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function malformed_response_content_schema_returns_failure(): void
    {
        // `responses.200.content["application/json"].schema` is a scalar.
        // Without the guard the scalar would reach OpenApiSchemaConverter and
        // raise a TypeError; the orchestrator now reports a clean spec error.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/response-scalar-content-schema',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"].schema\'',
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function null_response_content_schema_returns_failure(): void
    {
        // Locks `array_key_exists` over `isset` at the orchestrator level: an
        // explicit `schema: null` must surface a Failure, not slip through the
        // downstream presence check as a silent pass.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/response-null-content-schema',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"].schema\'',
            $result->errors()[0],
        );
    }

    #[Test]
    public function malformed_response_status_entry_returns_failure(): void
    {
        // `responses["200"]` is a scalar instead of a response object. Without
        // the guard the scalar reaches validateBody()/validateHeaders()' `array
        // $responseSpec` parameter and raises an uncaught TypeError (TypeError
        // extends Error, not RuntimeException). The guard added for issue #258
        // surfaces a loud spec error, mirroring the content-level guards and
        // RequestBodyValidator's `requestBody` guard.
        $result = $this->validator->validate(
            'malformed-response',
            'GET',
            '/things',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'responses[200]'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function malformed_response_status_entry_keys_message_off_matched_spec_key(): void
    {
        // The spec declares only `default`; a wire status of 200 resolves to
        // the `default` key (SpecResponseKeyResolver runs before the guard).
        // The guard's error message must name the matched spec key
        // (`responses[default]`), not the wire status — `responses[200]` would
        // point at a map entry the spec author never wrote (issue #258).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/response-default-status-scalar',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'responses[default]'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function malformed_paths_node_returns_failure(): void
    {
        // The spec's root `paths` is a scalar. The traversal guard surfaces a
        // loud spec error before it reaches `array_keys()` (which would raise
        // an uncaught TypeError), extending the #256/#258 per-response guards
        // to the spec walk itself (issue #259).
        $result = $this->validator->validate(
            'malformed-paths',
            'GET',
            '/things',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'paths'", $result->errors()[0]);
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function null_paths_node_returns_failure(): void
    {
        // The spec's root `paths` is an explicit `null` (a YAML `paths:` left
        // empty). `isset()` would be false for `null`, so the guard uses
        // `array_key_exists`: a present-but-`null` `paths` must surface as a
        // malformed spec, not be silently coalesced to an empty paths map
        // (issue #259). `get_debug_type` reports the concrete `null` type.
        $result = $this->validator->validate(
            'malformed-paths-null',
            'GET',
            '/things',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'paths'", $result->errors()[0]);
        $this->assertStringContainsString('expected object, got null', $result->errors()[0]);
    }

    #[Test]
    public function malformed_path_item_returns_failure(): void
    {
        // `paths["/scalar-path-item"]` is a scalar. Without the guard the
        // scalar reaches the `array_key_exists()` method lookup and raises an
        // uncaught TypeError. The guard surfaces a loud spec error instead
        // (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/scalar-path-item',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/scalar-path-item\"]'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function null_path_item_returns_failure(): void
    {
        // `paths["/null-path-item"]` is an explicit `null`. The `?? null`
        // fallback on the path-item lookup keeps the `null` value flowing
        // into the `!is_array()` guard rather than coalescing it to an empty
        // path item — so a null path item surfaces as malformed (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/null-path-item',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/null-path-item\"]'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got null', $result->errors()[0]);
    }

    #[Test]
    public function malformed_operation_returns_failure(): void
    {
        // `paths["/scalar-operation"].get` is a scalar. Without the guard the
        // scalar reaches the `array_key_exists()` `responses` lookup and
        // raises an uncaught TypeError (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/scalar-operation',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/scalar-operation\"].get'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function null_operation_returns_failure(): void
    {
        // `paths["/null-operation"].get` is an explicit `null`. The method
        // lookup uses `array_key_exists` (not `isset`), so a `get: null`
        // entry reaches the operation guard as malformed rather than being
        // misreported as an undefined method (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/null-operation',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/null-operation\"].get'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got null', $result->errors()[0]);
    }

    #[Test]
    public function malformed_operation_message_keys_off_matched_spec_path(): void
    {
        // The malformed operation lives under a templated path
        // (`/scalar-operation-template/{id}`); the request hits a concrete
        // `/scalar-operation-template/42`. The guard message must name the
        // matched spec key (the `{id}` template), not the wire request path
        // — mirroring `malformed_response_status_entry_keys_message_off_matched_spec_key`.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/scalar-operation-template/42',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/scalar-operation-template/{id}\"].get'",
            $result->errors()[0],
        );
        $this->assertStringNotContainsString('/scalar-operation-template/42', $result->errors()[0]);
    }

    #[Test]
    public function malformed_responses_map_returns_failure(): void
    {
        // `paths["/scalar-responses-map"].get.responses` is a scalar. Without
        // the guard the scalar reaches `SpecResponseKeyResolver::resolve()`'s
        // `array $responses` parameter and raises an uncaught TypeError. The
        // guard surfaces a loud spec error — the traversal-level sibling of
        // the #258 `responses[$status]` per-entry guard (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/scalar-responses-map',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/scalar-responses-map\"].get.responses'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got string', $result->errors()[0]);
    }

    #[Test]
    public function null_responses_map_returns_failure(): void
    {
        // `paths["/null-responses-map"].get.responses` is an explicit `null`.
        // The `responses` lookup uses `array_key_exists` (not `?? []`), so a
        // present-but-`null` `responses` reaches the guard as malformed while
        // a genuinely absent `responses` key still falls back to an empty map
        // (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/null-responses-map',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/null-responses-map\"].get.responses'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got null', $result->errors()[0]);
    }

    #[Test]
    public function malformed_responses_map_surfaces_even_for_skip_status(): void
    {
        // A malformed `responses` map is a structural spec error, not a
        // status-code-level failure mode — so the guard fires BEFORE the
        // skip-by-status-code check. A 503 (which matches the default
        // `5\d\d` skip pattern) must still surface the malformed map loudly
        // rather than be silently skipped (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/scalar-responses-map',
            503,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/scalar-responses-map\"].get.responses'",
            $result->errors()[0],
        );
    }

    #[Test]
    public function list_paths_node_returns_failure(): void
    {
        // The spec's root `paths` is a JSON list (`[...]`). A list passes
        // `is_array()` but is not an object: `array_keys()` would yield
        // integer keys that mis-resolve silently against every request path.
        // The guard surfaces it as a loud malformed-spec error (issue #259).
        $result = $this->validator->validate(
            'malformed-paths-list',
            'GET',
            '/things',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'paths'", $result->errors()[0]);
        $this->assertStringContainsString('expected object, got list', $result->errors()[0]);
    }

    #[Test]
    public function list_path_item_returns_failure(): void
    {
        // `paths["/list-path-item"]` is a JSON list. A list path item passes
        // `is_array()` but has no method keys, so it would mis-resolve to a
        // misleading "method not defined" — the guard surfaces it as
        // malformed instead (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/list-path-item',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/list-path-item\"]'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got list', $result->errors()[0]);
    }

    #[Test]
    public function list_operation_returns_failure(): void
    {
        // `paths["/list-operation"].get` is a JSON list (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/list-operation',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/list-operation\"].get'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got list', $result->errors()[0]);
    }

    #[Test]
    public function list_responses_map_returns_failure(): void
    {
        // `paths["/list-responses-map"].get.responses` is a JSON list. A list
        // passes `is_array()` and would reach `SpecResponseKeyResolver` with
        // integer keys that never match a status — the guard surfaces it as
        // malformed instead (issue #259).
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/list-responses-map',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'paths[\"/list-responses-map\"].get.responses'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got list', $result->errors()[0]);
    }

    #[Test]
    public function list_response_status_entry_returns_failure(): void
    {
        // `responses["200"]` is a JSON list. The per-entry guard (issue #258)
        // now routes through MalformedSpecNode, so a list response entry is
        // surfaced with the same loud diagnostic as a scalar one.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/list-response-status',
            200,
            ['id' => 1],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'responses[200]'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got list', $result->errors()[0]);
    }
}
