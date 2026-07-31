<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Strict\StrictRequiredKnownRequired;
use Studio\Gesso\Validation\Strict\StrictRequiredMapMatch;
use Studio\Gesso\Validation\Strict\StrictRequiredSchemaWalker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

final class StrictRequiredSchemaWalkerTest extends TestCase
{
    #[Test]
    public function split_endpoint_key_separates_method_and_path(): void
    {
        $this->assertSame(['GET', '/users/{id}'], StrictRequiredSchemaWalker::splitEndpointKey('GET /users/{id}'));
    }

    #[Test]
    public function split_endpoint_key_uppercases_method(): void
    {
        $this->assertSame(['POST', '/projects'], StrictRequiredSchemaWalker::splitEndpointKey('post /projects'));
    }

    #[Test]
    public function split_endpoint_key_falls_back_when_no_space(): void
    {
        // The tracker always inserts a space, but the helper is defensive
        // so a hand-built malformed key surfaces an obvious "/" path
        // rather than an out-of-bounds substring.
        $this->assertSame(['GET', '/'], StrictRequiredSchemaWalker::splitEndpointKey('get'));
    }

    #[Test]
    public function split_response_key_separates_status_and_content_type(): void
    {
        $this->assertSame(['200', 'application/json'], StrictRequiredSchemaWalker::splitResponseKey('200:application/json'));
    }

    #[Test]
    public function split_response_key_defaults_content_type_to_any(): void
    {
        $this->assertSame(['200', StrictRequiredTracker::ANY_CONTENT_TYPE], StrictRequiredSchemaWalker::splitResponseKey('200'));
    }

    #[Test]
    public function resolve_response_schema_returns_schema_for_full_path(): void
    {
        $spec = [
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = StrictRequiredSchemaWalker::resolveResponseSchema($spec, 'GET', '/users/{id}', '200', 'application/json');

        $this->assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'string']]], $resolved);
    }

    #[Test]
    public function resolve_response_schema_returns_null_for_missing_method(): void
    {
        $spec = ['paths' => ['/users/{id}' => ['get' => []]]];

        $this->assertNull(StrictRequiredSchemaWalker::resolveResponseSchema($spec, 'POST', '/users/{id}', '200', 'application/json'));
    }

    #[Test]
    public function resolve_response_schema_returns_null_for_missing_status(): void
    {
        $spec = [
            'paths' => [
                '/users/{id}' => [
                    'get' => [
                        'responses' => [
                            '404' => ['content' => ['application/json' => ['schema' => []]]],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertNull(StrictRequiredSchemaWalker::resolveResponseSchema($spec, 'GET', '/users/{id}', '200', 'application/json'));
    }

    #[Test]
    public function resolve_response_schema_returns_null_for_missing_content_type(): void
    {
        $spec = [
            'paths' => [
                '/foo' => [
                    'get' => [
                        'responses' => [
                            '200' => ['content' => ['text/plain' => ['schema' => ['type' => 'string']]]],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertNull(StrictRequiredSchemaWalker::resolveResponseSchema($spec, 'GET', '/foo', '200', 'application/json'));
    }

    #[Test]
    public function collect_required_by_pointer_walks_object_root_with_required(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['id', 'name'],
            'properties' => [
                'id' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'created_at' => ['type' => 'string'],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => ['id', 'name']], $result['walked']);
        $this->assertSame([], $result['disjunctions']);
    }

    #[Test]
    public function collect_required_by_pointer_unions_all_of_required_at_root(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => ['id']], $result['walked']);
        $this->assertSame([], $result['disjunctions']);
    }

    #[Test]
    public function collect_required_by_pointer_descends_into_nested_object_property(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'created_at' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => ['data'], '/data' => ['name']], $result['walked']);
    }

    #[Test]
    public function collect_required_by_pointer_descends_into_array_items(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'string']],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['[*]' => ['id']], $result['walked']);
        $this->assertSame([], $result['disjunctions']);
    }

    #[Test]
    public function collect_required_by_pointer_marks_root_any_of_as_disjunction(): void
    {
        $schema = [
            'anyOf' => [
                ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
                ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'string']]],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame([], $result['walked']);
        $this->assertSame([['pointer' => '', 'reason' => 'anyOf']], $result['disjunctions']);
    }

    #[Test]
    public function collect_required_by_pointer_marks_root_one_of_as_disjunction(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
                ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'string']]],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame([['pointer' => '', 'reason' => 'oneOf']], $result['disjunctions']);
    }

    #[Test]
    public function collect_required_by_pointer_marks_nested_disjunction(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'oneOf' => [
                        ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'string']]],
                        ['type' => 'object', 'required' => ['y'], 'properties' => ['y' => ['type' => 'string']]],
                    ],
                ],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertArrayHasKey('/', $result['walked']);
        $this->assertSame([['pointer' => '/data', 'reason' => 'oneOf']], $result['disjunctions']);
    }

    #[Test]
    public function find_covering_disjunction_matches_root_disjunction_for_any_pointer(): void
    {
        $disjunctions = [['pointer' => '', 'reason' => 'anyOf']];

        $this->assertSame($disjunctions[0], StrictRequiredSchemaWalker::findCoveringDisjunction('/foo', $disjunctions));
        $this->assertSame($disjunctions[0], StrictRequiredSchemaWalker::findCoveringDisjunction('[*]', $disjunctions));
    }

    #[Test]
    public function find_covering_disjunction_matches_descendant_via_slash_boundary(): void
    {
        $disjunctions = [['pointer' => '/data', 'reason' => 'oneOf']];

        $this->assertSame($disjunctions[0], StrictRequiredSchemaWalker::findCoveringDisjunction('/data/inner', $disjunctions));
    }

    #[Test]
    public function find_covering_disjunction_matches_descendant_via_array_marker(): void
    {
        $disjunctions = [['pointer' => '/items', 'reason' => 'oneOf']];

        $this->assertSame($disjunctions[0], StrictRequiredSchemaWalker::findCoveringDisjunction('/items[*]', $disjunctions));
    }

    #[Test]
    public function find_covering_disjunction_returns_null_for_unrelated_pointer(): void
    {
        $disjunctions = [['pointer' => '/data', 'reason' => 'oneOf']];

        // `/dataset` shares a prefix but is a different property — must not
        // be reported as covered by `/data`'s disjunction.
        $this->assertNull(StrictRequiredSchemaWalker::findCoveringDisjunction('/dataset', $disjunctions));
        $this->assertNull(StrictRequiredSchemaWalker::findCoveringDisjunction('/other', $disjunctions));
    }

    #[Test]
    public function collect_required_by_pointer_marks_map_shaped_node_as_map(): void
    {
        // Issue #437: `additionalProperties: <schema>` with no `properties`
        // means "this object is a map" — its keys are data, not shape, so
        // the node must not land in `walked` (which would diff observed
        // dynamic keys against `required`).
        $schema = [
            'type' => 'object',
            'required' => ['errors'],
            'properties' => [
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => ['errors']], $result['walked']);
        $this->assertSame([], $result['disjunctions']);
        $this->assertArrayHasKey('maps', $result);
        $this->assertSame(['/errors'], $result['maps']);
    }

    #[Test]
    public function collect_required_by_pointer_marks_map_shaped_root_as_map(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame([], $result['walked']);
        $this->assertSame([], $result['disjunctions']);
        $this->assertSame(['/'], $result['maps']);
    }

    #[Test]
    public function collect_required_by_pointer_marks_untyped_additional_properties_node_as_map(): void
    {
        // `type: object` omitted — the additionalProperties keyword alone
        // is enough to identify the node as an object-shaped map.
        $schema = [
            'type' => 'object',
            'properties' => [
                'lookup' => [
                    'additionalProperties' => ['type' => 'integer'],
                ],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => []], $result['walked']);
        $this->assertSame(['/lookup'], $result['maps']);
    }

    #[Test]
    public function collect_required_by_pointer_marks_additional_properties_true_node_as_map(): void
    {
        // Boolean `true` explicitly documents openness the same way the
        // schema form does (mirrors StrictAdditionalPropertiesInspector's
        // reading) — with no declared properties there is no shape to diff.
        $schema = [
            'type' => 'object',
            'properties' => [
                'extra' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => []], $result['walked']);
        $this->assertSame(['/extra'], $result['maps']);
    }

    #[Test]
    public function collect_required_by_pointer_keeps_node_with_properties_and_additional_properties_walked(): void
    {
        // Partially-fixed shape: at least one declared property means
        // "this key is always present" is still a meaningful claim, so the
        // node stays in `walked` and drift is still reported.
        $schema = [
            'type' => 'object',
            'required' => ['fixed'],
            'properties' => ['fixed' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'string'],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => ['fixed']], $result['walked']);
        $this->assertSame([], $result['maps']);
    }

    #[Test]
    public function collect_required_by_pointer_treats_boolean_property_schema_as_declared_property(): void
    {
        // JSON Schema 2020-12 (OAS 3.1/3.2) allows boolean subschemas:
        // `fixed: true` declares the property name even though there is
        // nothing to walk beneath it. The node therefore has a (partially)
        // fixed shape and must not be classified as a map.
        $schema = [
            'type' => 'object',
            'properties' => ['fixed' => true],
            'additionalProperties' => ['type' => 'string'],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => []], $result['walked']);
        $this->assertSame([], $result['maps']);
    }

    #[Test]
    public function collect_required_by_pointer_does_not_mark_additional_properties_false_as_map(): void
    {
        // `false` closes the object — it is not a map declaration, so the
        // node keeps ordinary walked/drift semantics.
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => []], $result['walked']);
        $this->assertSame([], $result['maps']);
    }

    #[Test]
    public function collect_required_by_pointer_marks_all_of_composed_map_as_map(): void
    {
        // additionalProperties declared inside an allOf branch with no
        // declared properties anywhere still composes to a map.
        $schema = [
            'type' => 'object',
            'properties' => [
                'meta' => [
                    'allOf' => [
                        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $result = StrictRequiredSchemaWalker::collectRequiredByPointer($schema);

        $this->assertSame(['/' => []], $result['walked']);
        $this->assertSame(['/meta'], $result['maps']);
    }

    #[Test]
    public function find_covering_map_pointer_matches_node_and_descendants(): void
    {
        $maps = ['/errors'];

        $this->assertSame('/errors', StrictRequiredSchemaWalker::findCoveringMapPointer('/errors', $maps));
        $this->assertSame('/errors', StrictRequiredSchemaWalker::findCoveringMapPointer('/errors/client_id', $maps));
        $this->assertSame('/errors', StrictRequiredSchemaWalker::findCoveringMapPointer('/errors[*]', $maps));
    }

    #[Test]
    public function find_covering_map_pointer_root_map_covers_every_object_pointer(): void
    {
        $maps = ['/'];

        $this->assertSame('/', StrictRequiredSchemaWalker::findCoveringMapPointer('/', $maps));
        $this->assertSame('/', StrictRequiredSchemaWalker::findCoveringMapPointer('/user-42', $maps));
        $this->assertSame('/', StrictRequiredSchemaWalker::findCoveringMapPointer('/user-42/id', $maps));
    }

    #[Test]
    public function find_covering_map_pointer_returns_null_for_unrelated_pointer(): void
    {
        $maps = ['/data'];

        // `/dataset` shares a prefix but is a different property.
        $this->assertNull(StrictRequiredSchemaWalker::findCoveringMapPointer('/dataset', $maps));
        $this->assertNull(StrictRequiredSchemaWalker::findCoveringMapPointer('/other', $maps));
    }

    #[Test]
    public function analyse_lookup_returns_map_match_for_map_covered_pointers(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ];

        $analysis = StrictRequiredSchemaWalker::analyse($schema);

        $this->assertInstanceOf(StrictRequiredMapMatch::class, $analysis->lookup('/errors'));
        $mapMatch = $analysis->lookup('/errors/client_id');
        $this->assertInstanceOf(StrictRequiredMapMatch::class, $mapMatch);
        $this->assertSame('/errors', $mapMatch->coveringPointer);
        $this->assertInstanceOf(StrictRequiredKnownRequired::class, $analysis->lookup('/'));
    }
}
