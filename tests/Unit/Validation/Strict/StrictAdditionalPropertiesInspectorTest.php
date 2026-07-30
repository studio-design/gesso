<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Strict;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesInspector;

use function json_decode;

final class StrictAdditionalPropertiesInspectorTest extends TestCase
{
    #[Test]
    public function finds_nested_and_array_element_properties_with_json_pointers(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $findings = StrictAdditionalPropertiesInspector::inspect([
            'items' => [
                ['id' => '1', 'secret' => true],
                ['id' => '2', 'debug' => true],
            ],
            'root/extra' => true,
            'literal[*]' => true,
            'tilde~key' => true,
        ], $schema);

        $this->assertSame([
            '/items[*]/debug' => 'debug',
            '/items[*]/secret' => 'secret',
            '/literal[~*]' => 'literal[*]',
            '/root~1extra' => 'root/extra',
            '/tilde~0key' => 'tilde~key',
        ], $findings);
    }

    #[Test]
    public function finds_numeric_string_property_names_decoded_as_integer_keys(): void
    {
        $body = json_decode('{"id":"ok","1":"extra"}', true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['/1' => '1'],
            StrictAdditionalPropertiesInspector::inspect($body, [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                ],
            ]),
        );
    }

    #[Test]
    public function respects_exact_pattern_and_all_of_declarations(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string']]],
            ],
        ];

        $this->assertSame(
            ['/other' => 'other'],
            StrictAdditionalPropertiesInspector::inspect(['id' => '1', 'x-trace' => 'abc', 'other' => true], $schema),
        );
    }

    #[Test]
    public function explicit_open_keywords_suppress_the_dynamic_property_but_typed_maps_are_walked(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'string']],
            ],
        ];

        $this->assertSame(
            ['/user-1/secret' => 'secret'],
            StrictAdditionalPropertiesInspector::inspect([
                'user-1' => ['id' => '1', 'secret' => true],
            ], $schema),
        );

        $this->assertSame([], StrictAdditionalPropertiesInspector::inspect(
            ['anything' => true],
            ['type' => 'object', 'unevaluatedProperties' => true],
        ));
        $this->assertSame(
            ['/anything' => 'anything'],
            StrictAdditionalPropertiesInspector::inspect(
                ['anything' => true],
                ['type' => 'object', 'unevaluatedProperties' => true],
                jsonSchemaDialect: OpenApiSchemaDialect::DRAFT_07,
            ),
        );
    }

    #[Test]
    public function boolean_property_and_pattern_schemas_still_declare_names(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['anything' => true],
            'patternProperties' => ['^x-' => true],
        ];

        $this->assertSame(
            ['/other' => 'other'],
            StrictAdditionalPropertiesInspector::inspect([
                'anything' => ['nested' => true],
                'x-dynamic' => ['nested' => true],
                'other' => true,
            ], $schema),
        );
    }

    #[Test]
    public function disjunction_nodes_are_conservatively_skipped(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ],
        ];

        $this->assertSame([], StrictAdditionalPropertiesInspector::inspect(['id' => '1', 'secret' => true], $schema));
    }

    #[Test]
    public function disjunctions_nested_in_all_of_are_conservatively_skipped(): void
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $schema = [
                'allOf' => [
                    [
                        $keyword => [
                            [
                                'type' => 'object',
                                'properties' => ['vat_id' => ['type' => 'string']],
                            ],
                            [
                                'type' => 'object',
                                'properties' => ['tax_id' => ['type' => 'string']],
                            ],
                        ],
                    ],
                    [
                        'type' => 'object',
                        'properties' => ['country' => ['type' => 'string']],
                    ],
                ],
            ];

            $this->assertSame(
                [],
                StrictAdditionalPropertiesInspector::inspect(
                    ['country' => 'DE', 'vat_id' => 'DE123'],
                    $schema,
                ),
                $keyword,
            );
        }
    }

    #[Test]
    public function prefix_items_and_items_apply_to_their_own_2020_12_indices(): void
    {
        $schema = [
            'type' => 'array',
            'prefixItems' => [
                [
                    'type' => 'object',
                    'properties' => ['prefix' => ['type' => 'string']],
                ],
            ],
            'items' => [
                'type' => 'object',
                'properties' => ['remainder' => ['type' => 'string']],
            ],
        ];

        $this->assertSame([], StrictAdditionalPropertiesInspector::inspect(
            [
                ['prefix' => 'first'],
                ['remainder' => 'second'],
            ],
            $schema,
        ));
        $this->assertSame([], StrictAdditionalPropertiesInspector::inspect(
            [['remainder' => 'first']],
            $schema,
            jsonSchemaDialect: OpenApiSchemaDialect::DRAFT_07,
        ));
    }

    #[Test]
    public function conditional_and_dependent_schema_nodes_are_conservatively_skipped(): void
    {
        $conditional = [
            'type' => 'object',
            'properties' => [
                'country' => ['type' => 'string'],
            ],
            'if' => [
                'properties' => [
                    'country' => ['const' => 'DE'],
                ],
            ],
            'then' => [
                'properties' => [
                    'vat_id' => ['type' => 'string'],
                ],
            ],
            'else' => [
                'properties' => [
                    'tax_id' => ['type' => 'string'],
                ],
            ],
        ];
        $dependent = [
            'type' => 'object',
            'properties' => [
                'company' => ['type' => 'string'],
            ],
            'dependentSchemas' => [
                'company' => [
                    'properties' => [
                        'vat_id' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $this->assertSame([], StrictAdditionalPropertiesInspector::inspect(
            ['country' => 'DE', 'vat_id' => 'DE123'],
            $conditional,
        ));
        $this->assertSame([], StrictAdditionalPropertiesInspector::inspect(
            ['company' => 'Acme', 'vat_id' => 'DE123'],
            $dependent,
        ));
    }

    #[Test]
    public function local_draft_07_dialect_does_not_enable_unevaluated_properties(): void
    {
        $schema = [
            '$schema' => OpenApiSchemaDialect::DRAFT_07,
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
            ],
            'unevaluatedProperties' => true,
        ];

        $this->assertSame(
            ['/trace_id' => 'trace_id'],
            StrictAdditionalPropertiesInspector::inspect(['id' => '1', 'trace_id' => 't'], $schema),
        );
    }

    #[Test]
    public function embedded_all_of_resources_preserve_dialect_for_collected_child_schemas(): void
    {
        $draft07Resource = [
            '$id' => 'https://example.test/draft-07-resource',
            '$schema' => OpenApiSchemaDialect::DRAFT_07,
            'type' => 'object',
        ];
        $child = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
            ],
            'unevaluatedProperties' => true,
        ];

        $this->assertSame(
            ['/payload/trace_id' => 'trace_id'],
            StrictAdditionalPropertiesInspector::inspect(
                ['payload' => ['id' => '1', 'trace_id' => 't']],
                [
                    'allOf' => [
                        $draft07Resource + [
                            'properties' => ['payload' => $child],
                        ],
                    ],
                ],
            ),
            'properties',
        );
        $this->assertSame(
            ['/payload/trace_id' => 'trace_id'],
            StrictAdditionalPropertiesInspector::inspect(
                ['payload' => ['id' => '1', 'trace_id' => 't']],
                [
                    'allOf' => [
                        $draft07Resource + [
                            'patternProperties' => ['^payload$' => $child],
                        ],
                    ],
                ],
            ),
            'patternProperties',
        );
        $this->assertSame(
            ['/payload/trace_id' => 'trace_id'],
            StrictAdditionalPropertiesInspector::inspect(
                ['payload' => ['id' => '1', 'trace_id' => 't']],
                [
                    'allOf' => [
                        $draft07Resource + [
                            'additionalProperties' => $child,
                        ],
                    ],
                ],
            ),
            'additionalProperties',
        );
        $this->assertSame(
            ['[*]/trace_id' => 'trace_id'],
            StrictAdditionalPropertiesInspector::inspect(
                [['id' => '1', 'trace_id' => 't']],
                [
                    'allOf' => [
                        $draft07Resource + [
                            'type' => 'array',
                            'items' => $child,
                        ],
                    ],
                ],
            ),
            'items',
        );
    }

    #[Test]
    public function additional_properties_prevents_unevaluated_properties_from_reapplying(): void
    {
        $unevaluatedChildSchema = [
            'type' => 'object',
            'properties' => [
                'documented_only_by_unevaluated' => ['type' => 'string'],
            ],
        ];

        $this->assertSame([], StrictAdditionalPropertiesInspector::inspect(
            ['dynamic' => ['secret' => true]],
            [
                'type' => 'object',
                'additionalProperties' => true,
                'unevaluatedProperties' => $unevaluatedChildSchema,
            ],
        ));
        $this->assertSame(
            ['/dynamic/documented_only_by_unevaluated' => 'documented_only_by_unevaluated'],
            StrictAdditionalPropertiesInspector::inspect(
                ['dynamic' => ['id' => '1', 'documented_only_by_unevaluated' => 'value']],
                [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                        ],
                    ],
                    'unevaluatedProperties' => $unevaluatedChildSchema,
                ],
            ),
        );
    }
}
