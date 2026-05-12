<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\OpenApiVersion;
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
        $errors = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            [],
            null,
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            null,
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Request body is empty', $errors[0]);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            null,
            'application/xml',
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Content-Type 'application/xml' is not defined", $errors[0]);
    }

    #[Test]
    public function validate_flags_malformed_non_array_request_body(): void
    {
        $operation = ['requestBody' => 'oops'];

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            null,
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Malformed 'requestBody'", $errors[0]);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            null,
            null,
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('.schema\'', $errors[0]);
        $this->assertStringContainsString('expected object, got scalar', $errors[0]);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/pets',
            $operation,
            ['name' => 'Fido'],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            [],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            [],
            'application/json',
            OpenApiVersion::V3_1,
        );

        $this->assertSame([], $errors);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            [],
            'application/json',
            OpenApiVersion::V3_0,
        );

        // No error AND no coercion fired — the property bag is permissive
        // so empty input passes for either array or object interpretation.
        $this->assertSame([], $errors);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            [],
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
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must match the type', $errors[0]);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            [],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
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

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            [],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('required properties (foo)', $errors[0]);
    }

    #[Test]
    public function validate_accepts_empty_object_body_when_request_body_is_optional(): void
    {
        // Request-side-specific invariant: the coercion fires regardless of
        // `required: true|false` because an empty `{}` body arrives as PHP
        // `[]`, not as `null` — only `null` short-circuits the `required`
        // branch. A future refactor that moved the optional-body fast-path
        // to also match `[]` would silently skip the coercion gate; this
        // test pins the current behaviour.
        $operation = [
            'requestBody' => [
                'required' => false,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                ],
            ],
        ];

        $errors = $this->validator->validate(
            'spec',
            'POST',
            '/p',
            $operation,
            [],
            'application/json',
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }
}
