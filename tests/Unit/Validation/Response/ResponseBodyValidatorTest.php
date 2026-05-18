<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Response;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\DecodedBody;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidationResult;
use Studio\OpenApiContractTesting\Validation\Response\ResponseBodyValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

class ResponseBodyValidatorTest extends TestCase
{
    private ResponseBodyValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ResponseBodyValidator(new SchemaValidatorRunner(20));
    }

    #[Test]
    public function validate_passes_valid_json_body_against_schema(): void
    {
        $content = [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets/{id}',
            200,
            $content,
            DecodedBody::present(['id' => 1]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_flags_empty_body_against_json_schema(): void
    {
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::absent(),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Response body is empty', $result->errors[0]);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_type_checks_present_literal_null_body_against_object_schema(): void
    {
        // Issue #246: a response body of the literal JSON `null` (the four
        // bytes `null` on the wire) is type-checked against the schema, not
        // short-circuited as an absent body. Against `type: object` it is a
        // contract violation and must surface a schema type error — NOT the
        // "Response body is empty" message reserved for a genuinely absent
        // body. A present DecodedBody carrying `null` is how an adapter
        // signals "the wire carried a body and its decoded value is null".
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present(null),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('must match the type', $result->errors[0]);
        $this->assertStringNotContainsString('Response body is empty', $result->errors[0]);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_accepts_present_literal_null_body_against_oas_31_nullable_schema(): void
    {
        // OAS 3.1 `type: ["object", "null"]` explicitly permits a null body.
        // A present literal `null` validates cleanly against it — the pre-#246
        // "body is empty" short-circuit would have wrongly rejected it.
        $content = [
            'application/json' => ['schema' => ['type' => ['object', 'null']]],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present(null),
            'application/json',
            OpenApiVersion::V3_1,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_accepts_present_literal_null_body_against_oas_30_nullable_schema(): void
    {
        // OAS 3.0 expresses a nullable body with `nullable: true`;
        // OpenApiSchemaConverter lowers it to a `["object", "null"]` type
        // array for Draft 07. A present literal `null` must validate cleanly
        // against it — distinct conversion branch from the OAS 3.1 type-array
        // form covered above.
        $content = [
            'application/json' => ['schema' => ['type' => 'object', 'nullable' => true]],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present(null),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_skips_non_json_content_type_when_spec_entry_has_a_schema(): void
    {
        // Issue #254: the response Content-Type matched the spec's `text/plain`
        // media-type key, and that key declares a `schema`. OpenAPI permits a
        // schema on any media type, but this engine only evaluates JSON Schema
        // — the body cannot be checked. The validator must surface a skip
        // (empty errors + non-null skipReason) so the unvalidated body is not
        // recorded as a clean pass. matchedContentType is still the matched
        // key so coverage records the skip against that exact media-type row.
        $content = [
            'text/plain' => ['schema' => ['type' => 'string']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/robots.txt',
            200,
            $content,
            DecodedBody::present('User-agent: *'),
            'text/plain; charset=utf-8',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('text/plain', $result->matchedContentType);
        $this->assertNotNull($result->skipReason);
        $this->assertStringContainsString('text/plain', $result->skipReason);
        $this->assertStringContainsString('JSON Schema engine only', $result->skipReason);
    }

    #[Test]
    public function validate_does_not_skip_non_json_content_type_without_a_schema(): void
    {
        // A non-JSON media type with NO `schema` has nothing to validate — it
        // stays silently successful (no errors, no skipReason) so it is not
        // noisily surfaced in coverage as an unvalidated endpoint.
        $content = [
            'text/plain' => [],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/robots.txt',
            200,
            $content,
            DecodedBody::present('User-agent: *'),
            'text/plain; charset=utf-8',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('text/plain', $result->matchedContentType);
        $this->assertNull($result->skipReason);
    }

    #[Test]
    public function validate_skips_non_json_content_type_matched_via_wildcard_range_with_a_schema(): void
    {
        // Issue #254 skip detection keys off `findContentTypeKey()`, which
        // also matches `<type>/*` ranges. A non-JSON Content-Type that
        // matches a wildcard spec key declaring a `schema` must skip too —
        // not just exact-key matches.
        $content = [
            'application/*' => ['schema' => ['type' => 'string']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/blob',
            200,
            $content,
            DecodedBody::present('binary-ish blob'),
            'application/octet-stream',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('application/*', $result->matchedContentType);
        $this->assertNotNull($result->skipReason);
    }

    #[Test]
    public function result_rejects_a_skip_reason_alongside_errors(): void
    {
        // A skip means the body was deliberately not checked — mutually
        // exclusive with reporting errors. The DTO guard makes the
        // contradictory state unconstructable.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot also carry errors');

        new ResponseBodyValidationResult(['some error'], 'text/plain', 'a skip reason');
    }

    #[Test]
    public function result_rejects_a_skip_reason_without_a_matched_content_type(): void
    {
        // A skip is only reached after a media-type key matched, so a skip
        // must always name that key — otherwise coverage would record the
        // skip against the wildcard bucket instead of the real row.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name the matched');

        new ResponseBodyValidationResult([], null, 'a skip reason');
    }

    #[Test]
    public function validate_preserves_spec_content_type_casing(): void
    {
        // The spec author wrote a mixed-case media type — the matched key
        // should keep that casing so coverage reports show it verbatim.
        $content = [
            'Application/Problem+JSON' => [
                'schema' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            422,
            $content,
            DecodedBody::present(['detail' => 'oops']),
            'application/problem+json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('Application/Problem+JSON', $result->matchedContentType);
    }

    #[Test]
    public function validate_flags_non_json_content_type_not_in_spec(): void
    {
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present('blob'),
            'application/xml',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString("Content-Type 'application/xml' is not defined", $result->errors[0]);
        $this->assertNull($result->matchedContentType);
    }

    #[Test]
    public function validate_returns_empty_when_no_json_content_defined(): void
    {
        // Non-JSON spec entries with no Content-Type header → out-of-scope, pass.
        $content = ['application/xml' => ['schema' => ['type' => 'string']]];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present('<pets/>'),
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertNull($result->matchedContentType);
    }

    #[Test]
    public function validate_accepts_empty_object_body_against_type_object(): void
    {
        // PHP's `json_decode('{}', true) === []` — the Laravel trait's
        // associative-array decoding loses the {} vs [] distinction. Without
        // schema-aware coercion the validator would reject `[]` against
        // `type: object`. Pin the fix so empty-{} responses validate.
        $content = [
            'application/json' => [
                'schema' => ['type' => 'object'],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $content,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_accepts_empty_object_body_against_oas_31_nullable_object(): void
    {
        // OAS 3.1 type-array form: `type: ["object", "null"]`. Same coercion.
        $content = [
            'application/json' => [
                'schema' => ['type' => ['object', 'null']],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $content,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_1,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_does_not_coerce_empty_array_when_schema_has_no_explicit_type(): void
    {
        // A schema that only declares `properties` (no `type`) is common in
        // third-party specs. `schemaAcceptsObject` returns false for this
        // shape — pin so a future "infer object from properties" change
        // doesn't silently start coercing array bodies.
        $content = [
            'application/json' => [
                'schema' => [
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $content,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        // No error AND no coercion fired — the property bag is permissive
        // so empty input passes for either array or object interpretation.
        // What we're pinning here: the implementation returned and we did
        // not promote `[]` to `(object) []`. Verify by also recording the
        // matched content type — the success path always sets it.
        $this->assertSame([], $result->errors);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_does_not_coerce_empty_array_for_oneof_with_object_branch(): void
    {
        // `oneOf: [{type: object, required: [foo]}]` with body `[]`. Composition
        // keywords are NOT walked by `schemaAcceptsObject` — by design — so the
        // body is validated as a JSON array against the oneOf and fails. Pin
        // the design choice so a future "let's walk oneOf" change is forced
        // through review.
        $content = [
            'application/json' => [
                'schema' => [
                    'oneOf' => [
                        ['type' => 'object', 'required' => ['foo'], 'properties' => ['foo' => ['type' => 'string']]],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $content,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        // Coercion did NOT fire — body remained a JSON array, oneOf failed.
        // (Tracker / orchestrator surface a non-empty errors list.)
        $this->assertNotEmpty($result->errors);
    }

    #[Test]
    public function validate_does_not_coerce_empty_array_when_schema_is_array_type(): void
    {
        // Coercion must NOT fire when the schema actually wants an array —
        // an empty array is a legitimate value for `type: array` (with no
        // minItems constraint).
        $content = [
            'application/json' => [
                'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $content,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_flags_schema_mismatch(): void
    {
        $content = [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets/{id}',
            200,
            $content,
            DecodedBody::present(['id' => 'not-an-int']),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('/id', $result->errors[0]);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    // ========================================
    // Malformed-spec guards (issue #256) — symmetric with RequestBodyValidator
    // ========================================

    #[Test]
    public function validate_flags_malformed_media_type_entry(): void
    {
        // `content: {"application/json": "oops"}` — a scalar where a media
        // type object was expected. Without the guard the scalar slips past
        // the downstream `isset(...['schema'])` presence check and the body
        // is silently recorded as a clean pass. Surface a loud spec error,
        // mirroring RequestBodyValidator's sibling guard.
        $content = ['application/json' => 'oops'];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present(['id' => 1]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"]\'',
            $result->errors[0],
        );
        $this->assertStringContainsString('expected object, got scalar', $result->errors[0]);
        $this->assertNull($result->matchedContentType);
    }

    #[Test]
    public function validate_flags_malformed_media_type_schema_for_json_content_type(): void
    {
        // A non-array `schema` on a JSON media type would reach
        // OpenApiSchemaConverter::convert() as a scalar and raise a confusing
        // TypeError. The guard turns it into a spec-level error instead.
        $content = ['application/json' => ['schema' => 'oops']];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present(['id' => 1]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"].schema\'',
            $result->errors[0],
        );
        $this->assertStringContainsString('expected object, got scalar', $result->errors[0]);
        $this->assertNull($result->matchedContentType);
    }

    #[Test]
    public function validate_flags_null_media_type_schema_for_json_content_type(): void
    {
        // Locks in `array_key_exists` over `isset`: an explicit `schema: null`
        // must be flagged. With `isset` it would fall through the downstream
        // presence check and accept any body — a silent pass.
        $content = ['application/json' => ['schema' => null]];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present(['id' => 1]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["application/json"].schema\'',
            $result->errors[0],
        );
    }

    #[Test]
    public function validate_flags_null_media_type_schema_for_non_json_content_type(): void
    {
        // Before the guard, a non-JSON entry with `schema: null` slipped
        // through the `isset(...['schema'])` skip check (issue #254) as a
        // silent success — asymmetric with the request validator, which
        // rejects the same `schema: null` loudly. The guard runs before
        // content negotiation, so request and response now agree.
        $content = ['text/plain' => ['schema' => null]];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present('blob'),
            'text/plain',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["text/plain"].schema\'',
            $result->errors[0],
        );
        $this->assertNull($result->skipReason);
    }

    #[Test]
    public function validate_flags_malformed_media_type_schema_for_non_json_content_type(): void
    {
        // A non-null scalar `schema` (the natural shape of an unresolved $ref
        // that decoded to a string) on a non-JSON media type. Like the
        // `schema: null` case, the guard runs before content negotiation, so
        // it is flagged regardless of the actual response Content-Type.
        $content = ['text/plain' => ['schema' => 'oops']];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present('blob'),
            'text/plain',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["text/plain"].schema\'',
            $result->errors[0],
        );
        $this->assertNull($result->skipReason);
    }

    #[Test]
    public function validate_flags_malformed_entry_even_when_not_the_negotiated_content_type(): void
    {
        // The guard loop pre-scans every media-type entry before content
        // negotiation runs. A malformed `text/plain` entry must be flagged
        // even though the JSON response Content-Type would negotiate the
        // well-formed `application/json` entry — a malformed spec is surfaced
        // regardless of which entry the request would have matched.
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
            'text/plain' => 'oops',
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            DecodedBody::present(['id' => 1]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString(
            'Malformed \'responses[200].content["text/plain"]\'',
            $result->errors[0],
        );
    }
}
