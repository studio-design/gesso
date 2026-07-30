<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesInspector;

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
