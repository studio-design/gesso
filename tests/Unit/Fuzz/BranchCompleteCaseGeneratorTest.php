<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\BranchCompleteCaseGenerator;
use Studio\Gesso\Fuzz\PlannedSchemaCase;
use Studio\Gesso\Fuzz\SchemaValueValidator;

use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function range;

class BranchCompleteCaseGeneratorTest extends TestCase
{
    /**
     * The studio-auth#1520 incident shape: a primitive-only inline `oneOf`
     * (`string | string[]`) inside an optional property. Rotation with an
     * unlucky case count misses branches; branch-complete generation must
     * cover both `oneOf` branches and both presence states.
     *
     * @var array<string, mixed>
     */
    private const INCIDENT_SHAPE = [
        'type' => 'object',
        'required' => ['active'],
        'properties' => [
            'active' => ['type' => 'boolean'],
            'aud' => [
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ],
    ];

    #[Test]
    public function covers_every_branch_of_an_optional_primitive_only_one_of(): void
    {
        $cases = BranchCompleteCaseGenerator::generate(self::INCIDENT_SHAPE, seed: 1);

        $sawString = false;
        $sawArray = false;
        $sawOmitted = false;
        foreach ($cases as $case) {
            $this->assertIsArray($case->value);
            if (!isset($case->value['aud'])) {
                $sawOmitted = true;

                continue;
            }
            $sawString = $sawString || is_string($case->value['aud']);
            $sawArray = $sawArray || is_array($case->value['aud']);
        }

        $this->assertTrue($sawString, 'no case pinned the string branch of aud');
        $this->assertTrue($sawArray, 'no case pinned the array branch of aud');
        $this->assertTrue($sawOmitted, 'no case omitted the optional aud property');
    }

    #[Test]
    public function derives_one_case_per_choice_point_branch_pair_plus_extras(): void
    {
        // Choice points: aud presence (2 branches) + aud oneOf (2 branches).
        $this->assertCount(4, BranchCompleteCaseGenerator::generate(self::INCIDENT_SHAPE, seed: 1));
        $this->assertCount(6, BranchCompleteCaseGenerator::generate(self::INCIDENT_SHAPE, seed: 1, extraCases: 2));
    }

    #[Test]
    public function pinned_cases_carry_their_target_choice_point(): void
    {
        $cases = BranchCompleteCaseGenerator::generate(self::INCIDENT_SHAPE, seed: 1, extraCases: 1);

        $targets = [];
        foreach ($cases as $case) {
            if ($case->plan->targetPointer !== null) {
                $targets[] = $case->plan->targetPointer . '@' . $case->plan->targetBranch;
            }
        }

        $this->assertSame([
            '/properties/aud@0',
            '/properties/aud@1',
            '/properties/aud/oneOf@0',
            '/properties/aud/oneOf@1',
        ], $targets);
        $this->assertNull($cases[4]->plan->targetPointer);
    }

    #[Test]
    public function every_generated_case_satisfies_the_converted_schema(): void
    {
        $cases = BranchCompleteCaseGenerator::generate(self::INCIDENT_SHAPE, seed: 1, extraCases: 2);

        foreach ($cases as $case) {
            $this->assertTrue(SchemaValueValidator::isValid($case->value, self::INCIDENT_SHAPE));
        }
    }

    #[Test]
    public function covers_both_nullable_branches(): void
    {
        $cases = BranchCompleteCaseGenerator::generate(['type' => ['string', 'null']], seed: 1);

        $values = array_map(static fn(PlannedSchemaCase $case): mixed => $case->value, $cases);

        $this->assertContains(null, $values);
        $sawString = false;
        foreach ($values as $value) {
            $sawString = $sawString || is_string($value);
        }
        $this->assertTrue($sawString, 'no case pinned the non-null branch');
    }

    #[Test]
    public function is_deterministic_for_a_fixed_schema_and_seed(): void
    {
        $first = BranchCompleteCaseGenerator::generate(self::INCIDENT_SHAPE, seed: 7, extraCases: 3);
        $second = BranchCompleteCaseGenerator::generate(self::INCIDENT_SHAPE, seed: 7, extraCases: 3);

        $this->assertSame(
            json_encode(array_map(static fn(PlannedSchemaCase $case): mixed => $case->value, $first)),
            json_encode(array_map(static fn(PlannedSchemaCase $case): mixed => $case->value, $second)),
        );
    }

    #[Test]
    public function generates_a_single_case_for_a_schema_without_choice_points(): void
    {
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'integer']],
        ], seed: 1);

        $this->assertCount(1, $cases);
        $this->assertNull($cases[0]->plan->targetPointer);
    }

    #[Test]
    public function rejects_negative_extra_cases(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchCompleteCaseGenerator::generate(self::INCIDENT_SHAPE, seed: 1, extraCases: -1);
    }

    #[Test]
    public function propagates_loud_enumeration_failures(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enumeration');

        BranchCompleteCaseGenerator::generate([
            'oneOf' => [
                ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                ['type' => 'boolean'],
            ],
        ], seed: 1);
    }

    #[Test]
    public function covers_the_else_branch_of_an_all_of_conditional(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string']],
            'allOf' => [
                [
                    'if' => ['properties' => ['kind' => ['const' => 'a']], 'required' => ['kind']],
                    'then' => ['properties' => ['kind' => ['const' => 'a']]],
                    'else' => ['properties' => ['kind' => ['const' => 'b']]],
                ],
            ],
        ];

        $kinds = [];
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $kinds[$case->value['kind']] = true;
        }

        $this->assertArrayHasKey('a', $kinds, 'no case took the then branch');
        $this->assertArrayHasKey('b', $kinds, 'no case took the else branch');
    }

    #[Test]
    public function covers_every_subtype_of_a_discriminator_style_conditional_all_of(): void
    {
        // Discriminator lowering produces this shape: a closed enum plus one
        // if/then pair per subtype. The none-match branch is unsatisfiable
        // here; its probe case must be dropped, not fail the whole run.
        $schema = [
            'type' => 'object',
            'required' => ['petType'],
            'properties' => ['petType' => ['type' => 'string', 'enum' => ['cat', 'dog']]],
            'allOf' => [
                [
                    'if' => ['properties' => ['petType' => ['const' => 'cat']], 'required' => ['petType']],
                    'then' => ['required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                ],
                [
                    'if' => ['properties' => ['petType' => ['const' => 'dog']], 'required' => ['petType']],
                    'then' => ['required' => ['bark'], 'properties' => ['bark' => ['type' => 'string']]],
                ],
            ],
        ];

        $cases = BranchCompleteCaseGenerator::generate($schema, seed: 1);

        $byType = [];
        foreach ($cases as $case) {
            $this->assertIsArray($case->value);
            $byType[$case->value['petType']] = $case->value;
        }

        $this->assertCount(2, $cases);
        $this->assertArrayHasKey('meow', $byType['cat']);
        $this->assertArrayHasKey('bark', $byType['dog']);
    }

    #[Test]
    public function covers_overlapping_conditionals_without_forcing_exclusivity(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['flag'],
            'properties' => ['flag' => ['type' => 'boolean']],
            'allOf' => [
                [
                    'if' => ['properties' => ['flag' => ['const' => true]], 'required' => ['flag']],
                    'then' => ['required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
                ],
                [
                    'if' => ['properties' => ['flag' => ['const' => true]], 'required' => ['flag']],
                    'then' => ['required' => ['b'], 'properties' => ['b' => ['type' => 'string']]],
                ],
            ],
        ];

        $sawBothThens = false;
        $sawNoneMatch = false;
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $sawBothThens = $sawBothThens ||
                (isset($case->value['a'], $case->value['b']));
            $sawNoneMatch = $sawNoneMatch || $case->value['flag'] === false;
        }

        $this->assertTrue($sawBothThens, 'no case satisfied the jointly-firing conditionals');
        $this->assertTrue($sawNoneMatch, 'no case exercised the none-match state');
    }

    #[Test]
    public function keeps_a_reachable_none_match_probe_despite_rotation_misalignment(): void
    {
        // The probe case's iteration rotates the enum onto the excluded
        // discriminator value; one failed generation must not be read as
        // "the none-match state is unreachable".
        $schema = [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string', 'enum' => ['other', 'cat']]],
            'allOf' => [
                [
                    'if' => ['properties' => ['kind' => ['const' => 'cat']], 'required' => ['kind']],
                    'then' => ['required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                ],
            ],
        ];

        $kinds = [];
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $kinds[$case->value['kind']] = true;
        }

        $this->assertArrayHasKey('other', $kinds, 'the reachable none-match probe was dropped');
    }

    #[Test]
    public function covers_a_none_match_probe_behind_a_long_enum(): void
    {
        // Ten enum values, nine conditionals, and a preceding choice point
        // shifting the probe's iteration: blind retry windows cannot reach
        // the one admissible value, so the excluded set must be filtered out
        // of the enum domain deterministically instead.
        $conditionals = [];
        $excluded = [];
        foreach (range(0, 8) as $i) {
            $conditionals[] = [
                'if' => ['properties' => ['kind' => ['const' => "v{$i}"]], 'required' => ['kind']],
                'then' => ['required' => ["p{$i}"], 'properties' => ["p{$i}" => ['type' => 'string']]],
            ];
            $excluded[] = "v{$i}";
        }
        $schema = [
            'type' => 'object',
            'required' => ['kind'],
            'oneOf' => [['type' => 'object']],
            'properties' => ['kind' => ['type' => 'string', 'enum' => [...$excluded, 'other']]],
            'allOf' => $conditionals,
        ];

        $kinds = [];
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $kinds[$case->value['kind']] = true;
        }

        $this->assertArrayHasKey('other', $kinds, 'the reachable none-match state behind a long enum was dropped');
    }

    #[Test]
    public function covers_choice_points_inside_a_suppressed_else(): void
    {
        // Overlapping ifs: whenever conditional 0 fires, conditional 1 fires
        // too, so its else — and the optional fallback inside it — is only
        // realizable in the none-match state. The fallback presence branch
        // must be generated from that stable context.
        $schema = [
            'type' => 'object',
            'required' => ['flag'],
            'properties' => ['flag' => ['type' => 'boolean']],
            'allOf' => [
                [
                    'if' => ['properties' => ['flag' => ['const' => true]], 'required' => ['flag']],
                    'then' => ['required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
                ],
                [
                    'if' => ['properties' => ['flag' => ['const' => true]], 'required' => ['flag']],
                    'then' => ['required' => ['b'], 'properties' => ['b' => ['type' => 'string']]],
                    'else' => ['properties' => ['fallback' => ['type' => 'string']]],
                ],
            ],
        ];

        $sawFallback = false;
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $sawFallback = $sawFallback || isset($case->value['fallback']);
        }

        $this->assertTrue($sawFallback, 'no case realized the optional property inside the suppressed else');
    }

    #[Test]
    public function preserves_an_existing_property_not_when_suppressing_conditionals(): void
    {
        // The property already excludes `forbidden`; suppressing the cat
        // conditional must add to that exclusion, not replace it — otherwise
        // the none-match probe generates `forbidden`, fails validation, and
        // is dropped even though `other` is admissible.
        $schema = [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => [
                'type' => 'string',
                'enum' => ['other', 'forbidden', 'cat'],
                'not' => ['const' => 'forbidden'],
            ]],
            'allOf' => [
                [
                    'if' => ['properties' => ['kind' => ['const' => 'cat']], 'required' => ['kind']],
                    'then' => ['required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                ],
            ],
        ];

        $kinds = [];
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $kinds[$case->value['kind']] = true;
        }

        $this->assertArrayHasKey('other', $kinds, 'the none-match state lost the pre-existing not exclusion');
        $this->assertArrayNotHasKey('forbidden', $kinds);
    }

    #[Test]
    public function preserves_a_base_not_across_an_else_merge(): void
    {
        // The suppressed conditional's else carries its own kind.not; merging
        // it must not displace the base exclusion of `forbidden`. Otherwise
        // the none-match probe generates `forbidden`, fails validation, and
        // is dropped even though `other` is admissible.
        $schema = [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => [
                'type' => 'string',
                'enum' => ['other', 'forbidden', 'elseForbidden', 'cat'],
                'not' => ['const' => 'forbidden'],
            ]],
            'allOf' => [
                [
                    'if' => ['properties' => ['kind' => ['const' => 'cat']], 'required' => ['kind']],
                    'then' => ['required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                    'else' => ['properties' => ['kind' => ['not' => ['const' => 'elseForbidden']]]],
                ],
            ],
        ];

        $kinds = [];
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $kinds[$case->value['kind']] = true;
        }

        $this->assertArrayHasKey('other', $kinds, 'the none-match state lost the base not across the else merge');
        $this->assertArrayNotHasKey('forbidden', $kinds);
        $this->assertArrayNotHasKey('elseForbidden', $kinds);
    }

    #[Test]
    public function preserves_a_base_not_across_a_boolean_not_merge(): void
    {
        // `not: false` in the else is a semantic no-op (2020-12 boolean
        // schema); it must not displace the base exclusion of `forbidden`.
        $schema = [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => [
                'type' => 'string',
                'enum' => ['other', 'forbidden', 'cat'],
                'not' => ['const' => 'forbidden'],
            ]],
            'allOf' => [
                [
                    'if' => ['properties' => ['kind' => ['const' => 'cat']], 'required' => ['kind']],
                    'then' => ['required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                    'else' => ['properties' => ['kind' => ['not' => false]]],
                ],
            ],
        ];

        $kinds = [];
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $kinds[$case->value['kind']] = true;
        }

        $this->assertArrayHasKey('other', $kinds, 'the none-match state lost the base not to a boolean no-op');
        $this->assertArrayNotHasKey('forbidden', $kinds);
    }

    #[Test]
    public function generates_valid_values_for_a_one_of_with_a_boolean_true_branch(): void
    {
        // OpenAPI 3.1 admits boolean Schema Objects. With a `true` sibling,
        // no other oneOf branch can ever be the sole match, so the valid
        // values are exactly those matching nothing else — here, any string
        // except `x`.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'oneOf' => [true, ['const' => 'x']],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotSame('x', $case->value);
        }
    }

    #[Test]
    public function applies_a_boolean_true_if_and_covers_its_then_choice_points(): void
    {
        // `if: true` makes the `then` unconditional; ignoring it generates an
        // empty object that fails the self-check, and the choice point inside
        // the `then` would never be covered.
        $schema = [
            'type' => 'object',
            'if' => true,
            'then' => [
                'required' => ['choice'],
                'properties' => ['choice' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]],
            ],
        ];

        $sawString = false;
        $sawInteger = false;
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $this->assertArrayHasKey('choice', $case->value);
            $sawString = $sawString || is_string($case->value['choice']);
            $sawInteger = $sawInteger || is_int($case->value['choice']);
        }

        $this->assertTrue($sawString, 'no case pinned the string branch under the unconditional then');
        $this->assertTrue($sawInteger, 'no case pinned the integer branch under the unconditional then');
    }

    #[Test]
    public function covers_the_none_match_state_when_it_is_reachable(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['petType'],
            'properties' => ['petType' => ['type' => 'string']],
            'allOf' => [
                [
                    'if' => ['properties' => ['petType' => ['const' => 'cat']], 'required' => ['petType']],
                    'then' => ['required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]],
                ],
            ],
        ];

        $sawNonCat = false;
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $sawNonCat = $sawNonCat || $case->value['petType'] !== 'cat';
        }

        $this->assertTrue($sawNonCat, 'no case exercised the none-match state of the conditional');
    }

    #[Test]
    public function covers_same_pointer_choice_points_with_different_content_per_context(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['v'],
            'anyOf' => [
                ['properties' => ['v' => ['oneOf' => [['const' => 'x'], ['const' => 'y']]]]],
                ['properties' => ['v' => ['oneOf' => [['const' => 1], ['const' => 2]]]]],
            ],
        ];

        $values = [];
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $values[json_encode($case->value['v'])] = true;
        }

        foreach (['"x"', '"y"', '1', '2'] as $expected) {
            $this->assertArrayHasKey($expected, $values, "branch producing {$expected} was never generated");
        }
    }

    #[Test]
    public function covers_branches_that_only_exist_under_a_later_composition_branch(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['v'],
            'anyOf' => [
                ['properties' => ['v' => ['oneOf' => [['const' => 'x'], ['const' => 'y']]]]],
                ['properties' => ['v' => ['oneOf' => [['const' => 'x'], ['const' => 'y'], ['const' => 'z']]]]],
            ],
        ];

        $values = [];
        foreach (BranchCompleteCaseGenerator::generate($schema, seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $values[$case->value['v']] = true;
        }

        $this->assertArrayHasKey('z', $values, 'the third branch of the wider rediscovery was never generated');
    }

    #[Test]
    public function covers_a_choice_point_inside_an_empty_by_default_array(): void
    {
        // The pinned case must force at least one item so the branch under
        // `items` is actually exercised even though minItems allows [].
        $schema = [
            'type' => 'array',
            'minItems' => 0,
            'items' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
        ];

        $cases = BranchCompleteCaseGenerator::generate($schema, seed: 1);

        $sawString = false;
        $sawInteger = false;
        foreach ($cases as $case) {
            $this->assertIsArray($case->value);
            foreach ($case->value as $item) {
                $sawString = $sawString || is_string($item);
                $sawInteger = $sawInteger || is_int($item);
            }
        }

        $this->assertTrue($sawString, 'no case exercised the string items branch');
        $this->assertTrue($sawInteger, 'no case exercised the integer items branch');
    }
}
