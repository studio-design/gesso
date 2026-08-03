<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\SchemaChoicePoint;
use Studio\Gesso\Fuzz\SchemaChoicePointEnumerator;
use Studio\Gesso\Fuzz\SchemaChoicePointKind;

use function array_fill;

class SchemaChoicePointEnumeratorTest extends TestCase
{
    #[Test]
    public function collects_optional_property_presence_and_nested_one_of_for_the_incident_shape(): void
    {
        // studio-auth#1520: a primitive-only inline oneOf inside an *optional*
        // property of the response model, not at the schema root.
        $schema = [
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

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayHasKey('/properties/aud', $points);
        $presence = $points['/properties/aud'];
        $this->assertSame(SchemaChoicePointKind::OptionalProperty, $presence->kind);
        $this->assertSame(2, $presence->branchCount);
        $this->assertSame([], $presence->ancestors);

        $this->assertArrayHasKey('/properties/aud/oneOf', $points);
        $oneOf = $points['/properties/aud/oneOf'];
        $this->assertSame(SchemaChoicePointKind::OneOf, $oneOf->kind);
        $this->assertSame(2, $oneOf->branchCount);
        $this->assertSame(
            ['/properties/aud' => SchemaChoicePoint::PRESENT],
            $oneOf->ancestors,
        );
    }

    #[Test]
    public function does_not_record_presence_for_required_properties(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['status'],
            'properties' => [
                'status' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
        ];

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayNotHasKey('/properties/status', $points);
        $this->assertArrayHasKey('/properties/status/anyOf', $points);
        $anyOf = $points['/properties/status/anyOf'];
        $this->assertSame(SchemaChoicePointKind::AnyOf, $anyOf->kind);
        $this->assertSame(2, $anyOf->branchCount);
        $this->assertSame([], $anyOf->ancestors);
    }

    #[Test]
    public function collects_nullable_type_choice_and_constrains_descendants_to_the_value_branch(): void
    {
        $schema = [
            'type' => ['object', 'null'],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayHasKey('/type', $points);
        $nullable = $points['/type'];
        $this->assertSame(SchemaChoicePointKind::Nullable, $nullable->kind);
        $this->assertSame(2, $nullable->branchCount);

        $this->assertArrayHasKey('/properties/name', $points);
        $this->assertSame(
            ['/type' => SchemaChoicePoint::VALUE],
            $points['/properties/name']->ancestors,
        );
    }

    #[Test]
    public function collects_if_then_else_choice(): void
    {
        $schema = [
            'type' => 'object',
            'if' => ['required' => ['kind']],
            'then' => ['required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
            'else' => ['required' => ['b'], 'properties' => ['b' => ['type' => 'integer']]],
        ];

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayHasKey('/if', $points);
        $conditional = $points['/if'];
        $this->assertSame(SchemaChoicePointKind::IfThenElse, $conditional->kind);
        $this->assertSame(2, $conditional->branchCount);
    }

    #[Test]
    public function collects_all_of_conditional_choice(): void
    {
        $schema = [
            'type' => 'object',
            'allOf' => [
                ['properties' => ['base' => ['type' => 'string']]],
                ['if' => ['required' => ['x']], 'then' => ['properties' => ['x' => ['type' => 'string']]]],
                ['if' => ['required' => ['y']], 'then' => ['properties' => ['y' => ['type' => 'integer']]]],
            ],
        ];

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayHasKey('/allOf', $points);
        $conditional = $points['/allOf'];
        $this->assertSame(SchemaChoicePointKind::AllOfConditional, $conditional->kind);
        // One branch per conditional (if+then, all others suppressed) plus
        // the trailing none-match branch.
        $this->assertSame(3, $conditional->branchCount);
    }

    #[Test]
    public function enumerates_choice_points_under_the_none_match_view(): void
    {
        $schema = [
            'type' => 'object',
            'allOf' => [
                [
                    'if' => ['required' => ['kind']],
                    'then' => ['properties' => ['kind' => ['const' => 'a']]],
                    'else' => ['properties' => ['fallback' => ['type' => 'string']]],
                ],
            ],
        ];

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayHasKey('/properties/fallback', $points);
        // Reachable only when no conditional matches.
        $this->assertSame(['/allOf' => 1], $points['/properties/fallback']->ancestors);
    }

    #[Test]
    public function excludes_statically_unreachable_boolean_branches_from_the_one_of_space(): void
    {
        $points = SchemaChoicePointEnumerator::enumerate([
            'type' => 'string',
            'oneOf' => [true, ['const' => 'x']],
        ]);

        $this->assertCount(1, $points);
        $this->assertSame('/oneOf', $points[0]->pointer);
        // With a `true` sibling no other branch can be the sole match; only
        // the `true` branch is generatable.
        $this->assertSame(1, $points[0]->branchCount);
    }

    #[Test]
    public function resolves_a_boolean_if_without_recording_a_choice_point(): void
    {
        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate([
            'type' => 'object',
            'if' => true,
            'then' => [
                'required' => ['choice'],
                'properties' => ['choice' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]],
            ],
        ]));

        // `if: true` has no else side to choose — the then is unconditional,
        // and its content is enumerated without an /if ancestor.
        $this->assertArrayNotHasKey('/if', $points);
        $this->assertArrayHasKey('/properties/choice/oneOf', $points);
        $this->assertSame([], $points['/properties/choice/oneOf']->ancestors);
    }

    #[Test]
    public function excludes_an_unsatisfiable_boolean_consequent_from_the_if_space(): void
    {
        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate([
            'type' => 'string',
            'if' => ['const' => 'x'],
            'then' => false,
            'else' => ['const' => 'y'],
        ]));

        $this->assertArrayHasKey('/if', $points);
        // Only the else side is generatable: nothing satisfies `then: false`.
        $this->assertSame(1, $points['/if']->branchCount);
    }

    #[Test]
    public function records_presence_for_boolean_true_properties_and_skips_false_ones(): void
    {
        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate([
            'type' => 'object',
            'properties' => ['x' => true, 'y' => false],
        ]));

        $this->assertArrayHasKey('/properties/x', $points);
        $this->assertSame(SchemaChoicePointKind::OptionalProperty, $points['/properties/x']->kind);
        // Presence of a `false` property is unreachable — no choice.
        $this->assertArrayNotHasKey('/properties/y', $points);
    }

    #[Test]
    public function records_no_choice_for_conditionals_with_boolean_consequents(): void
    {
        // then: false → always suppressed; else: false → always satisfied.
        // Neither leaves a branch to choose.
        $this->assertSame([], SchemaChoicePointEnumerator::enumerate([
            'type' => 'string',
            'allOf' => [['if' => ['const' => 'x'], 'then' => false, 'else' => ['const' => 'y']]],
        ]));
        $this->assertSame([], SchemaChoicePointEnumerator::enumerate([
            'type' => 'string',
            'allOf' => [['if' => ['const' => 'a'], 'then' => true, 'else' => false]],
        ]));
    }

    #[Test]
    public function does_not_enumerate_past_a_boolean_false_prefix_item(): void
    {
        // Any array with elements is invalid once prefixItems[0] is false;
        // choice points behind it are unreachable.
        $this->assertSame([], SchemaChoicePointEnumerator::enumerate([
            'type' => 'array',
            'prefixItems' => [false, ['oneOf' => [['const' => 'x'], ['const' => 'y']]]],
        ]));
    }

    #[Test]
    public function records_rediscoveries_with_different_content_as_separate_choice_points(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['v'],
            'anyOf' => [
                ['properties' => ['v' => ['oneOf' => [['const' => 'x'], ['const' => 'y']]]]],
                ['properties' => ['v' => ['oneOf' => [['const' => 1], ['const' => 2]]]]],
            ],
        ];

        $entries = [];
        foreach (SchemaChoicePointEnumerator::enumerate($schema) as $point) {
            if ($point->pointer === '/properties/v/oneOf') {
                $entries[] = $point;
            }
        }

        // Same pointer and branch count, but different branch content: both
        // contexts keep their own full coverage under their own ancestors.
        $this->assertCount(2, $entries);
        $this->assertSame(2, $entries[0]->branchCount);
        $this->assertSame(['/anyOf' => 0], $entries[0]->ancestors);
        $this->assertSame(2, $entries[1]->branchCount);
        $this->assertSame(['/anyOf' => 1], $entries[1]->ancestors);
    }

    #[Test]
    public function keeps_identical_content_rediscoveries_from_different_contexts(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['v'],
            'anyOf' => [
                ['properties' => ['v' => ['oneOf' => [['const' => 'x'], ['const' => 'y']]]]],
                ['properties' => ['v' => ['oneOf' => [['const' => 'x'], ['const' => 'y']]]]],
            ],
        ];

        $contexts = [];
        foreach (SchemaChoicePointEnumerator::enumerate($schema) as $point) {
            if ($point->pointer === '/properties/v/oneOf') {
                $contexts[] = $point->ancestors;
            }
        }

        $this->assertSame([['/anyOf' => 0], ['/anyOf' => 1]], $contexts);
    }

    #[Test]
    public function collects_nullable_choice_for_an_enum_spanning_both_reachable_sides(): void
    {
        $points = SchemaChoicePointEnumerator::enumerate([
            'type' => ['string', 'null'],
            'enum' => ['member', null],
        ]);

        $this->assertCount(1, $points);
        $this->assertSame(SchemaChoicePointKind::Nullable, $points[0]->kind);
        $this->assertSame('/type', $points[0]->pointer);
        $this->assertSame(2, $points[0]->branchCount);
        $this->assertSame([], $points[0]->ancestors);
    }

    #[Test]
    public function stops_at_exact_domains_that_reach_only_one_nullable_side(): void
    {
        $schemas = [
            [
                'const' => 'fixed',
                'properties' => ['a' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]],
            ],
            ['type' => ['string', 'null'], 'const' => 'fixed'],
            ['type' => ['string', 'null'], 'const' => null],
            ['type' => ['string', 'null'], 'enum' => ['fixed']],
            ['type' => ['string', 'null'], 'enum' => [null]],
            [
                'type' => ['string', 'null'],
                'enum' => ['fixed', null],
                'not' => ['const' => null],
            ],
        ];

        foreach ($schemas as $schema) {
            $this->assertSame([], SchemaChoicePointEnumerator::enumerate($schema));
        }
    }

    #[Test]
    public function collects_choice_points_under_array_items_with_forced_item_presence(): void
    {
        $schema = [
            'type' => 'array',
            'minItems' => 0,
            'items' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
        ];

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayHasKey('/items/oneOf', $points);
        $this->assertSame(['/items' => 1], $points['/items/oneOf']->ancestors);
    }

    #[Test]
    public function collects_choice_points_under_prefix_items(): void
    {
        $schema = [
            'type' => 'array',
            'prefixItems' => [
                ['type' => 'string'],
                ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
        ];

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayHasKey('/prefixItems/1/oneOf', $points);
        $this->assertSame(['/items' => 2], $points['/prefixItems/1/oneOf']->ancestors);
    }

    #[Test]
    public function records_rediscoveries_per_branch_context(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'shared' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
            'oneOf' => [
                ['required' => ['shared']],
                ['properties' => ['extra' => ['type' => 'string']]],
            ],
        ];

        $contexts = [];
        $presenceContexts = [];
        foreach (SchemaChoicePointEnumerator::enumerate($schema) as $point) {
            if ($point->pointer === '/properties/shared/oneOf') {
                $contexts[] = $point->ancestors;
            }
            if ($point->pointer === '/properties/shared') {
                $presenceContexts[] = $point->ancestors;
            }
        }

        // Generation may leave a claimed branch context (closure expansion),
        // so every discovery context keeps its own entry; at least one of
        // them is stable under generation. Under branch 1 `shared` is
        // optional, so that context also forces its presence.
        $this->assertSame([
            ['/oneOf' => 0],
            ['/oneOf' => 1, '/properties/shared' => SchemaChoicePoint::PRESENT],
        ], $contexts);
        // Presence is only a choice under branch 1, where `shared` is optional.
        $this->assertSame([['/oneOf' => 1]], $presenceContexts);
    }

    #[Test]
    public function escapes_property_names_in_json_pointers(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'a/b' => ['type' => 'string'],
                'c~d' => ['type' => 'string'],
            ],
        ];

        $points = $this->indexByPointer(SchemaChoicePointEnumerator::enumerate($schema));

        $this->assertArrayHasKey('/properties/a~1b', $points);
        $this->assertArrayHasKey('/properties/c~0d', $points);
    }

    #[Test]
    public function returns_empty_for_a_schema_without_choice_points(): void
    {
        $this->assertSame([], SchemaChoicePointEnumerator::enumerate([
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'integer']],
        ]));
    }

    #[Test]
    public function throws_on_a_composition_nested_directly_inside_a_branch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enumeration');

        SchemaChoicePointEnumerator::enumerate([
            'oneOf' => [
                ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                ['type' => 'boolean'],
            ],
        ]);
    }

    #[Test]
    public function throws_when_one_of_and_any_of_share_a_node(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enumeration');

        SchemaChoicePointEnumerator::enumerate([
            'oneOf' => [['type' => 'string'], ['type' => 'integer']],
            'anyOf' => [['minLength' => 1], ['maxLength' => 3]],
        ]);
    }

    #[Test]
    public function throws_beyond_the_documented_depth_bound(): void
    {
        $schema = ['oneOf' => [['type' => 'string'], ['type' => 'integer']]];
        for ($i = 0; $i < 40; $i++) {
            $schema = [
                'type' => 'object',
                'required' => ['nested'],
                'properties' => ['nested' => $schema],
            ];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('depth');

        SchemaChoicePointEnumerator::enumerate($schema);
    }

    #[Test]
    public function throws_beyond_the_documented_choice_point_budget(): void
    {
        $properties = [];
        for ($i = 0; $i < 300; $i++) {
            $properties['p' . $i] = ['type' => 'string'];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('choice point');

        SchemaChoicePointEnumerator::enumerate([
            'type' => 'object',
            'properties' => $properties,
        ]);
    }

    #[Test]
    public function throws_beyond_the_documented_node_visit_budget(): void
    {
        $prefixItems = array_fill(0, 10_000, ['type' => 'string']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('node-visit budget of 10000');

        SchemaChoicePointEnumerator::enumerate([
            'type' => 'array',
            'prefixItems' => $prefixItems,
        ]);
    }

    /**
     * @param list<SchemaChoicePoint> $points
     *
     * @return array<string, SchemaChoicePoint>
     */
    private function indexByPointer(array $points): array
    {
        $indexed = [];
        foreach ($points as $point) {
            $indexed[$point->pointer] = $point;
        }

        return $indexed;
    }
}
