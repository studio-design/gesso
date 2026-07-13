<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Fuzz;

use const E_USER_WARNING;

use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\OpenApiContractTesting\Fuzz\SchemaDataGenerator;
use Studio\OpenApiContractTesting\Fuzz\SchemaMutationGenerator;
use Studio\OpenApiContractTesting\Fuzz\SchemaValueValidator;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Validation\Support\DiscriminatorContext;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function fmod;
use function json_decode;
use function json_encode;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function str_repeat;
use function strlen;

class SchemaDataGeneratorTest extends TestCase
{
    #[Test]
    public function rejects_non_positive_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SchemaDataGenerator::generate(['type' => 'string'], 0);
    }

    #[Test]
    public function generates_strings(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'string'], 3, seed: 1);

        $this->assertCount(3, $values);
        foreach ($values as $value) {
            $this->assertIsString($value);
            $this->assertNotSame('', $value);
        }
    }

    #[Test]
    public function generates_integers_within_range(): void
    {
        $values = SchemaDataGenerator::generate(
            ['type' => 'integer', 'minimum' => 5, 'maximum' => 10],
            5,
            seed: 1,
        );

        foreach ($values as $value) {
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(5, $value);
            $this->assertLessThanOrEqual(10, $value);
        }
    }

    #[Test]
    public function generates_numbers_within_range(): void
    {
        $values = SchemaDataGenerator::generate(
            ['type' => 'number', 'minimum' => 1.5, 'maximum' => 2.5],
            5,
            seed: 1,
        );

        foreach ($values as $value) {
            $this->assertIsFloat($value);
            $this->assertGreaterThanOrEqual(1.5, $value);
            $this->assertLessThanOrEqual(2.5, $value);
        }
    }

    #[Test]
    public function number_generation_honors_multiple_of_after_boundary_cases(): void
    {
        $schema = [
            'type' => 'number',
            'minimum' => 0,
            'maximum' => 10,
            'multipleOf' => 2,
        ];

        $values = SchemaDataGenerator::generate($schema, 30, seed: 1);

        foreach ($values as $value) {
            $this->assertIsFloat($value);
            $this->assertSame(0.0, fmod($value, 2.0));
        }
    }

    #[Test]
    public function generates_booleans(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'boolean'], 4, seed: 1);

        foreach ($values as $value) {
            $this->assertIsBool($value);
        }
    }

    #[Test]
    public function honors_required_keys_in_objects(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'tag' => ['type' => 'string'],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 6, seed: 1);

        foreach ($values as $value) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey('name', $value, 'required keys must always be emitted');
        }
    }

    #[Test]
    public function alternates_optional_keys_so_both_shapes_appear(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'tag' => ['type' => 'string'],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 6, seed: 1);
        $hasOptional = false;
        $missingOptional = false;
        foreach ($values as $value) {
            $this->assertIsArray($value);
            if (isset($value['tag'])) {
                $hasOptional = true;
            } else {
                $missingOptional = true;
            }
        }

        $this->assertTrue($hasOptional, 'at least one case should include the optional key');
        $this->assertTrue($missingOptional, 'at least one case should omit the optional key');
    }

    #[Test]
    public function generates_arrays_of_items(): void
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
        ];

        $values = SchemaDataGenerator::generate($schema, 4, seed: 1);

        foreach ($values as $value) {
            $this->assertIsArray($value);
            $this->assertGreaterThanOrEqual(1, count($value));
            foreach ($value as $item) {
                $this->assertIsInt($item);
            }
        }
    }

    #[Test]
    public function picks_values_only_from_enum(): void
    {
        $allowed = ['available', 'pending', 'sold'];
        $schema = ['type' => 'string', 'enum' => $allowed];

        $values = SchemaDataGenerator::generate($schema, 9, seed: 1);

        foreach ($values as $value) {
            $this->assertContains($value, $allowed);
        }
    }

    #[Test]
    public function honors_format_email(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'string', 'format' => 'email'], 3, seed: 1);

        foreach ($values as $value) {
            $this->assertIsString($value);
            $this->assertSame(1, preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $value), "got: {$value}");
        }
    }

    #[Test]
    public function honors_format_uuid(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'string', 'format' => 'uuid'], 3, seed: 1);

        foreach ($values as $value) {
            $this->assertIsString($value);
            $this->assertSame(
                1,
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value),
                "got: {$value}",
            );
        }
    }

    #[Test]
    public function honors_min_and_max_length(): void
    {
        $schema = ['type' => 'string', 'minLength' => 4, 'maxLength' => 6];

        $values = SchemaDataGenerator::generate($schema, 5, seed: 1);

        foreach ($values as $value) {
            $this->assertIsString($value);
            $len = strlen($value);
            $this->assertGreaterThanOrEqual(4, $len);
            $this->assertLessThanOrEqual(6, $len);
        }
    }

    #[Test]
    public function same_seed_produces_same_output(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'tag' => ['type' => 'string'],
                'age' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ];

        $first = SchemaDataGenerator::generate($schema, 5, seed: 12345);
        $second = SchemaDataGenerator::generate($schema, 5, seed: 12345);

        $this->assertSame(json_encode($first), json_encode($second));
    }

    #[Test]
    public function generated_values_pass_opis_schema_validation(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name', 'count'],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
                'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['a', 'b', 'c']],
                ],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 8, seed: 1);
        $validator = new Validator();
        $jsonSchema = json_decode((string) json_encode($schema));

        foreach ($values as $value) {
            $jsonValue = json_decode((string) json_encode($value));
            $result = $validator->validate($jsonValue, $jsonSchema);
            $this->assertTrue($result->isValid(), 'generated value should validate against its schema');
        }
    }

    #[Test]
    public function nullable_type_array_explores_both_branches(): void
    {
        // Draft-2020 / OAS 3.1 normalised form after OpenApiSchemaConverter.
        $schema = ['type' => ['string', 'null']];

        $values = SchemaDataGenerator::generate($schema, 3, seed: 1);

        $this->assertIsString($values[0]);
        $this->assertIsString($values[1]);
        $this->assertNull($values[2]);
    }

    #[Test]
    public function untyped_object_is_inferred_from_properties(): void
    {
        $schema = [
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];

        $values = SchemaDataGenerator::generate($schema, 2, seed: 1);

        foreach ($values as $value) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey('name', $value);
        }
    }

    #[Test]
    public function different_seeds_produce_different_output(): void
    {
        // Without this test, dropping `$faker->seed($seed)` would silently
        // pass — same_seed_produces_same_output would still hold because
        // `Faker\Factory::create()` is only called once per generate().
        $schema = ['type' => 'string', 'minLength' => 8, 'maxLength' => 8];

        $first = SchemaDataGenerator::generate($schema, 4, seed: 1);
        $second = SchemaDataGenerator::generate($schema, 4, seed: 999);

        $this->assertNotSame(json_encode($first), json_encode($second));
    }

    #[Test]
    public function fallback_strings_are_valid_without_faker(): void
    {
        // Drive generateOne() directly with a null faker so the deterministic
        // fallback runs in CI even though fakerphp/faker is always installed
        // in dev. Without this test, breaking the fallback path would never
        // surface until a downstream user without faker reported it.
        $schema = ['type' => 'string', 'minLength' => 4, 'maxLength' => 12];

        for ($i = 0; $i < 4; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsString($value);
            $this->assertGreaterThanOrEqual(4, strlen($value));
            $this->assertLessThanOrEqual(12, strlen($value));
        }
    }

    #[Test]
    public function fallback_integers_stay_within_range(): void
    {
        $schema = ['type' => 'integer', 'minimum' => 5, 'maximum' => 10];

        for ($i = 0; $i < 12; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(5, $value);
            $this->assertLessThanOrEqual(10, $value);
        }
    }

    #[Test]
    public function fallback_numbers_stay_within_tight_range(): void
    {
        // Regression for the original `+ ($iteration % 100) / 10.0` that
        // could return up to 9.9 above $min, blowing past tight maximums.
        $schema = ['type' => 'number', 'minimum' => 0.5, 'maximum' => 0.6];

        for ($i = 0; $i < 12; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsFloat($value);
            $this->assertGreaterThanOrEqual(0.5, $value);
            $this->assertLessThanOrEqual(0.6, $value);
        }
    }

    #[Test]
    public function maximum_only_integer_anchors_relative_to_max(): void
    {
        // Regression for the static `$min = 1` default that produced 1
        // even when the spec required `<= 0`.
        $schema = ['type' => 'integer', 'maximum' => 0];

        for ($i = 0; $i < 12; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsInt($value);
            $this->assertLessThanOrEqual(0, $value);
        }
    }

    #[Test]
    public function maximum_only_number_anchors_relative_to_max(): void
    {
        $schema = ['type' => 'number', 'maximum' => -1.0];

        for ($i = 0; $i < 12; $i++) {
            $value = SchemaDataGenerator::generateOne($schema, faker: null, iteration: $i);
            $this->assertIsFloat($value);
            $this->assertLessThanOrEqual(-1.0, $value);
        }
    }

    #[Test]
    public function faker_missing_for_format_emits_warning_once(): void
    {
        SchemaDataGenerator::resetWarningStateForTesting();
        $schema = ['type' => 'string', 'format' => 'email'];

        $captured = [];
        $previous = set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            SchemaDataGenerator::generateOne($schema, faker: null, iteration: 0);
            SchemaDataGenerator::generateOne($schema, faker: null, iteration: 1);
            SchemaDataGenerator::generateOne($schema, faker: null, iteration: 2);
        } finally {
            restore_error_handler();
            // Be polite to other tests — clear state we just exercised.
            SchemaDataGenerator::resetWarningStateForTesting();
            // Suppress unused-variable analyzer: the previous handler reference
            // is here purely so set_error_handler returns a callable we can
            // restore. unused: $previous
            unset($previous);
        }

        $this->assertCount(1, $captured, 'warning should fire exactly once per format per process');
        $this->assertStringContainsString("'email'", $captured[0]);
        $this->assertStringContainsString('fakerphp/faker', $captured[0]);
    }

    #[Test]
    public function generates_from_each_oneof_branch(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'object'],
                ['type' => 'integer'],
            ],
        ];

        $this->assertIsArray(SchemaDataGenerator::generateOne($schema, faker: null, iteration: 0));
        $this->assertIsInt(SchemaDataGenerator::generateOne($schema, faker: null, iteration: 1));
    }

    #[Test]
    public function emits_valid_string_length_boundaries_using_unicode_code_points(): void
    {
        $values = SchemaDataGenerator::generate(['type' => 'string', 'minLength' => 1, 'maxLength' => 3], 3, seed: 7);

        foreach ($values as $value) {
            $this->assertTrue(SchemaValueValidator::isValid($value, ['type' => 'string', 'minLength' => 1, 'maxLength' => 3]));
        }
        $this->assertSame(1, strlen($values[0]));
        $this->assertSame(3, strlen($values[1]));
        $this->assertSame('é', SchemaDataGenerator::generateOne(['type' => 'string', 'pattern' => '^é$'], null, 0));
    }

    #[Test]
    public function pattern_generation_preserves_pattern_after_minimum_length_adjustment(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^a+$', 'minLength' => 2, 'maxLength' => 5];

        $values = SchemaDataGenerator::generate($schema, 3, seed: 1);

        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^a+$/', $value);
            $this->assertGreaterThanOrEqual(2, strlen($value));
            $this->assertLessThanOrEqual(5, strlen($value));
        }
    }

    #[Test]
    public function generates_simple_anchored_patterns_with_fixed_quantifiers(): void
    {
        $schemas = [
            ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
            ['type' => 'string', 'pattern' => '^\\d{6}$'],
        ];

        $this->assertSame(str_repeat('a', 64), SchemaDataGenerator::generate($schemas[0], 1, seed: 1)[0]);
        $this->assertSame('AA', SchemaDataGenerator::generate($schemas[1], 1, seed: 1)[0]);
        $this->assertSame('000000', SchemaDataGenerator::generate($schemas[2], 1, seed: 1)[0]);
    }

    #[Test]
    public function generates_common_hostname_patterns_with_a_fixed_domain_suffix(): void
    {
        $schema = [
            'type' => 'string',
            'maxLength' => 253,
            'pattern' => '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+studio\\.design$',
        ];

        $first = SchemaDataGenerator::generate($schema, 3, seed: 1);
        $second = SchemaDataGenerator::generate($schema, 3, seed: 999);

        $this->assertSame(['a.studio.design', 'a.studio.design', 'a.studio.design'], $first);
        $this->assertSame($first, $second);
        foreach ($first as $value) {
            $this->assertTrue(SchemaValueValidator::isValid($value, $schema));
        }
    }

    #[Test]
    public function hostname_pattern_generation_adjusts_length_one_character_at_a_time(): void
    {
        $schema = [
            'type' => 'string',
            'minLength' => 16,
            'maxLength' => 16,
            'pattern' => '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+studio\\.design$',
        ];

        $value = SchemaDataGenerator::generate($schema, 1, seed: 1)[0];

        $this->assertSame('aa.studio.design', $value);
        $this->assertTrue(SchemaValueValidator::isValid($value, $schema));
    }

    #[Test]
    public function emits_numeric_boundaries_that_honor_exclusive_and_multiple_of(): void
    {
        $schema = [
            'type' => 'integer',
            'exclusiveMinimum' => 0,
            'exclusiveMaximum' => 10,
            'multipleOf' => 2,
        ];

        $values = SchemaDataGenerator::generate($schema, 3, seed: 1);
        $this->assertSame(2, $values[0]);
        $this->assertSame(8, $values[1]);
        $this->assertSame(0, $values[2] % 2);
    }

    #[Test]
    public function integer_exclusive_bounds_round_in_the_contract_safe_direction(): void
    {
        $values = SchemaDataGenerator::generate([
            'type' => 'integer',
            'exclusiveMinimum' => 0.5,
            'exclusiveMaximum' => 10.5,
        ], 2, seed: 1);

        $this->assertSame([1, 10], $values);
    }

    #[Test]
    public function integer_inclusive_fractional_bounds_round_in_the_contract_safe_direction(): void
    {
        $minimumValues = SchemaDataGenerator::generate([
            'type' => 'integer',
            'minimum' => 1.5,
        ], 2, seed: 1);
        $maximumValues = SchemaDataGenerator::generate([
            'type' => 'integer',
            'maximum' => -1.5,
        ], 2, seed: 1);

        $this->assertSame(2, $minimumValues[0]);
        $this->assertGreaterThanOrEqual(2, $minimumValues[1]);
        $this->assertSame(-2, $maximumValues[1]);
        $this->assertLessThanOrEqual(-2, $maximumValues[0]);
    }

    #[Test]
    public function integer_generation_preserves_fractional_multiple_of_semantics(): void
    {
        $onePointFive = SchemaDataGenerator::generate([
            'type' => 'integer',
            'multipleOf' => 1.5,
        ], 3, seed: 1);
        $twoPointFive = SchemaDataGenerator::generate([
            'type' => 'integer',
            'multipleOf' => 2.5,
        ], 3, seed: 1);

        foreach ($onePointFive as $value) {
            $this->assertIsInt($value);
            $this->assertSame(0.0, fmod((float) $value, 1.5));
        }
        foreach ($twoPointFive as $value) {
            $this->assertIsInt($value);
            $this->assertSame(0.0, fmod((float) $value, 2.5));
        }
        $this->assertSame(3, $onePointFive[0]);
        $this->assertSame(5, $twoPointFive[0]);
    }

    #[Test]
    public function emits_array_and_object_size_boundaries(): void
    {
        $arrays = SchemaDataGenerator::generate([
            'type' => 'array',
            'minItems' => 2,
            'maxItems' => 4,
            'items' => ['type' => 'integer'],
        ], 3, seed: 1);
        $this->assertCount(2, $arrays[0]);
        $this->assertCount(4, $arrays[1]);

        $objects = SchemaDataGenerator::generate([
            'type' => 'object',
            'minProperties' => 2,
            'maxProperties' => 2,
        ], 1, seed: 1);
        $this->assertCount(2, $objects[0]);
    }

    #[Test]
    public function generates_allof_not_and_conditional_schemas(): void
    {
        $allOf = [
            'allOf' => [
                ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
                ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
            ],
        ];
        $value = SchemaDataGenerator::generate($allOf, 1, seed: 1)[0];
        $this->assertIsArray($value);
        $this->assertArrayHasKey('id', $value);
        $this->assertArrayHasKey('name', $value);

        $this->assertIsNotString(SchemaDataGenerator::generate(['not' => ['type' => 'string']], 1, seed: 1)[0]);

        $conditional = [
            'type' => 'object',
            'if' => ['required' => ['kind'], 'properties' => ['kind' => ['const' => 'a']]],
            'then' => ['required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
            'else' => ['required' => ['b'], 'properties' => ['b' => ['type' => 'integer']]],
        ];
        foreach (SchemaDataGenerator::generate($conditional, 2, seed: 1) as $conditionalValue) {
            $this->assertTrue(SchemaValueValidator::isValid($conditionalValue, $conditional));
        }
    }

    #[Test]
    public function allof_recursively_merges_constraints_on_the_same_property(): void
    {
        $schema = [
            'allOf' => [
                [
                    'type' => 'object',
                    'required' => ['name', 'profile'],
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 2],
                        'profile' => [
                            'type' => 'object',
                            'required' => ['nickname'],
                            'properties' => [
                                'nickname' => ['type' => 'string', 'minLength' => 3],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'maxLength' => 5],
                        'profile' => [
                            'type' => 'object',
                            'properties' => [
                                'nickname' => ['type' => 'string', 'maxLength' => 6],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 3, seed: 1);

        foreach ($values as $value) {
            $this->assertGreaterThanOrEqual(2, strlen($value['name']));
            $this->assertLessThanOrEqual(5, strlen($value['name']));
            $this->assertGreaterThanOrEqual(3, strlen($value['profile']['nickname']));
            $this->assertLessThanOrEqual(6, strlen($value['profile']['nickname']));
        }
    }

    #[Test]
    public function allof_combines_multiple_of_constraints(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'integer', 'multipleOf' => 2],
                ['multipleOf' => 3],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 6, seed: 1);

        foreach ($values as $value) {
            $this->assertIsInt($value);
            $this->assertSame(0, $value % 6);
        }
    }

    #[Test]
    public function allof_combines_decimal_multiple_of_constraints(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'number', 'multipleOf' => 1.5],
                ['multipleOf' => 2.5],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 6, seed: 1);

        foreach ($values as $value) {
            $this->assertSame(0.0, fmod($value, 7.5));
        }
    }

    #[Test]
    public function allof_combines_array_size_boundaries(): void
    {
        $schema = [
            'allOf' => [
                [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 3,
                    'maxItems' => 8,
                ],
                ['minItems' => 1, 'maxItems' => 5],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 3, seed: 1);

        $this->assertCount(3, $values[0]);
        $this->assertCount(5, $values[1]);
        foreach ($values as $value) {
            $this->assertGreaterThanOrEqual(3, count($value));
            $this->assertLessThanOrEqual(5, count($value));
        }
    }

    #[Test]
    public function allof_combines_object_size_boundaries(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'object', 'minProperties' => 3, 'maxProperties' => 8],
                ['minProperties' => 1, 'maxProperties' => 5],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 3, seed: 1);

        $this->assertCount(3, $values[0]);
        $this->assertCount(5, $values[1]);
        foreach ($values as $value) {
            $this->assertIsArray($value);
            $this->assertGreaterThanOrEqual(3, count($value));
            $this->assertLessThanOrEqual(5, count($value));
        }
    }

    #[Test]
    public function invalid_mutations_each_name_and_violate_the_target_constraint(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4, 'enum' => ['ab', 'cd']],
            ],
        ];

        $mutations = SchemaMutationGenerator::generate($schema, 20, SchemaDataGenerator::createFaker(1));
        $keywords = [];
        foreach ($mutations as $mutation) {
            $this->assertFalse(SchemaValueValidator::isValid($mutation->value, $schema));
            $keywords[] = $mutation->keyword;
        }

        $this->assertContains('required', $keywords);
        $this->assertContains('additionalProperties', $keywords);
        $this->assertContains('enum', $keywords);
    }

    #[Test]
    public function mutations_are_omitted_when_they_also_violate_a_sibling_constraint(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'required' => ['a'],
            'minProperties' => 1,
            'additionalProperties' => false,
        ];

        $mutations = SchemaMutationGenerator::generate($schema, 100, SchemaDataGenerator::createFaker(1));
        $keywords = array_map(static fn($mutation): string => $mutation->keyword, $mutations);

        $this->assertNotContains('required', $keywords);
        $this->assertNotContains('minProperties', $keywords);
        $this->assertContains('additionalProperties', $keywords);
        $this->assertContains('type', $keywords);
    }

    #[Test]
    public function required_only_mutation_uses_a_json_object_and_is_single_constraint(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'required' => ['a'],
            'additionalProperties' => false,
        ];
        $relaxed = $schema;
        unset($relaxed['required']);

        $mutations = SchemaMutationGenerator::generate($schema, 100, SchemaDataGenerator::createFaker(1));
        $required = array_values(array_filter(
            $mutations,
            static fn($mutation): bool => $mutation->keyword === 'required',
        ));

        $this->assertCount(1, $required);
        $this->assertInstanceOf(stdClass::class, $required[0]->value);
        $this->assertFalse(SchemaValueValidator::isValid($required[0]->value, $schema));
        $this->assertTrue(SchemaValueValidator::isValid($required[0]->value, $relaxed));
    }

    #[Test]
    public function supported_constraint_families_have_targeted_invalid_mutations(): void
    {
        $schemas = [
            'type' => ['type' => 'string'],
            'const' => ['const' => 'fixed'],
            'enum' => ['type' => 'string', 'enum' => ['a', 'b']],
            'minLength' => ['type' => 'string', 'minLength' => 2],
            'maxLength' => ['type' => 'string', 'maxLength' => 3],
            'pattern' => ['type' => 'string', 'pattern' => '^a+$'],
            'format' => ['type' => 'string', 'format' => 'email'],
            'minimum' => ['type' => 'integer', 'minimum' => 2],
            'maximum' => ['type' => 'integer', 'maximum' => 5],
            'exclusiveMinimum' => ['type' => 'integer', 'exclusiveMinimum' => 0],
            'exclusiveMaximum' => ['type' => 'integer', 'exclusiveMaximum' => 10],
            'multipleOf' => ['type' => 'integer', 'multipleOf' => 2],
            'minItems' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'integer']],
            'maxItems' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'integer']],
            'uniqueItems' => ['type' => 'array', 'minItems' => 2, 'uniqueItems' => true, 'items' => ['type' => 'integer']],
            'minProperties' => ['type' => 'object', 'minProperties' => 2],
            'maxProperties' => ['type' => 'object', 'maxProperties' => 1],
            'oneOf' => ['oneOf' => [['type' => 'integer'], ['type' => 'object']]],
            'anyOf' => ['anyOf' => [['type' => 'integer'], ['type' => 'object']]],
            'allOf' => ['allOf' => [['type' => 'string'], ['minLength' => 2]]],
            'not' => ['not' => ['type' => 'string']],
        ];

        foreach ($schemas as $keyword => $schema) {
            $mutations = SchemaMutationGenerator::generate($schema, 100, SchemaDataGenerator::createFaker(1));
            $keywords = array_map(static fn($mutation): string => $mutation->keyword, $mutations);
            $this->assertContains($keyword, $keywords, "missing invalid strategy for {$keyword}");
        }
    }

    #[Test]
    public function integer_multiple_of_mutation_preserves_integer_type_and_other_constraints(): void
    {
        $schema = [
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 10,
            'multipleOf' => 2,
        ];
        $withoutMultipleOf = $schema;
        unset($withoutMultipleOf['multipleOf']);

        $mutations = SchemaMutationGenerator::generate($schema, 100, SchemaDataGenerator::createFaker(1));
        $multipleOf = array_values(array_filter(
            $mutations,
            static fn($mutation): bool => $mutation->keyword === 'multipleOf',
        ));

        $this->assertCount(1, $multipleOf);
        $this->assertIsInt($multipleOf[0]->value);
        $this->assertTrue(SchemaValueValidator::isValid($multipleOf[0]->value, $withoutMultipleOf));
        $this->assertFalse(SchemaValueValidator::isValid($multipleOf[0]->value, $schema));
    }

    #[Test]
    public function integer_multiple_of_one_omits_impossible_single_constraint_mutation(): void
    {
        $mutations = SchemaMutationGenerator::generate(
            ['type' => 'integer', 'multipleOf' => 1],
            100,
            SchemaDataGenerator::createFaker(1),
        );
        $keywords = array_map(static fn($mutation): string => $mutation->keyword, $mutations);

        $this->assertNotContains('multipleOf', $keywords);
    }

    #[Test]
    public function generates_discriminator_selected_composition_branches(): void
    {
        $cat = [
            'type' => 'object',
            'required' => ['kind', 'meows'],
            'properties' => ['kind' => ['const' => 'cat'], 'meows' => ['type' => 'boolean']],
        ];
        $dog = [
            'type' => 'object',
            'required' => ['kind', 'barks'],
            'properties' => ['kind' => ['const' => 'dog'], 'barks' => ['type' => 'boolean']],
        ];
        $root = ['components' => ['schemas' => ['Cat' => $cat, 'Dog' => $dog]]];
        $converted = OpenApiSchemaConverter::convert([
            'oneOf' => [$cat, $dog],
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => ['cat' => 'Cat', 'dog' => 'Dog'],
            ],
        ], OpenApiVersion::V3_1, SchemaContext::Request, new DiscriminatorContext($root, true));

        $values = SchemaDataGenerator::generate($converted, 2, seed: 1);

        $this->assertSame('cat', $values[0]['kind']);
        $this->assertArrayHasKey('meows', $values[0]);
        $this->assertSame('dog', $values[1]['kind']);
        $this->assertArrayHasKey('barks', $values[1]);
    }

    #[Test]
    public function unsupported_pattern_reports_an_explicit_generation_limitation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supported synthesis subset');

        SchemaDataGenerator::generate(['type' => 'string', 'pattern' => '^impossible-literal$'], 1, seed: 1);
    }

    #[Test]
    public function native_const_value_is_generated_exactly(): void
    {
        $this->assertSame(
            ['fixed' => true],
            SchemaDataGenerator::generateOne(['const' => ['fixed' => true]], faker: null, iteration: 0),
        );
    }

    #[Test]
    public function native_prefix_items_generate_a_valid_tuple(): void
    {
        $value = SchemaDataGenerator::generateOne([
            'type' => 'array',
            'prefixItems' => [
                ['const' => 'fixed'],
                ['type' => 'integer'],
            ],
            'items' => false,
        ], faker: null, iteration: 1);

        $this->assertSame('fixed', $value[0]);
        $this->assertIsInt($value[1]);
    }

    #[Test]
    public function native_prefix_items_honor_array_boundaries(): void
    {
        $schema = [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 2,
            'prefixItems' => [
                ['const' => 'fixed'],
                ['type' => 'integer'],
            ],
            'items' => false,
        ];

        $values = SchemaDataGenerator::generate($schema, 2, seed: 1);

        $this->assertCount(1, $values[0]);
        $this->assertCount(2, $values[1]);
    }

    #[Test]
    public function prefix_items_do_not_imply_minimum_length_and_respect_max_items(): void
    {
        $schema = [
            'type' => 'array',
            'maxItems' => 1,
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
                ['type' => 'boolean'],
            ],
        ];

        $values = SchemaDataGenerator::generate($schema, 6, seed: 1);

        $this->assertSame([], $values[0]);
        foreach ($values as $value) {
            $this->assertLessThanOrEqual(1, count($value));
        }
    }
}
