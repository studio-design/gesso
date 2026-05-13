<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredSchemaWalker;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

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
}
