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
