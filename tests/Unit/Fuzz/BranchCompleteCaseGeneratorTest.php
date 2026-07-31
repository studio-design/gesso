<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\BranchCompleteCaseGenerator;
use Studio\Gesso\Fuzz\FuzzGenerationException;
use Studio\Gesso\Fuzz\PlannedSchemaCase;
use Studio\Gesso\Fuzz\SchemaValueValidator;

use function array_key_exists;
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
    public function skips_an_unsatisfiable_boolean_then_and_covers_the_else(): void
    {
        // `then: false` makes the if-holds side unsatisfiable (2020-12
        // boolean schema); only the else branch is generatable. Pinning the
        // then side would crash the run before the reachable else case.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'if' => ['const' => 'x'],
            'then' => false,
            'else' => ['const' => 'y'],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertSame('y', $case->value);
        }
    }

    #[Test]
    public function covers_presence_of_a_boolean_true_property(): void
    {
        // `properties: {x: true}` admits any value for x; both presence
        // states are reachable and must each appear in a case.
        $sawPresent = false;
        $sawOmitted = false;
        foreach (BranchCompleteCaseGenerator::generate([
            'type' => 'object',
            'properties' => ['x' => true],
        ], seed: 1) as $case) {
            $this->assertIsArray($case->value);
            if (array_key_exists('x', $case->value)) {
                $sawPresent = true;
            } else {
                $sawOmitted = true;
            }
        }

        $this->assertTrue($sawPresent, 'no case included the boolean-true property');
        $this->assertTrue($sawOmitted, 'no case omitted the boolean-true property');
    }

    #[Test]
    public function never_includes_a_boolean_false_property(): void
    {
        // Nothing matches `false`, so presence is unreachable; the only
        // valid shape omits x.
        foreach (BranchCompleteCaseGenerator::generate([
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'integer'], 'x' => false],
        ], seed: 1) as $case) {
            $this->assertIsArray($case->value);
            $this->assertArrayNotHasKey('x', $case->value);
        }
    }

    #[Test]
    public function folds_a_conditional_with_a_false_then_into_permanent_suppression(): void
    {
        // `then: false` means the if side is unsatisfiable: the conditional
        // is not a choice at all — every valid value violates the if and
        // satisfies the else.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'allOf' => [['if' => ['const' => 'x'], 'then' => false, 'else' => ['const' => 'y']]],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertSame('y', $case->value);
        }
    }

    #[Test]
    public function folds_a_conditional_with_a_false_else_into_a_mandatory_condition(): void
    {
        // `else: false` means the if must always hold — again no choice.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'allOf' => [['if' => ['const' => 'a'], 'then' => true, 'else' => false]],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertSame('a', $case->value);
        }
    }

    #[Test]
    public function generates_items_for_a_boolean_true_items_schema(): void
    {
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'array',
            'minItems' => 1,
            'items' => true,
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertIsArray($case->value);
            $this->assertNotSame([], $case->value);
        }
    }

    #[Test]
    public function does_not_reach_past_a_boolean_false_prefix_item(): void
    {
        // prefixItems[0] is false, so any array with one or more elements is
        // invalid: the oneOf behind it is unreachable and must not produce a
        // forced-size plan.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'array',
            'prefixItems' => [false, ['oneOf' => [['const' => 'x'], ['const' => 'y']]]],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertSame([], $case->value);
        }
    }

    #[Test]
    public function drops_a_one_of_branch_made_unreachable_by_exclusivity(): void
    {
        // `x` matches both branches, so the const branch can never be the
        // sole match; its case is dropped, not a crash.
        $cases = BranchCompleteCaseGenerator::generate([
            'oneOf' => [['type' => 'string'], ['const' => 'x']],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotSame('x', $case->value);
        }
    }

    #[Test]
    public function drops_an_else_branch_made_unreachable_by_a_const(): void
    {
        $cases = BranchCompleteCaseGenerator::generate([
            'const' => 'x',
            'if' => ['const' => 'x'],
            'then' => true,
            'else' => ['const' => 'y'],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertSame('x', $case->value);
        }
    }

    #[Test]
    public function drops_an_omission_branch_made_unreachable_by_min_properties(): void
    {
        // minProperties: 1 with additionalProperties: false means the only
        // optional property can never be omitted.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'object',
            'minProperties' => 1,
            'additionalProperties' => false,
            'properties' => ['a' => ['type' => 'string']],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertIsArray($case->value);
            $this->assertArrayHasKey('a', $case->value);
        }
    }

    #[Test]
    public function drops_a_conditional_branch_made_unreachable_by_a_folded_suppression(): void
    {
        // The first conditional's then: false permanently forbids x; the
        // second conditional's satisfied side needs x and is unreachable.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'allOf' => [
                ['if' => ['const' => 'x'], 'then' => false],
                ['if' => ['const' => 'x'], 'then' => ['minLength' => 1]],
            ],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            $this->assertNotSame('x', $case->value);
        }
    }

    #[Test]
    public function stays_loud_when_the_schema_itself_is_unsatisfiable(): void
    {
        // Dropping unreachable-branch cases must not swallow a schema no
        // value can satisfy: the fallback case still fails loudly.
        $this->expectException(FuzzGenerationException::class);

        BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'oneOf' => [['const' => 'x'], ['const' => 'x']],
        ], seed: 1);
    }

    #[Test]
    public function generates_items_when_the_items_keyword_is_omitted(): void
    {
        // Omitted items is the empty schema (2020-12 §10.3.1.2): elements
        // are unconstrained, not forbidden.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'array',
            'minItems' => 1,
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertIsArray($case->value);
            $this->assertNotSame([], $case->value);
        }
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

    #[Test]
    public function covers_each_any_of_branch_constrained_by_an_all_of_enum(): void
    {
        // The allOf enum narrows each anyOf branch: branch 0 admits only
        // `b`, branch 1 only `c`. A whole-schema check alone cannot tell a
        // case that took branch 0 from one that merely passed via branch 1.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'anyOf' => [
                ['enum' => ['a', 'b']],
                ['const' => 'c'],
            ],
            'allOf' => [
                ['enum' => ['c', 'b']],
            ],
        ], seed: 1);

        $byBranch = [];
        foreach ($cases as $case) {
            if ($case->plan->targetPointer === '/anyOf') {
                $byBranch[$case->plan->targetBranch] = $case->value;
            }
        }

        $this->assertSame('b', $byBranch[0] ?? null, 'branch 0 never produced its only admissible value');
        $this->assertSame('c', $byBranch[1] ?? null);
    }

    #[Test]
    public function covers_each_any_of_branch_constrained_by_an_all_of_type(): void
    {
        // Both branches stay reachable under the allOf type union; each
        // pinned case must produce a value of its own branch's type.
        $cases = BranchCompleteCaseGenerator::generate([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
            'allOf' => [
                ['type' => ['string', 'integer']],
            ],
        ], seed: 1);

        $byBranch = [];
        foreach ($cases as $case) {
            if ($case->plan->targetPointer === '/anyOf') {
                $byBranch[$case->plan->targetBranch] = $case->value;
            }
        }

        $this->assertIsString($byBranch[0] ?? null);
        $this->assertIsInt($byBranch[1] ?? null, 'the integer branch was never actually taken');
    }

    #[Test]
    public function drops_an_any_of_case_that_cannot_take_its_target_branch(): void
    {
        // The allOf forces strings, so the integer branch is unreachable;
        // its case must be dropped, not kept mislabeled with a value that
        // only passes through the string branch.
        $cases = BranchCompleteCaseGenerator::generate([
            'anyOf' => [
                ['type' => 'integer'],
                ['type' => 'string'],
            ],
            'allOf' => [
                ['type' => 'string'],
            ],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertIsString($case->value);
            if ($case->plan->targetPointer === '/anyOf') {
                $this->assertSame(1, $case->plan->targetBranch, 'the unreachable integer branch was kept');
            }
        }
    }

    #[Test]
    public function drops_a_conditional_case_whose_value_matches_a_suppressed_if(): void
    {
        // The node const forces `x`, so the none-match state (no `if`
        // firing) is unreachable — but `x` is valid for the whole schema,
        // so only a per-branch check can tell the case was mislabeled.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'const' => 'x',
            'allOf' => [
                ['if' => ['const' => 'x'], 'then' => ['minLength' => 1]],
            ],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertSame('x', $case->value);
            if ($case->plan->targetPointer === '/allOf') {
                $this->assertSame(0, $case->plan->targetBranch, 'the unreachable none-match state was kept');
            }
        }
    }

    #[Test]
    public function drops_an_else_case_whose_value_satisfies_the_if(): void
    {
        // The node const forces the `if` to fire, so the else side is
        // unreachable — yet its generated value `x` passes the whole
        // schema through the then side.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'string',
            'const' => 'x',
            'if' => ['const' => 'x'],
            'then' => ['minLength' => 1],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertSame('x', $case->value);
            if ($case->plan->targetPointer === '/if') {
                $this->assertSame(0, $case->plan->targetBranch, 'the unreachable else side was kept');
            }
        }
    }

    #[Test]
    public function drops_a_present_case_whose_property_was_trimmed(): void
    {
        // maxProperties 1 with a required sibling means `b` can never be
        // present; the trim removes it, so keeping the case would label a
        // b-less object as covering b's presence.
        $cases = BranchCompleteCaseGenerator::generate([
            'type' => 'object',
            'maxProperties' => 1,
            'required' => ['a'],
            'properties' => [
                'a' => ['type' => 'string'],
                'b' => ['type' => 'string'],
            ],
        ], seed: 1);

        $this->assertNotSame([], $cases);
        foreach ($cases as $case) {
            $this->assertIsArray($case->value);
            if ($case->plan->targetPointer === '/properties/b' && $case->plan->targetBranch === 0) {
                $this->assertArrayHasKey('b', $case->value, 'a present-targeted case lost its property to the trim');
            }
        }
    }
}
