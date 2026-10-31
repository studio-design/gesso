<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\SchemaViolation;

use function array_map;
use function preg_match_all;
use function str_repeat;

/**
 * The schema-side pointer and the `actual` / `expected` excerpts a schema
 * violation carries (v3 vision item A3).
 */
final class SchemaViolationContextTest extends TestCase
{
    #[Test]
    public function a_nested_violation_points_at_its_keyword_with_both_values(): void
    {
        $violation = $this->only(
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ['name' => 42],
        );

        $this->assertSame('/name', $violation->instancePath);
        $this->assertSame('/properties/name/type', $violation->schemaPointer);
        $this->assertSame('42', $violation->actual);
        $this->assertSame('"string"', $violation->expected);
    }

    #[Test]
    public function an_object_value_is_left_out_but_a_scalar_list_constraint_is_kept(): void
    {
        $violation = $this->only(['type' => 'object', 'required' => ['id', 'name']], ['name' => 'x']);

        $this->assertSame('/required', $violation->schemaPointer);
        $this->assertNull($violation->actual);
        $this->assertSame('["id","name"]', $violation->expected);
    }

    #[Test]
    public function a_subschema_constraint_is_left_out_and_branch_pointers_keep_their_index(): void
    {
        $violations = $this->violations(
            ['type' => 'object', 'properties' => ['pet' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]]],
            ['pet' => true],
        );

        $pointers = array_map(static fn(SchemaViolation $violation): ?string => $violation->schemaPointer, $violations);
        $this->assertContains('/properties/pet/oneOf/0/type', $pointers);
        $this->assertContains('/properties/pet/oneOf/1/type', $pointers);

        $oneOf = $this->only(['oneOf' => [['type' => 'string'], ['minLength' => 1]]], 'abc');
        $this->assertSame('/oneOf', $oneOf->schemaPointer);
        $this->assertNull($oneOf->expected);
    }

    #[Test]
    public function the_pointer_follows_the_converted_openapi_30_schema(): void
    {
        $violation = $this->only(
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'nullable' => true]]],
            ['name' => 1],
            OpenApiVersion::V3_0,
        );

        $this->assertSame('/properties/name/type', $violation->schemaPointer);
        $this->assertSame('["string","null"]', $violation->expected);
    }

    #[Test]
    public function credential_like_values_are_redacted(): void
    {
        $byName = $this->only(
            ['type' => 'object', 'properties' => ['password' => ['type' => 'string', 'minLength' => 12]]],
            ['password' => 'hunter2'],
        );
        $this->assertSame('<redacted>', $byName->actual);

        $byFormat = $this->only(
            ['type' => 'object', 'properties' => ['pin' => ['type' => 'string', 'format' => 'password', 'minLength' => 6]]],
            ['pin' => '1234'],
        );
        $this->assertSame('<redacted>', $byFormat->actual);

        $runner = new SchemaValidatorRunner(20);
        $named = $runner->validateNamed('api_key', 'query.api_key', ['type' => 'integer'], 'secret-value', OpenApiVersion::V3_1, SchemaContext::Request);
        $this->assertSame('<redacted>', $named[0]->violation?->actual);
    }

    #[Test]
    public function long_values_are_truncated(): void
    {
        $violation = $this->only(['type' => 'string', 'maxLength' => 3], str_repeat('é', 200));

        $this->assertNotNull($violation->actual);
        $this->assertSame(81, preg_match_all('/./su', $violation->actual));
        $this->assertStringEndsWith('…', $violation->actual);
    }

    /** @param array<string, mixed> $schema */
    private function only(array $schema, mixed $data, OpenApiVersion $version = OpenApiVersion::V3_1): SchemaViolation
    {
        $violations = $this->violations($schema, $data, $version);
        $this->assertCount(1, $violations);

        return $violations[0];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<SchemaViolation>
     */
    private function violations(array $schema, mixed $data, OpenApiVersion $version = OpenApiVersion::V3_1): array
    {
        $converted = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Response);

        return (new SchemaValidatorRunner(20))->validateStructured(ObjectConverter::convert($converted), ObjectConverter::convert($data));
    }
}
