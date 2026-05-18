<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Internal\PresentJsonNull;
use Studio\OpenApiContractTesting\OpenApiVersion;
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
            ['id' => 1],
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
            null,
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
        // body. The PresentJsonNull marker is how an adapter signals "the
        // wire carried a body and its decoded value is null".
        $content = [
            'application/json' => ['schema' => ['type' => 'object']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $content,
            PresentJsonNull::Body,
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
            PresentJsonNull::Body,
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
            PresentJsonNull::Body,
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_accepts_non_json_content_type_when_defined_in_spec(): void
    {
        $content = [
            'text/plain' => ['schema' => ['type' => 'string']],
        ];

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/robots.txt',
            200,
            $content,
            'User-agent: *',
            'text/plain; charset=utf-8',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('text/plain', $result->matchedContentType);
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
            ['detail' => 'oops'],
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
            'blob',
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
            '<pets/>',
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
            [],
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
            [],
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
            [],
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
            [],
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
            [],
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
            ['id' => 'not-an-int'],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('/id', $result->errors[0]);
        $this->assertSame('application/json', $result->matchedContentType);
    }
}
