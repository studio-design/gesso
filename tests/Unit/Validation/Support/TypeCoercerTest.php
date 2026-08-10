<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Support\TypeCoercer;

class TypeCoercerTest extends TestCase
{
    #[Test]
    public function first_primitive_type_skips_null(): void
    {
        $this->assertSame('integer', TypeCoercer::firstPrimitiveType(['null', 'integer']));
        $this->assertSame('string', TypeCoercer::firstPrimitiveType(['string']));
    }

    #[Test]
    public function first_primitive_type_returns_null_when_only_null_present(): void
    {
        $this->assertNull(TypeCoercer::firstPrimitiveType(['null']));
        $this->assertNull(TypeCoercer::firstPrimitiveType([]));
    }

    #[Test]
    public function coerce_to_int_converts_canonical_digits(): void
    {
        $this->assertSame(0, TypeCoercer::coerceToInt('0'));
        $this->assertSame(42, TypeCoercer::coerceToInt('42'));
        $this->assertSame(-7, TypeCoercer::coerceToInt('-7'));
    }

    #[Test]
    public function coerce_to_int_rejects_non_canonical(): void
    {
        // Leading zero, whitespace, plus sign, decimal — all pass through untouched.
        $this->assertSame('05', TypeCoercer::coerceToInt('05'));
        $this->assertSame('5 ', TypeCoercer::coerceToInt('5 '));
        $this->assertSame('+5', TypeCoercer::coerceToInt('+5'));
        $this->assertSame('3.14', TypeCoercer::coerceToInt('3.14'));
        $this->assertSame('abc', TypeCoercer::coerceToInt('abc'));
    }

    #[Test]
    public function coerce_to_int_falls_back_to_string_on_overflow(): void
    {
        // A canonical-integer shape that exceeds PHP_INT_MAX: the regex passes
        // but `filter_var` returns false, so the original string must survive
        // unchanged so opis can flag the type mismatch instead of quietly
        // receiving a truncated int.
        $overflow = '99999999999999999999';

        $this->assertSame($overflow, TypeCoercer::coerceToInt($overflow));
    }

    #[Test]
    public function coerce_primitive_from_type_handles_boolean_and_number(): void
    {
        $this->assertTrue(TypeCoercer::coercePrimitiveFromType('true', 'boolean'));
        $this->assertFalse(TypeCoercer::coercePrimitiveFromType('FALSE', 'boolean'));
        $this->assertSame(3.14, TypeCoercer::coercePrimitiveFromType('3.14', 'number'));
        $this->assertSame('maybe', TypeCoercer::coercePrimitiveFromType('maybe', 'boolean'));
    }

    #[Test]
    public function coerce_primitive_uses_first_primitive_type_for_multi_type_schema(): void
    {
        $schema = ['type' => ['null', 'integer']];

        $this->assertSame(42, TypeCoercer::coercePrimitive('42', $schema));
    }

    #[Test]
    public function coerce_query_handles_array_type(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];

        $this->assertSame([1, 2, 3], TypeCoercer::coerceQuery(['1', '2', '3'], $schema));
    }

    #[Test]
    public function coerce_query_wraps_scalar_when_type_is_array(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];

        $this->assertSame([5], TypeCoercer::coerceQuery('5', $schema));
    }

    #[Test]
    public function coerce_query_skips_per_item_coercion_when_items_schema_missing(): void
    {
        // With no `items` schema (or a non-array `items` like an OAS $ref string
        // that slipped past validation), the array is only reindexed — values
        // stay as raw strings so opis surfaces the shape mismatch.
        $schema = ['type' => 'array'];

        $this->assertSame(['1', '2'], TypeCoercer::coerceQuery(['1', '2'], $schema));
    }

    #[Test]
    public function reads_the_type_from_an_allof_branch_when_the_schema_omits_it(): void
    {
        $this->assertSame(5, TypeCoercer::coercePrimitive('5', [
            'maximum' => 100,
            'allOf' => [['type' => 'integer']],
        ]));
    }

    #[Test]
    public function reads_query_type_and_items_from_an_allof_branch(): void
    {
        $this->assertSame([1, 2], TypeCoercer::coerceQuery(['1', '2'], [
            'allOf' => [['type' => 'array', 'items' => ['type' => 'integer']]],
        ]));
    }

    #[Test]
    public function the_schemas_own_type_wins_over_an_allof_branch(): void
    {
        $this->assertSame('5', TypeCoercer::coercePrimitive('5', [
            'type' => 'string',
            'allOf' => [['type' => 'integer']],
        ]));
    }
}
