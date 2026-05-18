<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Request;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\DecodedBody;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\Validation\Request\RequestBodyValidationResult;
use Studio\OpenApiContractTesting\Validation\Request\RequestBodyValidator;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

class RequestBodyValidatorTest extends TestCase
{
    private RequestBodyValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RequestBodyValidator(new SchemaValidatorRunner(20));
    }

    #[Test]
    public function validate_returns_empty_when_operation_defines_no_body(): void
    {
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            [],
            DecodedBody::absent(),
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_flags_missing_required_body(): void
    {
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::absent(),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Request body is empty', $result->errors[0]);
    }

    #[Test]
    public function validate_flags_present_literal_null_body_against_object_schema_when_optional(): void
    {
        // Issue #246 — the core silent-pass bug. A request body of the literal
        // JSON `null` against an OPTIONAL `type: object` body must NOT pass:
        // before the fix the validator read the decoded `null` as "no body"
        // and, because the body was optional, returned no errors — letting a
        // malformed `null` body slip through unchecked. A present DecodedBody
        // carrying `null` is now type-checked against the schema and fails loudly.
        $operation = [
            'requestBody' => [
                'required' => false,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::present(null),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('must match the type', $result->errors[0]);
    }

    #[Test]
    public function validate_flags_present_literal_null_body_against_object_schema_when_required(): void
    {
        // A present literal `null` against a REQUIRED object body fails with a
        // schema type error, not the "Request body is empty" message — the
        // body WAS present on the wire, it is simply the wrong type.
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::present(null),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('must match the type', $result->errors[0]);
        $this->assertStringNotContainsString('Request body is empty', $result->errors[0]);
    }

    #[Test]
    public function validate_accepts_present_literal_null_body_against_oas_31_nullable_schema(): void
    {
        // OAS 3.1 `type: ["object", "null"]` explicitly permits a null body.
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => ['type' => ['object', 'null']]],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::present(null),
            'application/json',
            OpenApiVersion::V3_1,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_accepts_present_literal_null_body_against_oas_30_nullable_schema(): void
    {
        // OAS 3.0 expresses a nullable body with `nullable: true`;
        // OpenApiSchemaConverter lowers it to a `["object", "null"]` type
        // array for Draft 07. A present literal `null` validates cleanly
        // against it — distinct conversion branch from the OAS 3.1 type-array
        // form covered above.
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object', 'nullable' => true]],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::present(null),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_still_treats_absent_body_as_no_body(): void
    {
        // Regression guard for issue #246: an absent body (raw content was
        // empty) keeps the historical "no body" semantics — it is NOT
        // type-checked. An optional absent body still passes; only a present
        // DecodedBody carrying `null` is type-checked against the schema.
        $operation = [
            'requestBody' => [
                'required' => false,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::absent(),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_flags_unknown_non_json_content_type(): void
    {
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::absent(),
            'application/xml',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString("Content-Type 'application/xml' is not defined", $result->errors[0]);
    }

    #[Test]
    public function validate_flags_malformed_non_array_request_body(): void
    {
        $operation = ['requestBody' => 'oops'];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::absent(),
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString("Malformed 'requestBody'", $result->errors[0]);
    }

    #[Test]
    public function validate_flags_malformed_media_type_schema(): void
    {
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => ['schema' => 'not-an-array'],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::absent(),
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('.schema\'', $result->errors[0]);
        $this->assertStringContainsString('expected object, got string', $result->errors[0]);
    }

    #[Test]
    public function validate_flags_list_request_body(): void
    {
        // A `requestBody` written as a JSON list passes `is_array()` but is
        // not an object. The shared MalformedSpecNode guard surfaces it with
        // the same loud diagnostic as a scalar `requestBody` (issue #256).
        $operation = ['requestBody' => ['this should have been an object']];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::absent(),
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString("Malformed 'requestBody'", $result->errors[0]);
        $this->assertStringContainsString('expected object, got list', $result->errors[0]);
    }

    #[Test]
    public function validate_flags_list_media_type_schema(): void
    {
        // A `schema` written as a JSON list is malformed the same way — a
        // list is not a JSON Schema object (issue #256).
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => ['schema' => ['this should have been an object']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::absent(),
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('.schema\'', $result->errors[0]);
        $this->assertStringContainsString('expected object, got list', $result->errors[0]);
    }

    #[Test]
    public function validate_validates_json_body_against_schema(): void
    {
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => ['name' => ['type' => 'string']],
                            'required' => ['name'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::present(['name' => 'Fido']),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_accepts_empty_object_body_against_type_object(): void
    {
        // PHP's `json_decode('{}', true) === []` — the Laravel adapter's
        // associative-array decoding loses the {} vs [] distinction. Without
        // schema-aware coercion the validator would reject `[]` against
        // `type: object`. Pin the fix so empty-{} request bodies validate,
        // matching the response-side coercion (issue #217).
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_accepts_empty_object_body_against_oas_31_nullable_object(): void
    {
        // Coercion fires on the OAS 3.1 type-array form too: `type: ["object", "null"]`.
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => ['type' => ['object', 'null']]],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
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
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => ['id' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        // No error AND no coercion fired — the property bag is permissive
        // so empty input passes for either array or object interpretation.
        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_does_not_coerce_empty_array_for_oneof_with_object_branch(): void
    {
        // `oneOf: [{type: object, required: [foo]}]` with body `[]`. Composition
        // keywords are NOT walked by `schemaAcceptsObject` — by design — so the
        // body is validated as a JSON array against the oneOf and fails. Pin
        // the design choice so a future "let's walk oneOf" change is forced
        // through review.
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'oneOf' => [
                                ['type' => 'object', 'required' => ['foo'], 'properties' => ['foo' => ['type' => 'string']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        // Coercion did NOT fire — body remained a JSON array, oneOf reported
        // an array-vs-object type mismatch. Assert on message shape, not just
        // non-empty errors: if a future change made the gate walk oneOf and
        // coerce, the body would be stdClass and the failure would shift to
        // a `required` (missing foo) error instead of a type-mismatch error.
        // The substring "must match the type" only appears in the pre-coercion
        // world; the post-coercion world would say "required properties".
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('must match the type', $result->errors[0]);
    }

    #[Test]
    public function validate_does_not_coerce_empty_array_when_schema_is_array_type(): void
    {
        // Coercion must NOT fire when the schema actually wants an array —
        // an empty array is a legitimate value for `type: array` (with no
        // minItems constraint).
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_still_flags_missing_required_property_after_empty_object_coercion(): void
    {
        // Pin: the `[] -> stdClass` coercion must NOT mask missing-required-
        // property errors. An empty `{}` body against `{type: object,
        // required: [foo]}` is still a contract violation; the coercion only
        // fixes the {} vs [] shape ambiguity, it does not satisfy `required`.
        // Without this pin, a future refactor that moved the coercion past
        // the schema check or fed opis a permissive schema could silently
        // accept an empty body that omits required fields — exactly the
        // silent-pass class this library exists to surface.
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => ['foo' => ['type' => 'string']],
                            'required' => ['foo'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('required properties (foo)', $result->errors[0]);
    }

    #[Test]
    public function validate_accepts_empty_object_body_when_request_body_is_optional(): void
    {
        // Request-side-specific invariant: the coercion fires regardless of
        // `required: true|false` because an empty `{}` body arrives as PHP
        // `[]`, not as an absent body — only an absent body short-circuits the
        // `required` branch. A future refactor that moved the optional-body
        // fast-path to also match `[]` would silently skip the coercion gate;
        // this test pins the current behaviour.
        $operation = [
            'requestBody' => [
                'required' => false,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            DecodedBody::present([]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_skips_non_json_content_type_when_spec_entry_has_a_schema(): void
    {
        // Issue #254: the request Content-Type is a non-JSON media type that
        // matches a spec media-type key, and that key declares a `schema`.
        // OpenAPI permits a schema on any media type, but this engine only
        // evaluates JSON Schema — the body cannot be checked. The validator
        // must surface a skip (empty errors + non-null skipReason) so the
        // unvalidated body is not recorded as a clean pass.
        $operation = [
            'requestBody' => [
                'content' => [
                    'text/plain' => ['schema' => ['type' => 'string']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::present('raw pet body'),
            'text/plain; charset=utf-8',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertNotNull($result->skipReason);
        $this->assertStringContainsString('text/plain', $result->skipReason);
        $this->assertStringContainsString('JSON Schema engine only', $result->skipReason);
    }

    #[Test]
    public function validate_does_not_skip_non_json_content_type_without_a_schema(): void
    {
        // A non-JSON media type with NO `schema` has nothing to validate —
        // it stays silently successful (no errors, no skipReason), so it is
        // not noisily surfaced in coverage as an unvalidated endpoint.
        $operation = [
            'requestBody' => [
                'content' => [
                    'text/plain' => [],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::present('raw pet body'),
            'text/plain',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertNull($result->skipReason);
    }

    #[Test]
    public function validate_skips_non_json_content_type_matched_via_wildcard_range_with_a_schema(): void
    {
        // Issue #254 skip detection keys off `findContentTypeKey()`, which
        // also matches `<type>/*` ranges. A non-JSON Content-Type that
        // matches a wildcard spec key declaring a `schema` must skip too —
        // not just exact-key matches.
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/*' => ['schema' => ['type' => 'string']],
                ],
            ],
        ];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/blob',
            $operation,
            DecodedBody::present('binary-ish blob'),
            'application/octet-stream',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertNotNull($result->skipReason);
        $this->assertStringContainsString('application/*', $result->skipReason);
    }

    #[Test]
    public function result_rejects_a_skip_reason_alongside_errors(): void
    {
        // A skip means the body was deliberately not checked — that is
        // mutually exclusive with reporting errors. The DTO guard makes the
        // contradictory state unconstructable so a future producer bug fails
        // loudly instead of silently miscounting an errored body as a skip.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot also carry errors');

        new RequestBodyValidationResult(['some error'], 'a skip reason');
    }
}
