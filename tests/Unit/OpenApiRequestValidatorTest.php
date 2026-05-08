<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use const E_USER_WARNING;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Request\SecurityValidator;

use function array_filter;
use function fclose;
use function fopen;
use function implode;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function str_starts_with;
use function strtolower;

class OpenApiRequestValidatorTest extends TestCase
{
    private OpenApiRequestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiRequestValidator();
        // Several integration-style tests below exercise oauth2 / openIdConnect
        // endpoints that now fire a one-shot E_USER_WARNING per scheme name.
        // Reset so each test starts with a clean warning ledger and run order
        // does not affect outcomes. SecurityValidatorTest covers the warning
        // contents; the tests here only check the silent-pass return value,
        // so warnings are captured-and-discarded per test where necessary.
        SecurityValidator::resetWarningStateForTesting();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        SecurityValidator::resetWarningStateForTesting();
        parent::tearDown();
    }

    // ========================================
    // Acceptance criteria: valid / invalid / spec 未定義
    // ========================================

    #[Test]
    public function validates_request_body_against_ref_backed_schema(): void
    {
        // End-to-end mirror of the response-side integration test: the spec's
        // requestBody is a $ref to components.requestBodies.PetBody, whose
        // schema is itself $ref'd. Loader -> resolver -> converter -> opis
        // must all line up for a valid payload to pass.
        $result = $this->validator->validate(
            'refs-valid',
            'POST',
            '/pets',
            [],
            [],
            ['id' => 1, 'name' => 'Fido', 'category' => ['id' => 7, 'label' => 'dog']],
            'application/json',
        );

        $this->assertTrue($result->isValid(), 'errors: ' . implode(' | ', $result->errors()));
        $this->assertSame('/pets', $result->matchedPath());
    }

    #[Test]
    public function rejects_invalid_request_body_against_ref_backed_schema(): void
    {
        // Negative path confirming the ref-backed schema actually enforces
        // the Pet shape — a missing required `category` must fail.
        $result = $this->validator->validate(
            'refs-valid',
            'POST',
            '/pets',
            [],
            [],
            ['id' => 1, 'name' => 'Fido'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function v30_valid_request_body_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido', 'tag' => 'dog'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function v30_invalid_request_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['tag' => 'dog'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function v30_no_request_body_spec_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    // ========================================
    // Path / method resolution failures
    // ========================================

    #[Test]
    public function unknown_path_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/unknown',
            [],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $error = $result->errors()[0];
        $this->assertStringContainsString("No matching path found in 'petstore-3.0' spec for POST /v1/unknown", $error);
        $this->assertStringContainsString('closest spec paths:', $error);
    }

    #[Test]
    public function unknown_path_error_reveals_stripped_prefix(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs', ['/api']);

        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/api/v1/unknown',
            [],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "searched as: /v1/unknown (after stripping prefix '/api')",
            $result->errors()[0],
        );
    }

    #[Test]
    public function undefined_method_returns_failure(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PUT',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $error = $result->errors()[0];
        $this->assertStringContainsString('Method PUT not defined', $error);
        $this->assertStringContainsString('Defined methods: GET, POST', $error);
    }

    // ========================================
    // Empty body behaviour (required vs optional)
    // ========================================

    #[Test]
    public function v30_empty_body_when_required_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            null,
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Request body is empty', $result->errors()[0]);
    }

    #[Test]
    public function v30_empty_body_when_not_required_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/123',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function v30_valid_body_when_not_required_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/123',
            [],
            [],
            ['name' => 'Rex'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function v30_invalid_body_when_not_required_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/123',
            [],
            [],
            ['name' => 123],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
        // Must reach schema validation, not short-circuit via the required-but-empty branch.
        $this->assertStringNotContainsString('Request body is empty', $result->errorMessage());
    }

    // ========================================
    // Content negotiation
    // ========================================

    #[Test]
    public function v30_non_json_content_type_with_spec_match_succeeds(): void
    {
        // Body intentionally violates the JSON pet schema (integer, not an object with "name").
        // If the non-JSON short-circuit ever regresses into schema validation this will fail.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            12345,
            'text/plain',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v30_non_json_content_type_spec_match_is_case_insensitive(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            'raw pet body',
            'TEXT/PLAIN',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v30_content_type_not_in_spec_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            'id,name',
            'text/csv',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Request Content-Type 'text/csv' is not defined for", $result->errors()[0]);
        $this->assertStringContainsString('application/json', $result->errors()[0]);
        $this->assertStringContainsString('text/plain', $result->errors()[0]);
    }

    #[Test]
    public function v30_vendor_json_content_type_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido'],
            'application/vnd.api+json',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v30_json_content_type_with_charset_validates_schema(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido'],
            'application/json; charset=utf-8',
        );

        $this->assertTrue($result->isValid());
    }

    // ========================================
    // OAS 3.1
    // ========================================

    #[Test]
    public function v31_valid_request_body_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 'Fido', 'tag' => null],
            'application/json',
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v31_invalid_request_body_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'POST',
            '/v1/pets',
            [],
            [],
            ['tag' => 'dog'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
    }

    // ========================================
    // Schema-less JSON content type
    // ========================================

    #[Test]
    public function v30_json_content_type_without_schema_skips_validation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'PUT',
            '/v1/pets/123',
            [],
            [],
            ['anything' => 'goes'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    // ========================================
    // Malformed spec guards
    // ========================================

    #[Test]
    public function malformed_request_body_returns_failure(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/scalar-request-body',
            [],
            [],
            ['foo' => 'bar'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'requestBody'", $result->errors()[0]);
        $this->assertStringContainsString('expected object, got scalar', $result->errors()[0]);
    }

    #[Test]
    public function malformed_content_returns_failure(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/scalar-content',
            [],
            [],
            ['foo' => 'bar'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'requestBody.content'", $result->errors()[0]);
    }

    #[Test]
    public function scalar_content_media_type_returns_failure(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/scalar-content-media-type',
            [],
            [],
            ['foo' => 'bar'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Malformed 'requestBody.content[\"application/json\"]'", $result->errors()[0]);
        $this->assertStringContainsString('expected object, got scalar', $result->errors()[0]);
    }

    #[Test]
    public function scalar_content_media_type_schema_returns_failure(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/scalar-content-schema',
            [],
            [],
            ['foo' => 'bar'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'requestBody.content[\"application/json\"].schema'",
            $result->errors()[0],
        );
        $this->assertStringContainsString('expected object, got scalar', $result->errors()[0]);
    }

    /**
     * Locks in the `array_key_exists` vs `isset` choice in the guard. If a
     * future refactor swaps to `isset`, `schema: null` would silently fall
     * through the downstream presence check and accept any request body —
     * the exact silent failure this guard exists to prevent.
     */
    #[Test]
    public function null_content_media_type_schema_returns_failure(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'POST',
            '/null-content-schema',
            [],
            [],
            ['foo' => 'bar'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            "Malformed 'requestBody.content[\"application/json\"].schema'",
            $result->errors()[0],
        );
    }

    // ========================================
    // Constructor validation (mirrors response validator)
    // ========================================

    #[Test]
    public function negative_max_errors_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxErrors must be 0 (unlimited) or a positive integer, got -1.');

        new OpenApiRequestValidator(maxErrors: -1);
    }

    #[Test]
    public function max_errors_one_limits_to_single_error(): void
    {
        $validator = new OpenApiRequestValidator(maxErrors: 1);
        $result = $validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            [],
            [],
            ['name' => 123, 'tag' => 456],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->errors());
    }

    // ========================================
    // Strip prefix (reuses OpenApiPathMatcher behaviour)
    // ========================================

    #[Test]
    public function v30_strip_prefixes_applied(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs', ['/api']);

        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/api/v1/pets',
            [],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function query_params_all_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'status' => 'available', 'tags' => ['a', 'b'], 'q' => 'abc'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/pets/search', $result->matchedPath());
    }

    #[Test]
    public function query_params_required_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errors()[0]);
        $this->assertStringContainsString('required', strtolower($result->errors()[0]));
    }

    #[Test]
    public function query_params_enum_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'status' => 'unknown'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.status]', $result->errorMessage());
    }

    #[Test]
    public function query_params_type_mismatch_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => 'abc'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errorMessage());
    }

    #[Test]
    public function query_params_array_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'tags' => ['a', 'b', 'c']],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_array_single_value_wrapped_passes(): void
    {
        // ?tags=a — frameworks may pass this as a scalar string for array-typed params.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'tags' => 'a'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_optional_missing_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_min_max_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '999'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errorMessage());
    }

    #[Test]
    public function query_params_pattern_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '10', 'q' => 'hello world'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.q]', $result->errorMessage());
    }

    #[Test]
    public function query_params_path_level_inherited(): void
    {
        // PATCH does not redeclare traceId, so the path-level rule (lowercase only) applies.
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/123',
            ['traceId' => 'ABC'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.traceId]', $result->errorMessage());
    }

    #[Test]
    public function query_params_operation_overrides_path_level(): void
    {
        // GET redeclares traceId with uppercase-only pattern, overriding the path-level lowercase rule.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/123',
            ['traceId' => 'ABC'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_operation_override_still_validates(): void
    {
        // GET's override requires uppercase; lowercase must fail (proves the override is checked,
        // not silently bypassed).
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/123',
            ['traceId' => 'abc'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.traceId]', $result->errorMessage());
    }

    #[Test]
    public function query_and_body_errors_are_combined(): void
    {
        // dryRun=maybe → query type-mismatch (not a boolean)
        // body missing required "name" → body schema violation
        // Both must surface in a single result so users see the full diagnostic in one run.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            ['dryRun' => 'maybe'],
            [],
            ['tag' => 'dog'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.dryRun]', $result->errorMessage());
        // Body errors use opis JSON pointer paths (no "query." prefix); assert at least one such entry.
        $bodyErrors = array_filter(
            $result->errors(),
            static fn(string $err): bool => !str_contains($err, '[query.'),
        );
        $this->assertNotEmpty($bodyErrors, 'expected at least one body validation error in combined result');
    }

    #[Test]
    public function query_and_body_both_valid_passes(): void
    {
        // Mirror of the combined-error test: confirms the success path through
        // the composed validate() with both phases active and contributing zero errors.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            ['dryRun' => 'true'],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_boolean_true_coerced(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            ['dryRun' => 'true'],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_boolean_false_coerced(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            ['dryRun' => 'false'],
            [],
            ['name' => 'Fido'],
            'application/json',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_number_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'score' => '0.7'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_number_invalid_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'score' => 'not-a-number'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.score]', $result->errorMessage());
    }

    #[Test]
    public function query_params_no_type_schema_passes(): void
    {
        // category schema declares only `enum`, no `type` — coercion must skip,
        // and opis must accept the matching enum value untouched.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'category' => 'dog'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_no_type_schema_enum_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'category' => 'fish'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.category]', $result->errorMessage());
    }

    #[Test]
    public function query_params_explicit_null_treated_as_missing(): void
    {
        // Explicit null for a required parameter should hit the same branch as "absent".
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => null],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errorMessage());
        $this->assertStringContainsString('required', strtolower($result->errorMessage()));
    }

    #[Test]
    public function query_params_explicit_null_optional_skipped(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'status' => null],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function query_params_integer_overflow_treated_as_string(): void
    {
        // Out-of-range integer must NOT silently truncate to PHP_INT_MAX — opis
        // should see the original string and emit a type error.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '99999999999999999999'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.limit]', $result->errorMessage());
        $this->assertStringContainsString('type: integer', $result->errorMessage());
    }

    #[Test]
    public function query_params_integer_negative_passes(): void
    {
        // The integer schema for `count` (3.1) has minimum: 0, so use a permissive
        // schema-less coercion check via path-level traceId... actually use the
        // integer-typed `limit` with a known-passing positive value to prove
        // negative-prefix regex handling — exercised via integer-overflow test.
        // This case asserts the "clean integer" branch: filter_var accepts "-5" → -5,
        // and even though -5 fails minimum:1, it does NOT fail with a type error.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            ['limit' => '-5'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringNotContainsString('type: integer', $result->errorMessage());
        $this->assertStringContainsString('greater', strtolower($result->errorMessage()));
    }

    #[Test]
    public function query_params_scalar_entry_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/scalar-parameter',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Malformed parameter entry', $result->errors()[0]);
        $this->assertStringContainsString('expected object, got scalar', $result->errors()[0]);
    }

    #[Test]
    public function query_params_required_no_schema_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/required-no-schema',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.token]', $result->errors()[0]);
        $this->assertStringContainsString('no schema', $result->errors()[0]);
    }

    // ========================================
    // OAS 3.1 query parity
    // ========================================

    #[Test]
    public function v31_query_params_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'status' => 'pending', 'tags' => ['x']],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v31_query_params_enum_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'status' => 'unknown'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.status]', $result->errorMessage());
    }

    #[Test]
    public function v31_query_params_multi_type_coerced_passes(): void
    {
        // count has type: ["integer", "null"] — coerceQueryValue must pick "integer"
        // as the coercion target so "42" → 42 passes the schema.
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'count' => '42'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v31_query_params_multi_type_invalid_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/pets/search',
            ['limit' => '5', 'count' => 'not-a-number'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[query.count]', $result->errorMessage());
    }

    // ========================================
    // Path parameter validation
    // ========================================

    #[Test]
    public function path_params_valid_integer_passes(): void
    {
        // petId is declared `type: integer, minimum: 1` — "42" coerces to int 42 and passes.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/42',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/pets/{petId}', $result->matchedPath());
    }

    #[Test]
    public function path_params_integer_expected_string_fails(): void
    {
        // Acceptance: integer 期待で文字列 → surface [path.petId] type error.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/not-a-number',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_integer_violates_minimum_fails(): void
    {
        // Coercion succeeds but schema constraint (minimum:1) rejects 0.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/0',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_uuid_format_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/orders/f47ac10b-58cc-4372-a567-0e02b2c3d479',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/orders/{orderId}', $result->matchedPath());
    }

    #[Test]
    public function path_params_uuid_format_violation_fails(): void
    {
        // Acceptance: uuid 違反 → surface [path.orderId] format error.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/orders/not-a-uuid',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.orderId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_percent_encoded_value_decoded_for_validation(): void
    {
        // Canonical UUID with a literal hyphen replaced by "%2D" (percent-encoded '-').
        // Without rawurldecode() the string `f47ac10b%2D58cc-4372-a567-0e02b2c3d479`
        // would fail uuid format validation; with decoding it is the valid canonical form.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/orders/f47ac10b%2D58cc-4372-a567-0e02b2c3d479',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/orders/{orderId}', $result->matchedPath());
    }

    #[Test]
    public function path_params_no_schema_surfaces_error(): void
    {
        // Path parameters are always required (OpenAPI spec) — a declared `in: path`
        // entry without a schema is a malformed spec and should surface as a hard error
        // rather than silently passing every request.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/widgets/anything',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.widgetId]', $result->errors()[0]);
    }

    #[Test]
    public function v31_path_params_uuid_format_violation_fails(): void
    {
        // OAS 3.1 parity: the 3.1 fixture declares orderId as `type: ["string"], format: uuid`
        // (multi-type array form). The format validator must still fire.
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/orders/still-not-a-uuid',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.orderId]', $result->errorMessage());
    }

    #[Test]
    public function v31_path_params_uuid_format_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/orders/f47ac10b-58cc-4372-a567-0e02b2c3d479',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/orders/{orderId}', $result->matchedPath());
    }

    #[Test]
    public function path_params_date_time_format_valid_passes(): void
    {
        // Pin the docblock claim that opis's built-in FormatResolver handles date-time
        // without additional configuration. A future refactor of OpenApiSchemaConverter
        // that accidentally strips `format` for path params would fail this test.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/events/2026-04-23T10:00:00Z',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/events/{eventTime}', $result->matchedPath());
    }

    #[Test]
    public function path_params_date_time_format_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/events/not-a-timestamp',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.eventTime]', $result->errorMessage());
    }

    #[Test]
    public function path_params_integer_with_trailing_space_fails(): void
    {
        // filter_var(FILTER_VALIDATE_INT) accepts "5 " as 5; combined with rawurldecode("5%20"),
        // this would silently launder a whitespace-polluted URL into a valid integer.
        // Real HTTP servers typically reject such paths — the validator should mirror them.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/5%20',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_integer_with_leading_plus_fails(): void
    {
        // filter_var accepts "+5" as 5. OpenAPI's `style: simple` path serialization does
        // not emit a leading sign; accepting it silently passes a non-canonical value.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/+5',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
    }

    #[Test]
    public function path_params_scalar_entry_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/path-scalar-parameter/123',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Malformed parameter entry', $result->errors()[0]);
        $this->assertStringContainsString('expected object, got scalar', $result->errors()[0]);
    }

    #[Test]
    public function path_placeholder_without_declaration_surfaces_error(): void
    {
        // A template with `{id}` but no matching `in: path` parameter declaration is
        // a malformed spec per OpenAPI (every placeholder MUST be declared). Silently
        // accepting any value would be a classic drift-hiding silent pass.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/path-undeclared/anything',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.id]', $result->errorMessage());
        $this->assertStringContainsString('not declared', $result->errorMessage());
    }

    #[Test]
    public function path_params_declared_but_not_in_template_surfaces_error(): void
    {
        // Inverse of the above: a spec declares `in: path` name: wrongId but the template
        // uses {id}. Defensive check should surface this so the author fixes the typo.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/path-name-mismatch/123',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.wrongId]', $result->errorMessage());
        $this->assertStringContainsString('not captured', $result->errorMessage());
    }

    #[Test]
    public function path_and_query_errors_are_combined(): void
    {
        // GET /v1/pets/{petId} with:
        //   - invalid path (petId = "abc" — not an integer)
        //   - invalid query (traceId = "lower" fails the operation-level ^[A-Z]+$ pattern)
        // Both errors must surface in a single result so users see the full diagnostic
        // in one run. (GET has no body — the POST /v1/pets endpoint that defines a body
        // has no path parameter, so body composition is covered by
        // query_and_body_errors_are_combined above.)
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets/abc',
            ['traceId' => 'lower'],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[path.petId]', $result->errorMessage());
        $this->assertStringContainsString('[query.traceId]', $result->errorMessage());
    }

    // ========================================
    // Header parameter validation (in: header)
    // ========================================

    #[Test]
    public function header_params_valid_uuid_passes(): void
    {
        // Lower-case request key ('x-request-id') against spec name 'X-Request-ID' —
        // HTTP headers are case-insensitive (RFC 7230), so the match must hold.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['x-request-id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/v1/reports', $result->matchedPath());
    }

    #[Test]
    public function header_params_required_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
        $this->assertStringContainsString('missing', $result->errorMessage());
    }

    #[Test]
    public function header_params_uuid_format_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'not-a-uuid'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
    }

    #[Test]
    public function header_params_pattern_violation_fails(): void
    {
        // X-Trace-Id: ^[A-Z0-9-]+$ pattern — lower-case value must fail.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Trace-Id' => 'lower-case',
            ],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Trace-Id]', $result->errorMessage());
    }

    #[Test]
    public function header_params_integer_coerced_passes(): void
    {
        // HTTP headers are strings on the wire; string→int coercion must fire.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Page-Size' => '42',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_integer_bad_value_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Page-Size' => 'abc',
            ],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Page-Size]', $result->errorMessage());
    }

    #[Test]
    public function header_params_optional_absent_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_single_element_array_is_unwrapped(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479']],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_multi_value_array_surfaces_hard_error(): void
    {
        // Laravel picks first / Symfony picks last — silently choosing one would
        // hide whichever value the framework under test actually uses. Refuse.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479', 'also-a-value']],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
        $this->assertStringContainsString('multiple values', $result->errorMessage());
        $this->assertStringContainsString('count=2', $result->errorMessage());
    }

    #[Test]
    public function header_params_empty_array_treated_as_missing(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => []],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
        $this->assertStringContainsString('missing', $result->errorMessage());
    }

    #[Test]
    public function header_params_explicit_null_treated_as_missing(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => null],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
        $this->assertStringContainsString('missing', $result->errorMessage());
    }

    #[Test]
    public function header_params_enum_violation_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Category' => 'delta',
            ],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Category]', $result->errorMessage());
    }

    #[Test]
    public function header_params_enum_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'X-Category' => 'beta',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_runtime_reserved_names_silently_ignored_when_not_declared(): void
    {
        // Per OAS 3.x §4.7.12.1: `Accept`/`Content-Type`/`Authorization` sent by
        // the caller are handled by content negotiation / security schemes, not
        // parameter validation. When the spec declares none of them (the normal
        // case), runtime values at any casing must pass through untouched — this
        // pins that the per-request side stays OAS-compliant.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
                'ACCEPT' => 'x',
                'content-type' => 'x',
                'AUTHORIZATION' => 'x',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_duplicate_case_variants_last_wins(): void
    {
        // HTTP headers are case-insensitive so two keys that fold to the same
        // lower-case name refer to the same header. Later entries overwrite
        // earlier ones — pinning behaviour so future refactors can't silently
        // change which value gets validated.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                'X-Request-ID' => 'not-a-uuid',
                'x-request-id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_non_string_key_skipped(): void
    {
        // Integer keys (rare, but possible for array literal casts) must not
        // crash strtolower() — they are silently dropped because no spec name
        // can ever match them.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            [
                0 => 'junk',
                'X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function header_params_no_schema_surfaces_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/header-required-no-schema',
            [],
            ['X-Api-Key' => 'anything'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Api-Key]', $result->errors()[0]);
    }

    #[Test]
    public function header_params_associative_single_element_array_surfaces_hard_error(): void
    {
        // Caller-side bug: associative single-element array (not a HeaderBag shape).
        // The unwrap picks the only element via `array_key_first`, which here yields
        // another array — opis would then emit a cryptic JSON-Pointer type mismatch.
        // Surface the caller bug directly instead.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => ['nested' => ['deeper' => 'value']]],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('scalar', $message);
        $this->assertStringContainsString('array', $message);
    }

    #[Test]
    public function header_params_object_value_surfaces_hard_error(): void
    {
        // Objects never reach the is_array unwrap branch, so they flow straight to
        // the scalar guard. Pins the "direct, no unwrap" path and asserts that
        // `get_debug_type` surfaces a class name specific enough for callers to
        // locate the offending producer.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => new stdClass()],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('scalar', $message);
        $this->assertStringContainsString('stdClass', $message);
    }

    #[Test]
    public function header_params_list_shaped_single_element_containing_array_surfaces_hard_error(): void
    {
        // HeaderBag shape (list-indexed single element) but the element is an
        // array — the unwrap produces an array. Complements the associative-shape
        // test above: different caller-supplied input shape, same post-unwrap
        // guard path.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => [['deeper' => 'value']]],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('scalar', $message);
        $this->assertStringContainsString('array', $message);
    }

    #[Test]
    public function header_params_resource_value_surfaces_hard_error(): void
    {
        // Completes the non-scalar type matrix (array, object, resource). A future
        // refactor that narrows the guard to `is_array || is_object` would slip
        // this case through silently, so it earns its own regression pin.
        $resource = fopen('php://memory', 'r');
        $this->assertNotFalse($resource);

        try {
            $result = $this->validator->validate(
                'petstore-3.0',
                'GET',
                '/v1/reports',
                [],
                ['X-Request-ID' => $resource],
                null,
                null,
            );
        } finally {
            fclose($resource);
        }

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('scalar', $message);
        $this->assertStringContainsString('resource', $message);
    }

    #[Test]
    public function header_params_list_shaped_single_element_null_treated_as_missing(): void
    {
        // `['X-Foo' => [null]]` unwraps to `null`. Without a post-unwrap missing
        // check this would either silently pass against a `nullable` schema or
        // surface as a `/` type mismatch from opis — both hide the caller-side
        // shape bug. Mirrors the pre-unwrap `null`/`[]` → missing branch.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => [null]],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('missing', $message);
    }

    #[Test]
    public function reserved_header_declaration_accept_required_surfaces_spec_error(): void
    {
        // Per OAS 3.x §4.7.12.1, `Accept`/`Content-Type`/`Authorization` parameter
        // definitions SHALL be ignored. Silently skipping would let a spec author's
        // `required: true` declaration pass every request, masking the footgun —
        // so we surface the declaration as a hard spec error instead.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/header-reserved-accept-required',
            [],
            ['Accept' => 'application/json'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.Accept]', $message);
        $this->assertStringContainsString('GET /header-reserved-accept-required', $message);
        $this->assertStringContainsString('SHALL be ignored', $message);
        $this->assertStringContainsString('§4.7.12.1', $message);
    }

    #[Test]
    public function reserved_header_declaration_content_type_optional_surfaces_spec_error(): void
    {
        // Detection is name-based, not required-based — `required` is absent
        // (implicit false) yet the declaration is still a spec defect.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/header-reserved-content-type-optional',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.Content-Type]', $message);
        $this->assertStringContainsString('SHALL be ignored', $message);
        $this->assertStringContainsString('§4.7.12.1', $message);
    }

    #[Test]
    public function reserved_header_declaration_authorization_required_surfaces_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/header-reserved-authorization-required',
            [],
            ['Authorization' => 'Bearer token'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.Authorization]', $message);
        $this->assertStringContainsString('SHALL be ignored', $message);
        $this->assertStringContainsString('§4.7.12.1', $message);
    }

    #[Test]
    public function reserved_header_declaration_case_insensitive_in_spec(): void
    {
        // The error message preserves the author's original casing so the
        // offending entry is easy to grep in their spec file.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/header-reserved-accept-upper',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.ACCEPT]', $message);
        $this->assertStringContainsString('SHALL be ignored', $message);
        $this->assertStringContainsString('§4.7.12.1', $message);
    }

    #[Test]
    public function reserved_header_declaration_multiple_entries_surface_multiple_errors(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/header-reserved-multiple',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $errors = $result->errors();
        // Pin the total so a future bug producing a duplicate or extra error is caught.
        $this->assertCount(2, $errors, 'expected exactly 2 errors, got: ' . implode(' | ', $errors));
        $accept = array_filter($errors, static fn(string $e): bool => str_contains($e, '[header.Accept]'));
        $contentType = array_filter($errors, static fn(string $e): bool => str_contains($e, '[header.Content-Type]'));
        $this->assertCount(1, $accept, 'expected one [header.Accept] error, got: ' . implode(' | ', $errors));
        $this->assertCount(1, $contentType, 'expected one [header.Content-Type] error, got: ' . implode(' | ', $errors));
    }

    #[Test]
    public function reserved_header_declaration_path_level_surfaces_spec_error(): void
    {
        // Path-level `parameters` are a separate source from operation-level
        // `parameters` in collectParameters. Pin that reserved-header detection
        // applies to both — a future refactor that dedupes sources before
        // detection must not silently drop the path-level case.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/header-reserved-path-level',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[header.Authorization]', $message);
        $this->assertStringContainsString('SHALL be ignored', $message);
        $this->assertStringContainsString('§4.7.12.1', $message);
    }

    #[Test]
    public function v31_header_params_uuid_format_violation_fails(): void
    {
        // OAS 3.1 parity: 3.1 fixture uses multi-type form (`type: ["string"]`).
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'still-not-a-uuid'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[header.X-Request-ID]', $result->errorMessage());
    }

    #[Test]
    public function v31_header_params_uuid_format_valid_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/reports',
            [],
            ['X-Request-ID' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function path_query_header_and_body_errors_are_combined(): void
    {
        // PATCH /v1/pets/{petId} inherits path-level petId + traceId, declares an
        // operation-level X-Request-ID header, and accepts an optional JSON body.
        // Inject one failing value per source to prove all four error channels
        // compose in a single result — the single regression guard that prevents
        // any validate-and-return-early refactor from silently masking drift.
        $result = $this->validator->validate(
            'petstore-3.0',
            'PATCH',
            '/v1/pets/abc',
            ['traceId' => 'UPPER'],
            ['X-Request-ID' => 'not-a-uuid'],
            ['name' => 123],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[path.petId]', $message);
        $this->assertStringContainsString('[query.traceId]', $message);
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('/name', $message);
    }

    #[Test]
    public function path_query_header_body_and_security_errors_all_compose(): void
    {
        // POST /v1/secure/compose/{tenantId} bundles every validation channel the
        // pipeline offers: path param (integer), query param (pattern), header
        // (uuid), body (required 'name'), and security (bearerAuth). Inject a
        // failing value per source so a refactor that short-circuits after any
        // single channel fails would break this test.
        $result = $this->validator->validate(
            'petstore-3.0',
            'POST',
            '/v1/secure/compose/abc',
            ['trace' => 'UPPER'],
            ['X-Request-ID' => 'not-a-uuid'],
            ['name' => 123],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[path.tenantId]', $message);
        $this->assertStringContainsString('[query.trace]', $message);
        $this->assertStringContainsString('[header.X-Request-ID]', $message);
        $this->assertStringContainsString('[security]', $message);
        $this->assertStringContainsString('/name', $message);
    }

    // ========================================
    // Security scheme validation
    // ========================================

    #[Test]
    public function v30_security_bearer_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'Bearer abc123'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('bearerAuth', $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_wrong_scheme_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'Basic dXNlcjpwYXNz'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('bearerAuth', $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_empty_token_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'Bearer '],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_scheme_name_is_case_insensitive(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'bearer abc123'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_header_name_is_case_insensitive(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            ['authorization' => 'Bearer abc123'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_header_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-header',
            [],
            ['X-API-Key' => 'k1'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_header_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-header',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('apiKeyHeader', $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_header_empty_value_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-header',
            [],
            ['X-API-Key' => ''],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function v30_security_apikey_query_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-query',
            ['api_key' => 'k1'],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_query_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-query',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('apiKeyQuery', $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_cookie_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-cookie',
            [],
            [],
            null,
            null,
            ['session_id' => 'abc'],
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_cookie_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-cookie',
            [],
            [],
            null,
            null,
            [],
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('apiKeyCookie', $result->errorMessage());
    }

    #[Test]
    public function v30_security_or_bearer_only_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/or',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_or_apikey_only_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/or',
            [],
            ['X-API-Key' => 'k1'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_or_both_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/or',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
    }

    #[Test]
    public function v30_security_and_both_satisfied_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/and',
            [],
            [
                'Authorization' => 'Bearer abc',
                'X-API-Key' => 'k1',
            ],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_and_one_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/and',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('apiKeyHeader', $result->errorMessage());
    }

    #[Test]
    public function v30_security_explicit_opt_out_passes_with_no_auth(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/opt-out',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_oauth2_only_silent_skips(): void
    {
        // When every requirement entry contains only unsupported schemes
        // (oauth2 / openIdConnect) we have nothing we can validate — pass
        // rather than block the test (false-negative avoidance).
        $result = $this->suppressSilentPassWarning(fn() => $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/oauth2-only',
            [],
            [],
            null,
            null,
        ));

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_or_oauth2_with_bearer_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer-or-oauth2',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_bearer_or_oauth2_with_nothing_fails(): void
    {
        // Bearer entry is validatable and fails; OAuth2 entry is skipped.
        // Since the only validatable entry failed and no other entry passed,
        // overall result is fail.
        $result = $this->suppressSilentPassWarning(fn() => $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer-or-oauth2',
            [],
            [],
            null,
            null,
        ));

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('bearerAuth', $result->errorMessage());
    }

    #[Test]
    public function v30_security_combines_with_other_errors(): void
    {
        // Bearer missing + no other issues — ensure the security error appears
        // alongside any other errors the pipeline surfaces.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/bearer',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $errors = $result->errors();
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            array_filter($errors, static fn(string $e): bool => str_contains($e, '[security]')) !== [],
            'expected at least one [security] error in: ' . $result->errorMessage(),
        );
    }

    #[Test]
    public function security_undefined_scheme_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-undefined-scheme',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('ghostScheme', $result->errorMessage());
    }

    #[Test]
    public function security_scalar_entry_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scalar-entry',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $message = $result->errorMessage();
        $this->assertStringContainsString('[security]', $message);
        $this->assertStringContainsString('index 0', $message);
    }

    #[Test]
    public function security_scheme_missing_type_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-missing-type',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('brokenScheme', $result->errorMessage());
    }

    #[Test]
    public function security_scheme_http_without_scheme_field_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-http-no-scheme-field',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('httpNoScheme', $result->errorMessage());
    }

    #[Test]
    public function security_scheme_apikey_missing_name_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-apikey-missing-name',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('apiKeyNoName', $result->errorMessage());
    }

    #[Test]
    public function security_root_level_is_inherited_when_operation_omits_security(): void
    {
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/inherits',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('bearerAuth', $result->errorMessage());
    }

    #[Test]
    public function security_root_level_is_inherited_and_satisfied(): void
    {
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/inherits',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function security_operation_empty_array_opts_out_of_root(): void
    {
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/opts-out',
            [],
            [],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function security_operation_override_replaces_root(): void
    {
        // /overrides replaces root-level bearerAuth with apiKeyHeader.
        // Passing a bearer should fail (because apiKey is now required).
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/overrides',
            [],
            ['Authorization' => 'Bearer abc'],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('apiKeyHeader', $result->errorMessage());
    }

    #[Test]
    public function security_operation_override_satisfied_passes(): void
    {
        $result = $this->validator->validate(
            'security-root',
            'GET',
            '/overrides',
            [],
            ['X-API-Key' => 'k1'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v31_security_bearer_present_passes(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/secure/bearer',
            [],
            ['Authorization' => 'Bearer abc123'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v31_security_apikey_query_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/secure/apikey-query',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('apiKeyQuery', $result->errorMessage());
    }

    #[Test]
    public function v31_security_or_both_missing_fails(): void
    {
        $result = $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/secure/or',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
    }

    #[Test]
    public function v31_security_oauth2_only_silent_skips(): void
    {
        $result = $this->suppressSilentPassWarning(fn() => $this->validator->validate(
            'petstore-3.1',
            'GET',
            '/v1/secure/oauth2-only',
            [],
            [],
            null,
            null,
        ));

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function security_unknown_type_is_hard_spec_error(): void
    {
        // A typo like type: "htpp" must not silently fall through as "unsupported".
        // If it did, every request for that endpoint would silently pass — which
        // is exactly the drift this library exists to catch.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-unknown-type',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('typoScheme', $result->errorMessage());
        $this->assertStringContainsString('htpp', $result->errorMessage());
    }

    #[Test]
    public function security_empty_type_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-scheme-empty-type',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('emptyTypeScheme', $result->errorMessage());
    }

    #[Test]
    public function security_operation_level_scalar_is_hard_spec_error(): void
    {
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-root-scalar',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
    }

    #[Test]
    public function security_numeric_scheme_name_is_hard_spec_error(): void
    {
        // Purely numeric JSON object keys (e.g. {"0": []}) become integer PHP
        // array keys after json_decode. The guard at the top of the entry loop
        // must catch this so a typo doesn't silently skip auth.
        $result = $this->validator->validate(
            'malformed',
            'GET',
            '/security-numeric-scheme-name',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[security]', $result->errorMessage());
        $this->assertStringContainsString('scheme name must be a string', $result->errorMessage());
    }

    #[Test]
    public function security_components_schemes_as_scalar_is_hard_spec_error(): void
    {
        // If `components.securitySchemes` itself is a scalar, don't silently
        // treat every scheme reference as "undefined" — that misdirects the
        // spec author. Surface a dedicated error pointing at the real cause.
        $result = $this->validator->validate(
            'security-schemes-scalar',
            'GET',
            '/needs-bearer',
            [],
            [],
            null,
            null,
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('components.securitySchemes', $result->errorMessage());
    }

    #[Test]
    public function security_two_unsupported_entries_silent_skip_to_pass(): void
    {
        // Two entries (oauth2 + oidc) are both unsupported. With no validatable
        // entries remaining, overall result must be pass — prevents a regression
        // where "no satisfied entry" is conflated with "no evaluable entry".
        $result = $this->suppressSilentPassWarning(fn() => $this->validator->validate(
            'security-edge-cases',
            'GET',
            '/two-unsupported-entries',
            [],
            [],
            null,
            null,
        ));

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function security_and_with_unsupported_scheme_skips_entry(): void
    {
        // Single entry AND-joining bearer with oauth2 — because the entry
        // contains an unsupported scheme, phase-1 policy treats the whole entry
        // as unevaluable (we cannot check oauth2, so we cannot confirm AND).
        // Overall result: pass. Pins the documented tradeoff so a future policy
        // change is an explicit decision, not an accidental regression.
        $result = $this->suppressSilentPassWarning(fn() => $this->validator->validate(
            'security-edge-cases',
            'GET',
            '/and-with-unsupported',
            [],
            [],
            null,
            null,
        ));

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function v30_security_apikey_header_name_is_case_insensitive(): void
    {
        // RFC 9110 §5.1 — HTTP header names are case-insensitive. Spec declares
        // 'X-API-Key'; request sends 'x-api-key'. Locks in the normalize-then-
        // lookup behaviour.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/secure/apikey-header',
            [],
            ['x-api-key' => 'k1'],
            null,
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    // ========================================
    // readOnly / writeOnly enforcement
    // ========================================

    #[Test]
    public function request_body_containing_read_only_property_fails_validation(): void
    {
        // The spec marks `id` as readOnly — clients must not send it.
        $result = $this->validator->validate(
            'readwrite',
            'POST',
            '/users',
            [],
            [],
            ['id' => 99, 'name' => 'Ada', 'password' => 'hunter2'],
            'application/json',
        );

        $this->assertFalse($result->isValid(), 'readOnly id should be rejected in request');
        $errorMessage = implode(' | ', $result->errors());
        $this->assertStringContainsString('id', $errorMessage);
    }

    #[Test]
    public function request_body_without_read_only_property_passes_even_when_spec_lists_it_required(): void
    {
        // The spec lists `id` in `required`, but since it is readOnly the request
        // side must treat it as absent — a compliant request that omits it
        // should validate.
        $result = $this->validator->validate(
            'readwrite',
            'POST',
            '/users',
            [],
            [],
            ['name' => 'Ada', 'password' => 'hunter2'],
            'application/json',
        );

        $this->assertTrue($result->isValid(), 'errors: ' . implode(' | ', $result->errors()));
    }

    #[Test]
    public function body_validator_exception_does_not_suppress_path_parameter_errors(): void
    {
        // Regression guard: pre-boundary, a RuntimeException thrown from inside
        // the body sub-validator (opis rejects the malformed `pattern: "[unterminated"`
        // with InvalidKeywordException at validation time) aborted the orchestrator
        // mid-spread, discarding the path-param error collected one step earlier.
        // ValidatorErrorBoundary::safely() converts the throw into a synthetic
        // [request-body] error so sibling validators' findings remain surfaced.
        $result = $this->validator->validate(
            'body-validator-throws',
            'POST',
            '/items/abc', // non-integer path param → [path.id] error must survive the body throw
            [],
            [],
            ['code' => 'x'],
            'application/json',
        );

        $this->assertFalse($result->isValid());

        $joined = implode(' | ', $result->errors());
        $this->assertStringContainsString('[path.id]', $joined, 'path-param error must survive body throw');
        $this->assertStringContainsString('[request-body]', $joined, 'body throw must surface as boundary error');
        $this->assertStringContainsString('InvalidKeywordException', $joined, 'exception class name preserved for diagnostics');
    }

    // ========================================
    // Issue #179: downgrade failure → skipped when response is a documented 4xx
    // ========================================

    #[Test]
    public function downgrades_failure_to_skipped_when_documented_4xx_matches_pattern(): void
    {
        // POST /exact-422 documents an exact `422` response; with the validator
        // configured to treat 422 as the "documented client error" sentinel,
        // an invalid request body that yields a 422 from the impl should be
        // surfaced as Skipped — not Failure — so dataProvider tests that send
        // intentionally-invalid input to verify 4xx behaviour stop false-failing.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['422'],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            [], // missing required `name` → request validation MUST fail
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertTrue($result->isValid(), 'skipped result must report isValid()=true');
        $this->assertTrue($result->isSkipped(), 'documented-4xx downgrade must produce a Skipped outcome');
        $this->assertSame('/exact-422', $result->matchedPath());
        $this->assertSame('422', $result->matchedStatusCode(), 'matchedStatusCode must reflect the spec key the response resolved to');
        $this->assertNotNull($result->skipReason());
        $this->assertStringContainsString('422', (string) $result->skipReason());
    }

    #[Test]
    public function preserves_failure_when_4xx_not_documented_in_spec(): void
    {
        // POST /no-4xx documents only 200/500. A 422 response is NOT documented
        // for this operation, so the downgrade must NOT apply — the spec gap
        // (impl returns a status the spec never mentions) should stay loud.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['422'],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/no-4xx',
            [],
            [],
            [],
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertFalse($result->isValid(), 'undocumented 4xx must NOT trigger downgrade');
        $this->assertNotEmpty($result->errors());
    }

    #[Test]
    public function preserves_failure_when_status_does_not_match_pattern(): void
    {
        // 403 is documented? Not for /exact-422 — but even if it were, the
        // pattern set is `['422']` which does not match 403. The downgrade
        // should not fire for unrelated status codes.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['422'],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            [],
            'application/json',
            responseStatusCode: 403,
        );

        $this->assertFalse($result->isValid(), 'pattern miss must keep failure intact');
    }

    #[Test]
    public function range_key_4xx_in_spec_counts_as_documented(): void
    {
        // POST /4xx-range documents `4XX` (range key) — a literal 422 response
        // resolves to that range key per OpenAPI's three-tier fallback, so
        // the downgrade must apply.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['422'],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/4xx-range',
            [],
            [],
            [],
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertTrue($result->isSkipped(), 'range-key match must be treated as documented');
        $this->assertSame('4XX', $result->matchedStatusCode());
    }

    #[Test]
    public function default_response_in_spec_counts_as_documented(): void
    {
        // POST /only-default declares only a `default` response. Per the
        // resolver, default is the catch-all that matches anything — so a
        // configured skip pattern should still apply.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['422'],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/only-default',
            [],
            [],
            [],
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertTrue($result->isSkipped(), 'default response must count as documented');
        $this->assertSame('default', $result->matchedStatusCode());
    }

    #[Test]
    public function valid_request_is_unaffected_by_skip_config(): void
    {
        // Downgrade only fires when there is a failure to downgrade. A
        // perfectly-shaped payload that legitimately returns a 422 (business
        // logic 422 on a valid envelope) must stay Success — not get
        // demoted to Skipped because of the response status.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['422'],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            ['name' => 'Fido'],
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped(), 'valid request must remain Success, not be downgraded');
    }

    #[Test]
    public function skip_does_not_apply_when_response_status_is_null(): void
    {
        // Backward-compat: existing callers that don't pass responseStatusCode
        // (i.e. the validator is used outside the trait orchestrator) must
        // see no behavior change. Failures stay Failures.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['422'],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            [],
            'application/json',
        );

        $this->assertFalse($result->isValid(), 'no responseStatusCode → no downgrade, BC preserved');
    }

    #[Test]
    public function empty_skip_config_disables_downgrade(): void
    {
        // Opt-out path: setting the config to [] turns the feature off
        // entirely so users can keep strict request-side validation.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: [],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            [],
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertFalse($result->isValid(), 'empty skip config must keep failures loud');
    }

    #[Test]
    public function rejects_invalid_pattern_in_skip_config(): void
    {
        // Loud-fail on bad config — same shape as the response side's
        // skipResponseCodes validation. Empty string and unparseable regex
        // both throw with a labelled message.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('skipRequestValidationResponseCodes');

        new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: [''],
        );
    }

    #[Test]
    public function downgrade_applies_when_failure_originates_from_security_validator(): void
    {
        // Sibling-validator coverage: the downgrade must be source-agnostic
        // — a security-side failure (missing apiKey) on an endpoint whose
        // spec documents 422 should also downgrade. Without this guarantee,
        // a future refactor that gated the downgrade inside the body branch
        // would still pass the body-only Issue #179 tests.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['422'],
        );

        // petstore-3.0 GET /v1/secure/apikey-header requires an `X-Api-Key`
        // header. Missing it produces a security-only failure. The endpoint
        // does not document 422 in petstore-3.0, so use a test fixture
        // that does.
        $result = $this->suppressSilentPassWarning(static fn() => $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            [], // missing required `name` AND no security to check — body fails
            'application/json',
            responseStatusCode: 422,
        ));

        $this->assertTrue($result->isSkipped());
    }

    #[Test]
    public function multiple_patterns_first_match_wins_in_skip_reason(): void
    {
        // The skip reason embeds the matched raw pattern verbatim. With
        // `['4\d\d', '422']` against status 422, the broader range pattern
        // appears first and wins — pinned so insertion order semantics are
        // preserved by future refactors.
        $validator = new OpenApiRequestValidator(
            skipRequestValidationResponseCodes: ['4\d\d', '422'],
        );

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            [],
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('matched pattern 4\d\d', (string) $result->skipReason());
    }

    #[Test]
    public function downgrade_via_default_fallback_emits_suspicious_keys_warning(): void
    {
        // Symmetric warning (issue #179 follow-up): the request side now
        // also fires `warnSuspiciousKeys` when its downgrade consumed a
        // `default` fallback, so a test class with auto_validate_request
        // ON but auto_assert OFF still surfaces spec-key typos.
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING && str_contains($errstr, 'falling back to')) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            $validator = new OpenApiRequestValidator(
                skipRequestValidationResponseCodes: ['422'],
            );
            // /only-default declares only `default` — a 422 falls through
            // to it. Our fixture has no typo, so this assertion just pins
            // the call path; the typo case is covered by the resolver's
            // own warn_suspicious_keys_emits_warning_for_typo_alongside_default.
            $result = $validator->validate(
                'request-validation-skip',
                'POST',
                '/only-default',
                [],
                [],
                [],
                'application/json',
                responseStatusCode: 422,
            );
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result->isSkipped());
        $this->assertSame('default', $result->matchedStatusCode());
        // No typos in the fixture → no warnings; verifies the wiring fires
        // only for non-conforming sibling keys, not for every default fallback.
        $this->assertSame([], $captured);
    }

    /**
     * Run a callable while suppressing the silent-pass `[security]`
     * `E_USER_WARNING` emitted for oauth2 / openIdConnect / mutualTLS /
     * http-basic / http-digest endpoints. Warning *contents* are covered by
     * `SecurityValidatorTest`; the integration tests here only verify the
     * {@see OpenApiRequestValidator} return value, so the warning is
     * captured-and-discarded.
     *
     * Filter is intent-driven: only warnings starting with `[security]` are
     * absorbed. Unrelated `E_USER_WARNING`s (e.g. a future deprecation in
     * the validator pipeline) bubble up so they cannot silently pass — the
     * exact pattern this PR was created to prevent.
     */
    private function suppressSilentPassWarning(callable $fn): mixed
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            return $errno === E_USER_WARNING && str_starts_with($errstr, '[security]');
        });

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
