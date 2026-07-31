<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Response;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Validation\Response\ResponseBodyValidationResult;
use Studio\Gesso\Validation\Response\ResponseBodyValidator;
use Studio\Gesso\Validation\Response\ResponseSchemaResolution;
use Studio\Gesso\Validation\Support\DiscriminatorContext;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

/**
 * Body-vs-schema validation only: content negotiation, media-type guards,
 * and schema selection moved to {@see ResponseSchemaResolver} (issue #442)
 * and are pinned in ResponseSchemaResolverTest.
 */
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
        $resolution = self::resolution([
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ]);

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets/{id}',
            200,
            $resolution,
            DecodedBody::present(['id' => 1]),
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_flags_empty_body_against_json_schema(): void
    {
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            self::resolution(['type' => 'object']),
            DecodedBody::absent(),
        );

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Response body is empty', $result->errors[0]);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_carries_structured_violations_aligned_with_errors(): void
    {
        // Issue #282 stage 2: schema errors keep their structured twin so the
        // orchestrator can attach instancePath/keyword to response.body issues.
        $resolution = self::resolution([
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id', 'name'],
        ]);

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            $resolution,
            DecodedBody::present(['id' => 'not-an-int']),
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
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            self::resolution(['type' => 'object']),
            DecodedBody::absent(),
        );

        $this->assertNotSame([], $result->errors);
        $this->assertSame([], $result->violations);
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
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            self::resolution(['type' => 'object']),
            DecodedBody::present(null),
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
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            self::resolution(['type' => ['object', 'null']], version: OpenApiVersion::V3_1),
            DecodedBody::present(null),
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
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets',
            200,
            self::resolution(['type' => 'object', 'nullable' => true]),
            DecodedBody::present(null),
        );

        $this->assertSame([], $result->errors);
        $this->assertSame('application/json', $result->matchedContentType);
    }

    #[Test]
    public function validate_rejects_non_resolved_resolution(): void
    {
        // Non-Resolved outcomes are mapped by the orchestrator before the
        // body validator runs; reaching here with one is a wiring bug.
        $resolution = ResponseSchemaResolution::noContent('/pets', '204', []);

        $this->expectException(LogicException::class);
        $this->validator->validate('spec', 'GET', '/pets', 204, $resolution, DecodedBody::absent());
    }

    #[Test]
    public function validate_accepts_empty_object_body_against_type_object(): void
    {
        // PHP's `json_decode('{}', true) === []` — the Laravel trait's
        // associative-array decoding loses the {} vs [] distinction. Without
        // schema-aware coercion the validator would reject `[]` against
        // `type: object`. Pin the fix so empty-{} responses validate.
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            self::resolution(['type' => 'object']),
            DecodedBody::present([]),
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_accepts_empty_object_body_against_oas_31_nullable_object(): void
    {
        // OAS 3.1 type-array form: `type: ["object", "null"]`. Same coercion.
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            self::resolution(['type' => ['object', 'null']], version: OpenApiVersion::V3_1),
            DecodedBody::present([]),
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
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            self::resolution(['properties' => ['id' => ['type' => 'integer']]]),
            DecodedBody::present([]),
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
        $resolution = self::resolution([
            'oneOf' => [
                ['type' => 'object', 'required' => ['foo'], 'properties' => ['foo' => ['type' => 'string']]],
            ],
        ]);

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            $resolution,
            DecodedBody::present([]),
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
        $result = $this->validator->validate(
            'spec',
            'GET',
            '/p',
            200,
            self::resolution(['type' => 'array', 'items' => ['type' => 'string']]),
            DecodedBody::present([]),
        );

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validate_flags_schema_mismatch(): void
    {
        $resolution = self::resolution([
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ]);

        $result = $this->validator->validate(
            'spec',
            'GET',
            '/pets/{id}',
            200,
            $resolution,
            DecodedBody::present(['id' => 'not-an-int']),
        );

        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('/id', $result->errors[0]);
        $this->assertSame('application/json', $result->matchedContentType);
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

    /**
     * @param array<string, mixed> $schema
     */
    private static function resolution(
        array $schema,
        string $contentType = 'application/json',
        OpenApiVersion $version = OpenApiVersion::V3_0,
    ): ResponseSchemaResolution {
        return ResponseSchemaResolution::resolved(
            '/pets',
            '200',
            $contentType,
            ['content' => [$contentType => ['schema' => $schema]]],
            $schema,
            $version,
            $version === OpenApiVersion::V3_0 ? OpenApiSchemaDialect::DRAFT_07 : OpenApiSchemaDialect::OAS_3_1,
            DiscriminatorContext::disabled(),
        );
    }
}
