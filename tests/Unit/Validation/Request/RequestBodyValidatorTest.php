<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Request;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\UploadedPart;
use Studio\Gesso\Validation\Request\RequestBodyValidationResult;
use Studio\Gesso\Validation\Request\RequestBodyValidator;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

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
    public function validate_flags_missing_required_body_when_content_is_absent(): void
    {
        $operation = [
            'requestBody' => [
                'required' => true,
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
        $this->assertStringContainsString('Request body is empty', $result->errors[0]);
    }

    #[Test]
    public function validate_flags_missing_required_body_when_media_type_has_no_schema(): void
    {
        $operation = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [],
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
    public function validate_carries_structured_violations_aligned_with_errors(): void
    {
        // Issue #282 stage 2: schema errors keep their structured twin so the
        // orchestrator can attach instancePath/keyword to request.body issues.
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => ['name' => ['type' => 'string']],
                            'required' => ['name', 'age'],
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
            DecodedBody::present(['name' => 42]),
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(2, $result->errors);
        $this->assertCount(2, $result->violations);
        foreach ($result->violations as $index => $violation) {
            $this->assertSame(
                "[{$violation->displayPath()}] {$violation->message}",
                $result->errors[$index],
            );
            $this->assertNotNull($violation->keyword);
        }
    }

    #[Test]
    public function validate_reports_no_violations_for_non_schema_errors(): void
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

        $this->assertNotSame([], $result->errors);
        $this->assertSame([], $result->violations);
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

    #[Test]
    public function validate_checks_a_form_urlencoded_body_against_its_schema(): void
    {
        // Issue #405: form bodies are no longer presence-only. Values arrive
        // as strings and are coerced to the declared types before the schema
        // runs, exactly as query parameters are.
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            self::formOperation(),
            DecodedBody::present(['name' => 'Fido', 'age' => '3']),
            'application/x-www-form-urlencoded',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertNull($result->skipReason);
        $this->assertSame('application/x-www-form-urlencoded', $result->matchedContentType);
    }

    #[Test]
    public function validate_reports_a_form_urlencoded_type_violation(): void
    {
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            self::formOperation(),
            DecodedBody::present(['name' => 'Fido', 'age' => 'three']),
            'application/x-www-form-urlencoded; charset=utf-8',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('/age', $result->errors[0]);
        $this->assertCount(1, $result->violations);
        $this->assertSame('/age', $result->violations[0]->instancePath);
    }

    #[Test]
    public function validate_reports_a_missing_required_form_field(): void
    {
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            self::formOperation(),
            DecodedBody::present(['age' => '3']),
            'application/x-www-form-urlencoded',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('name', $result->errors[0]);
    }

    #[Test]
    public function validate_parses_a_raw_urlencoded_body(): void
    {
        // Adapters without a parsed bag (a client PSR-7 request) hand over the
        // raw bytes; the validator parses them rather than skipping.
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            self::formOperation(),
            DecodedBody::present('name=Fido&age=notanumber'),
            'application/x-www-form-urlencoded',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('/age', $result->errors[0]);
    }

    #[Test]
    public function validate_accepts_a_multipart_file_part_for_a_required_binary_property(): void
    {
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            self::multipartOperation(),
            DecodedBody::present(['avatar' => new UploadedPart('image/png', 'avatar.png')]),
            'multipart/form-data; boundary=----x',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertNull($result->skipReason);
        $this->assertSame('multipart/form-data', $result->matchedContentType);
    }

    #[Test]
    public function validate_reports_a_missing_required_multipart_part(): void
    {
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            self::multipartOperation(),
            DecodedBody::present(['note' => 'no file here']),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('avatar', $result->errors[0]);
    }

    #[Test]
    public function validate_rejects_a_part_content_type_the_encoding_object_forbids(): void
    {
        // The bytes of a binary part are never read, but the part's declared
        // Content-Type is a contract the encoding object can pin.
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            self::multipartOperation(),
            DecodedBody::present(['avatar' => new UploadedPart('application/pdf', 'avatar.pdf')]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('application/pdf', $result->errors[0]);
        $this->assertStringContainsString('image/*', $result->errors[0]);
    }

    #[Test]
    public function validate_applies_a_subschema_to_a_json_part(): void
    {
        // league/openapi-psr7-validator#234: a JSON part declared through
        // encoding.contentType is decoded before its subschema is applied.
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            self::multipartOperation(),
            DecodedBody::present([
                'avatar' => new UploadedPart('image/png', 'avatar.png'),
                'meta' => '{"label": 7}',
            ]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('/meta/label', $result->errors[0]);
    }

    #[Test]
    public function validate_reports_a_json_part_that_does_not_parse(): void
    {
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            self::multipartOperation(),
            DecodedBody::present([
                'avatar' => new UploadedPart('image/png', 'avatar.png'),
                'meta' => '{not json',
            ]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('not valid JSON', $result->errors[0]);
    }

    #[Test]
    public function validate_skips_a_multipart_body_that_was_never_parsed(): void
    {
        // Raw multipart bytes are not reassembled here. That must surface as a
        // skip with a reason, never as a clean pass.
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            self::multipartOperation(),
            DecodedBody::present('--boundary\r\nContent-Disposition: form-data; name="avatar"'),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertNotNull($result->skipReason);
        $this->assertStringContainsString('parsed field map', $result->skipReason);
        $this->assertSame('multipart/form-data', $result->matchedContentType);
    }

    #[Test]
    public function validate_rejects_a_malformed_encoding_object(): void
    {
        $operation = self::multipartOperation();
        $operation['requestBody']['content']['multipart/form-data']['encoding'] = 'oops';

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::present(['avatar' => new UploadedPart('image/png', 'avatar.png')]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString("Malformed 'requestBody.content[\"multipart/form-data\"].encoding'", $result->errors[0]);
    }

    #[Test]
    public function validate_decodes_an_object_part_that_declares_no_encoding(): void
    {
        // OAS 3.0.3 Encoding Object: an object property defaults to
        // application/json. Without this the part stays a string and fails
        // its own `type: object` subschema, so a perfectly valid spec that
        // omits `encoding` could never pass.
        $operation = self::multipartOperation();
        unset($operation['requestBody']['content']['multipart/form-data']['encoding']['meta']);

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::present([
                'avatar' => new UploadedPart('image/png', 'avatar.png'),
                'meta' => '{"label": "hero"}',
            ]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);

        $invalid = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::present([
                'avatar' => new UploadedPart('image/png', 'avatar.png'),
                'meta' => '{"label": 7}',
            ]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $invalid->errors);
        $this->assertStringContainsString('/meta/label', $invalid->errors[0]);
    }

    #[Test]
    public function validate_decodes_a_json_part_regardless_of_its_position_in_the_encoding_list(): void
    {
        // `encoding.contentType` may list several JSON flavours; the part is
        // JSON whichever one comes first.
        $operation = self::multipartOperation();
        $operation['requestBody']['content']['multipart/form-data']['encoding']['meta']['contentType']
            = 'application/problem+json, application/json';

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::present([
                'avatar' => new UploadedPart('image/png', 'avatar.png'),
                'meta' => '{"label": 7}',
            ]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('/meta/label', $result->errors[0]);
    }

    #[Test]
    public function validate_does_not_sniff_a_string_part_against_a_mixed_encoding_list(): void
    {
        // OAS 3.2 §4.15.4.1: the part's own Content-Type selects among several
        // declared media types, and sniffing is not the default. That header
        // does not survive any adapter's form parsing, so a text/plain value
        // that happens to parse as JSON (`42`) must NOT be decoded — doing so
        // turned a valid string part into an integer and failed its schema.
        $operation = self::multipartOperation();
        $operation['requestBody']['content']['multipart/form-data']['schema']['properties']['note']
            = ['type' => 'string'];
        $operation['requestBody']['content']['multipart/form-data']['encoding']['note']
            = ['contentType' => 'application/json, text/plain'];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::present([
                'avatar' => new UploadedPart('image/png', 'avatar.png'),
                'note' => '42',
            ]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_decodes_a_mixed_list_part_whose_schema_cannot_hold_a_string(): void
    {
        // The text alternative of a mixed list could never satisfy an object
        // schema, so JSON is the only readable choice — a contract-driven
        // selection rather than a guess from the content.
        $operation = self::multipartOperation();
        $operation['requestBody']['content']['multipart/form-data']['encoding']['meta']['contentType']
            = 'text/plain, application/json';

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::present([
                'avatar' => new UploadedPart('image/png', 'avatar.png'),
                'meta' => '{"label": 7}',
            ]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('/meta/label', $result->errors[0]);
    }

    #[Test]
    public function validate_rejects_a_binary_part_that_arrived_as_a_plain_field(): void
    {
        // A non-file part carries no Content-Type through any adapter, so an
        // `image/*` encoding entry cannot be matched against one. The schema
        // still decides the case that matters: raw-bytes properties must
        // arrive as file parts.
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            self::multipartOperation(),
            DecodedBody::present(['avatar' => 'hello']),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('did not arrive as a file part', $result->errors[0]);
    }

    #[Test]
    public function validate_accepts_a_binary_property_as_a_plain_field_for_urlencoded_bodies(): void
    {
        // The file-part rule is multipart-only: an urlencoded body has no
        // parts at all, so `format: binary` there is just a string.
        $operation = self::formOperation();
        $operation['requestBody']['content']['application/x-www-form-urlencoded']['schema']['properties']['name']
            = ['type' => 'string', 'format' => 'binary'];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            DecodedBody::present(['name' => 'Fido']),
            'application/x-www-form-urlencoded',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_keeps_a_non_json_part_when_the_encoding_list_allows_another_type(): void
    {
        // A mixed list plus a string schema means the part is never read as
        // JSON, so unparseable content is not an encoding error either.
        $operation = self::multipartOperation();
        $operation['requestBody']['content']['multipart/form-data']['schema']['properties']['note']
            = ['type' => 'string'];
        $operation['requestBody']['content']['multipart/form-data']['encoding']['note']
            = ['contentType' => 'application/json, text/plain'];

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::present([
                'avatar' => new UploadedPart('image/png', 'avatar.png'),
                'note' => 'just words',
            ]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_treats_a_part_without_a_content_type_as_text_plain(): void
    {
        // RFC 7578 §4.4: an absent part Content-Type means text/plain, which
        // does not satisfy `image/*`. Passing unknown types would make the
        // constraint opt-out for any adapter that cannot report a part type.
        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            self::multipartOperation(),
            DecodedBody::present(['avatar' => new UploadedPart(null, 'avatar.bin')]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('image/*', $result->errors[0]);
    }

    #[Test]
    public function validate_rejects_a_malformed_encoding_object_even_without_a_body(): void
    {
        // A broken spec node is broken whether or not this request carried a
        // body that would reach it — same rule as `content` / `schema`.
        $operation = self::multipartOperation();
        $operation['requestBody']['required'] = false;
        $operation['requestBody']['content']['multipart/form-data']['encoding'] = 'oops';

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::absent(),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString("encoding'", $result->errors[0]);
    }

    #[Test]
    public function validate_rejects_a_malformed_encoding_entry(): void
    {
        $operation = self::multipartOperation();
        $operation['requestBody']['content']['multipart/form-data']['encoding']['avatar'] = 'oops';

        $result = $this->validator->validate(
            'spec',
            'POST',
            '/uploads',
            $operation,
            DecodedBody::present(['avatar' => new UploadedPart('image/png', 'avatar.png')]),
            'multipart/form-data',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('encoding["avatar"]', $result->errors[0]);
    }

    /** @return array<string, mixed> */
    private static function formOperation(): array
    {
        return [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/x-www-form-urlencoded' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'age' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function multipartOperation(): array
    {
        return [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'multipart/form-data' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['avatar'],
                            'properties' => [
                                'avatar' => ['type' => 'string', 'format' => 'binary'],
                                'note' => ['type' => 'string'],
                                'meta' => [
                                    'type' => 'object',
                                    'properties' => ['label' => ['type' => 'string']],
                                ],
                            ],
                        ],
                        'encoding' => [
                            'avatar' => ['contentType' => 'image/*'],
                            'meta' => ['contentType' => 'application/json'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
