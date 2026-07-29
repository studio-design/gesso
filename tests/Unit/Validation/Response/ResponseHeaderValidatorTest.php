<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Validation\Response\ResponseHeaderValidator;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

use function array_map;

class ResponseHeaderValidatorTest extends TestCase
{
    #[Test]
    public function returns_no_errors_when_spec_defines_no_headers(): void
    {
        $errors = $this->validator()->validate([], ['Location' => 'https://example.com/pets/1'], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function passes_when_required_header_is_present(): void
    {
        $headersSpec = [
            'Location' => [
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['Location' => 'https://example.com/pets/1'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_missing_required_header(): void
    {
        $headersSpec = [
            'Location' => [
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame(['[response-header.Location] required header is missing.'], self::messages($errors));
    }

    #[Test]
    public function passes_when_optional_header_is_absent(): void
    {
        $headersSpec = [
            'X-RateLimit-Remaining' => [
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function matches_header_names_case_insensitively(): void
    {
        $headersSpec = [
            'Location' => [
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['location' => 'https://example.com/pets/1'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_schema_mismatch_with_response_header_prefix(): void
    {
        $headersSpec = [
            'X-RateLimit-Remaining' => [
                'schema' => ['type' => 'integer', 'minimum' => 0],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-RateLimit-Remaining' => '-5'],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringStartsWith('[response-header.X-RateLimit-Remaining]', $errors[0]->message);
    }

    #[Test]
    public function coerces_string_value_to_integer_for_schema_check(): void
    {
        // HTTP header values are always strings; the validator must coerce
        // a clean integer-shaped string before checking against type: integer.
        $headersSpec = [
            'X-Count' => [
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Count' => '42'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_format_violation(): void
    {
        $headersSpec = [
            'X-Issued-At' => [
                'schema' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Issued-At' => 'not-a-date'],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringStartsWith('[response-header.X-Issued-At]', $errors[0]->message);
    }

    #[Test]
    public function skips_content_type_header_per_oas_spec(): void
    {
        // Per OAS 3.0/3.1, "If a response header is defined with the name
        // 'Content-Type', it SHALL be ignored." Even with an absurd schema
        // mismatch, no error must surface.
        $headersSpec = [
            'Content-Type' => [
                'required' => true,
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['Content-Type' => 'application/json'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function skips_content_type_case_insensitively(): void
    {
        $headersSpec = [
            'content-type' => [
                'required' => true,
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function unwraps_single_element_array_value_from_header_bag(): void
    {
        $headersSpec = [
            'X-Count' => [
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Count' => ['42']],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_multi_value_header_against_scalar_schema(): void
    {
        // Sending the same header more than once with a scalar schema is
        // ambiguous (Laravel picks first, Symfony picks last). Refusing
        // to silently choose mirrors the request-side behaviour.
        $headersSpec = [
            'X-Foo' => [
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Foo' => ['a', 'b']],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('multiple values', $errors[0]->message);
        $this->assertStringStartsWith('[response-header.X-Foo]', $errors[0]->message);
    }

    #[Test]
    public function treats_empty_array_value_as_absent_header(): void
    {
        $headersSpec = [
            'X-Optional' => [
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Optional' => []],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function reports_required_with_no_schema_as_spec_error(): void
    {
        // Required headers with no schema would silently pass every response,
        // mirroring the request-side guard rail.
        $headersSpec = [
            'X-Token' => ['required' => true],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Token' => 'abc'],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('no schema', $errors[0]->message);
        $this->assertStringStartsWith('[response-header.X-Token]', $errors[0]->message);
    }

    #[Test]
    public function reports_non_array_header_definition_as_spec_error(): void
    {
        // YAML/JSON authoring slips that produce e.g. `Location: "string"`
        // would silently disable validation otherwise. Surfacing it ensures
        // the spec author notices.
        $headersSpec = ['X-Misdefined' => 'string'];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Misdefined' => 'whatever'],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringStartsWith('[response-header.X-Misdefined]', $errors[0]->message);
        $this->assertStringContainsString('must be an object', $errors[0]->message);
    }

    #[Test]
    public function skips_content_type_with_trailing_whitespace_in_spec_key(): void
    {
        // Defensive: a YAML/JSON authoring slip that produces a trailing
        // space on the Content-Type key must still trigger the OAS-mandated
        // skip rather than running schema validation.
        $headersSpec = [
            'Content-Type ' => [
                'required' => true,
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function passes_when_zero_string_satisfies_minimum_zero_integer_schema(): void
    {
        // The missing-check is `=== null || === []`, not `empty()`. Pin
        // that a literal '0' does NOT collapse to "missing" so a future
        // refactor can't silently turn `X-RateLimit-Remaining: 0` into
        // a "header is missing" error.
        $headersSpec = [
            'X-Count' => [
                'required' => true,
                'schema' => ['type' => 'integer', 'minimum' => 0],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Count' => '0'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function passes_when_empty_string_value_is_present_for_string_schema(): void
    {
        // An empty string is a real (though unusual) header value. It must
        // not collapse to "missing" — the schema decides whether empty
        // strings are valid via `minLength`.
        $headersSpec = [
            'X-Comment' => [
                'schema' => ['type' => 'string'],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Comment' => ''],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function v31_type_array_with_null_validates_against_nullable_integer(): void
    {
        // Pin that the OpenApiVersion argument actually flows through and
        // a 3.1 multi-type declaration `["integer", "null"]` correctly
        // accepts a clean integer-shaped string. This branch is invisible
        // to V3_0-only tests because the schema converter handles 3.0's
        // `nullable: true` differently.
        $headersSpec = [
            'X-Tags-Count' => [
                'schema' => ['type' => ['integer', 'null']],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Tags-Count' => '7'],
            OpenApiVersion::V3_1,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function array_typed_header_schema_documents_known_limitation(): void
    {
        // The validator coerces header values as scalars (matching the
        // request-side `style: simple` policy), so a `type: array` header
        // schema cannot succeed: a single-element value gets unwrapped
        // and fed to opis as a string, which fails the array type check.
        // This pin documents the known limitation; if array support is
        // ever added the test will start failing and force a review.
        $headersSpec = [
            'X-Tags' => [
                'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        $errors = $this->validator()->validate(
            $headersSpec,
            ['X-Tags' => ['a']],
            OpenApiVersion::V3_0,
        );

        $this->assertNotSame([], $errors);
        $this->assertStringStartsWith('[response-header.X-Tags', $errors[0]->message);
    }

    #[Test]
    public function preserves_original_spec_casing_in_error_messages(): void
    {
        $headersSpec = [
            'X-RateLimit-Remaining' => [
                'required' => true,
                'schema' => ['type' => 'integer'],
            ],
        ];

        $errors = $this->validator()->validate($headersSpec, [], OpenApiVersion::V3_0);

        $this->assertSame(
            ['[response-header.X-RateLimit-Remaining] required header is missing.'],
            self::messages($errors),
        );
    }

    /**
     * @param list<NamedError> $errors
     *
     * @return string[]
     */
    private static function messages(array $errors): array
    {
        return array_map(static fn(NamedError $error): string => $error->message, $errors);
    }

    private function validator(): ResponseHeaderValidator
    {
        return new ResponseHeaderValidator(new SchemaValidatorRunner(20));
    }
}
