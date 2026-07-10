<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Spec;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\MalformedDiscriminatorException;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiRefResolver;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Validation\Support\DiscriminatorContext;
use Studio\OpenApiContractTesting\Validation\Support\ObjectConverter;
use Studio\OpenApiContractTesting\Validation\Support\SchemaValidatorRunner;

use function implode;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;

class OpenApiSchemaConverterTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the converter's "warned-once" set. The set is process-global,
        // so without per-test reset the test ordering decides whether a
        // warning fires. Run filters or test-suite reordering would otherwise
        // produce non-deterministic outcomes for the warning tests below.
        parent::setUp();
        OpenApiSchemaConverter::resetWarningStateForTesting();
    }

    protected function tearDown(): void
    {
        OpenApiSchemaConverter::resetWarningStateForTesting();
        parent::tearDown();
    }

    // ========================================
    // OAS 3.0 tests
    // ========================================

    #[Test]
    public function v30_nullable_type_converted_to_type_array(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => true,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['type']);
        $this->assertArrayNotHasKey('nullable', $result);
    }

    #[Test]
    public function v30_nullable_one_of_adds_null_type(): void
    {
        $schema = [
            'nullable' => true,
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertCount(3, $result['oneOf']);
        $this->assertSame(['type' => 'null'], $result['oneOf'][2]);
        $this->assertArrayNotHasKey('nullable', $result);
    }

    #[Test]
    public function v30_nullable_all_of_wrapped_in_one_of(): void
    {
        $schema = [
            'nullable' => true,
            'allOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertArrayNotHasKey('allOf', $result);
        $this->assertArrayHasKey('oneOf', $result);
        $this->assertCount(2, $result['oneOf']);
        $this->assertArrayHasKey('allOf', $result['oneOf'][0]);
        $this->assertSame(['type' => 'null'], $result['oneOf'][1]);
        $this->assertArrayNotHasKey('nullable', $result);
    }

    #[Test]
    public function v30_nested_properties_converted_recursively(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'address' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'city' => [
                            'type' => 'string',
                            'nullable' => true,
                        ],
                    ],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['properties']['name']['type']);
        $this->assertSame(['object', 'null'], $result['properties']['address']['type']);
        $this->assertSame(['string', 'null'], $result['properties']['address']['properties']['city']['type']);
    }

    #[Test]
    public function v30_items_nullable_converted(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'nullable' => true,
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['items']['type']);
    }

    #[Test]
    public function v30_openapi_only_keys_removed(): void
    {
        $schema = [
            'type' => 'string',
            'description' => 'a name',
            'example' => 'John',
            'deprecated' => true,
            'readOnly' => true,
            'writeOnly' => false,
            'xml' => ['name' => 'test'],
            'externalDocs' => ['url' => 'https://example.com'],
            'discriminator' => ['propertyName' => 'type'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame('string', $result['type']);
        $this->assertSame('a name', $result['description']);
        $this->assertArrayNotHasKey('example', $result);
        $this->assertArrayNotHasKey('deprecated', $result);
        $this->assertArrayNotHasKey('readOnly', $result);
        $this->assertArrayNotHasKey('writeOnly', $result);
        $this->assertArrayNotHasKey('xml', $result);
        $this->assertArrayNotHasKey('externalDocs', $result);
        $this->assertArrayNotHasKey('discriminator', $result);
    }

    #[Test]
    public function v30_nullable_false_not_converted(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => false,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame('string', $result['type']);
        $this->assertArrayNotHasKey('nullable', $result);
    }

    #[Test]
    public function v30_default_version_when_omitted(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => true,
        ];

        $result = OpenApiSchemaConverter::convert($schema);

        $this->assertSame(['string', 'null'], $result['type']);
    }

    // ========================================
    // OAS 3.1 tests
    // ========================================

    #[Test]
    public function v31_type_array_preserved(): void
    {
        $schema = [
            'type' => ['string', 'null'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame(['string', 'null'], $result['type']);
    }

    #[Test]
    public function v31_prefix_items_converted_to_items(): void
    {
        $schema = [
            'type' => 'array',
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('prefixItems', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertCount(2, $result['items']);
        $this->assertSame(['type' => 'string'], $result['items'][0]);
        $this->assertSame(['type' => 'integer'], $result['items'][1]);
        // No sibling `items` declared on input → no `additionalItems` emitted.
        // Draft 07 defaults to "anything allowed" past the tuple, matching
        // 2020-12 semantics when `items` is absent alongside `prefixItems`.
        $this->assertArrayNotHasKey('additionalItems', $result);
    }

    #[Test]
    public function v31_prefix_items_with_sibling_items_schema_is_lowered_to_additional_items(): void
    {
        // Anchor case for the issue #212 fix: sibling `items` must survive
        // as `additionalItems`. The handlePrefixItems docblock carries the
        // full 2020-12 § 10.3 rationale; this test pins the canonical mapping.
        $schema = [
            'type' => 'array',
            'prefixItems' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
            'items' => ['type' => 'boolean'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('prefixItems', $result);
        $this->assertCount(2, $result['items']);
        $this->assertSame(['type' => 'string'], $result['items'][0]);
        $this->assertSame(['type' => 'integer'], $result['items'][1]);
        $this->assertArrayHasKey('additionalItems', $result);
        $this->assertSame(['type' => 'boolean'], $result['additionalItems']);
    }

    #[Test]
    public function v31_prefix_items_with_items_false_lowers_to_additional_items_false(): void
    {
        // Closed-tuple idiom: `prefixItems + items: false` means "exactly N
        // elements, in this order, nothing more". Draft 07 encodes the same
        // closure as `additionalItems: false`.
        $schema = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'items' => false,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('prefixItems', $result);
        $this->assertCount(1, $result['items']);
        $this->assertArrayHasKey('additionalItems', $result);
        $this->assertFalse($result['additionalItems']);
    }

    #[Test]
    public function v31_prefix_items_with_items_true_omits_additional_items(): void
    {
        // `items: true` is the 2020-12 explicit form of the implicit default
        // ("any overflow allowed"). Draft 07's implicit default is the same
        // under opis's pinned Draft 07 runtime, so the key is omitted rather
        // than emitted. The opis-equivalence regression test in
        // SchemaValidatorRunnerTest pins the "absent === true" assumption.
        $schema = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'items' => true,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('prefixItems', $result);
        $this->assertCount(1, $result['items']);
        $this->assertArrayNotHasKey('additionalItems', $result);
    }

    #[Test]
    public function v31_nested_prefix_items_inside_additional_items_converted_recursively(): void
    {
        // The schema routed to `additionalItems` may itself be a 2020-12
        // subschema (here: a nested array with its own `prefixItems`).
        // Without recursion into `additionalItems`, the inner `prefixItems`
        // would survive into the lowered output.
        $schema = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'items' => [
                'type' => 'array',
                'prefixItems' => [['type' => 'integer']],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayHasKey('additionalItems', $result);
        $this->assertArrayNotHasKey('prefixItems', $result['additionalItems']);
        $this->assertArrayHasKey('items', $result['additionalItems']);
        $this->assertSame([['type' => 'integer']], $result['additionalItems']['items']);
    }

    #[Test]
    public function v31_prefix_items_with_sibling_items_inside_one_of_lowers_recursively(): void
    {
        // Pins the recursion order: handlePrefixItems must run at the top of
        // convertInPlace for each frame, so a `prefixItems + items` carried
        // inside a combiner is lowered on the combiner's own pass. A future
        // refactor that moved handlePrefixItems below the combiner loop would
        // silently regress this — the test fails for the right reason.
        $schema = [
            'oneOf' => [
                [
                    'type' => 'array',
                    'prefixItems' => [['type' => 'string']],
                    'items' => ['type' => 'boolean'],
                ],
                ['type' => 'string'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $tupleBranch = $result['oneOf'][0];
        $this->assertArrayNotHasKey('prefixItems', $tupleBranch);
        $this->assertSame([['type' => 'string']], $tupleBranch['items']);
        $this->assertSame(['type' => 'boolean'], $tupleBranch['additionalItems']);
    }

    #[Test]
    public function v31_prefix_items_with_sibling_items_inside_additional_properties_lowers_recursively(): void
    {
        // additionalProperties is one of the other recursion sites; a
        // `prefixItems + items` value placed there must lower the same way.
        $schema = [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'array',
                'prefixItems' => [['type' => 'string']],
                'items' => ['type' => 'boolean'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $value = $result['additionalProperties'];
        $this->assertArrayNotHasKey('prefixItems', $value);
        $this->assertSame([['type' => 'string']], $value['items']);
        $this->assertSame(['type' => 'boolean'], $value['additionalItems']);
    }

    #[Test]
    public function v31_prefix_items_with_malformed_items_sibling_warns_and_drops(): void
    {
        // JSON Schema 2020-12 §10.3 requires `items` to be `Schema | bool`.
        // A scalar like `items: "string"` is a spec defect; hoisting it
        // into `additionalItems` would surface as an opis parse error far
        // from the source. handlePrefixItems must warn and drop it.
        $schema = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'items' => 'boolean',
        ];

        $captured = $this->captureWarnings(
            static fn() => OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1),
        );

        $this->assertCount(1, $captured);
        $this->assertStringContainsString("sibling 'items' of 'prefixItems'", $captured[0]);
        $this->assertStringContainsString('string', $captured[0]);

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        $this->assertArrayNotHasKey('additionalItems', $result);
        $this->assertSame([['type' => 'string']], $result['items']);
    }

    #[Test]
    public function v30_literal_additional_items_recursively_lowered_for_nullable(): void
    {
        // Draft 04 (which OAS 3.0 inherits) defines `additionalItems` as a
        // real keyword. A hand-authored 3.0 spec that uses it must still
        // see its nested 3.0-only keys (nullable, etc.) lowered — the
        // converter must recurse into additionalItems regardless of
        // whether handlePrefixItems put it there.
        $schema = [
            'type' => 'array',
            'items' => [['type' => 'string']],
            'additionalItems' => [
                'type' => 'string',
                'nullable' => true,
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertArrayHasKey('additionalItems', $result);
        $this->assertArrayNotHasKey('nullable', $result['additionalItems']);
        $this->assertSame(['string', 'null'], $result['additionalItems']['type']);
    }

    #[Test]
    public function v31_draft_2020_12_keys_removed(): void
    {
        $schema = [
            'type' => 'object',
            '$dynamicRef' => '#meta',
            '$dynamicAnchor' => 'meta',
            'contentSchema' => ['type' => 'string'],
            'examples' => [['key' => 'value']],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('$dynamicRef', $result);
        $this->assertArrayNotHasKey('$dynamicAnchor', $result);
        $this->assertArrayNotHasKey('contentSchema', $result);
        $this->assertArrayNotHasKey('examples', $result);
        $this->assertSame('object', $result['type']);
        $this->assertArrayHasKey('properties', $result);
    }

    #[Test]
    public function v31_common_openapi_keys_removed(): void
    {
        $schema = [
            'type' => 'string',
            'description' => 'a name',
            'example' => 'John',
            'deprecated' => true,
            'xml' => ['name' => 'test'],
            'externalDocs' => ['url' => 'https://example.com'],
            'discriminator' => ['propertyName' => 'type'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame('string', $result['type']);
        $this->assertSame('a name', $result['description']);
        $this->assertArrayNotHasKey('example', $result);
        $this->assertArrayNotHasKey('deprecated', $result);
        $this->assertArrayNotHasKey('xml', $result);
        $this->assertArrayNotHasKey('externalDocs', $result);
        $this->assertArrayNotHasKey('discriminator', $result);
    }

    #[Test]
    public function v31_nullable_keyword_not_processed(): void
    {
        // OAS 3.1 doesn't have "nullable" — type arrays are used instead.
        // If somehow present, it should NOT be converted to type array.
        $schema = [
            'type' => 'string',
            'nullable' => true,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        // nullable is not in the 3.1 removal list, so it stays
        // but handleNullable is NOT called for 3.1
        $this->assertSame('string', $result['type']);
    }

    #[Test]
    public function v31_nested_prefix_items_converted_recursively(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'coordinates' => [
                    'type' => 'array',
                    'prefixItems' => [
                        ['type' => 'number'],
                        ['type' => 'number'],
                    ],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('prefixItems', $result['properties']['coordinates']);
        $this->assertArrayHasKey('items', $result['properties']['coordinates']);
        $this->assertCount(2, $result['properties']['coordinates']['items']);
    }

    #[Test]
    public function v31_read_only_write_only_preserved(): void
    {
        // In 3.1, readOnly/writeOnly are valid JSON Schema keywords (Draft 07 supports them)
        $schema = [
            'type' => 'string',
            'readOnly' => true,
            'writeOnly' => false,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        // These are NOT removed in 3.1 mode (only removed in 3.0 mode)
        $this->assertTrue($result['readOnly']);
        $this->assertFalse($result['writeOnly']);
    }

    // ========================================
    // Input immutability tests
    // ========================================

    #[Test]
    public function convert_does_not_mutate_input_schema(): void
    {
        $schema = [
            'type' => 'object',
            'nullable' => true,
            'example' => 'test',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'nullable' => true,
                    'example' => 'John',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'deprecated' => true,
                    ],
                ],
            ],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame($original, $schema);
    }

    // ========================================
    // SchemaContext: readOnly / writeOnly enforcement
    // ========================================

    #[Test]
    public function response_context_write_only_property_becomes_false_subschema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertFalse($result['properties']['password']);
        $this->assertSame(['type' => 'integer'], $result['properties']['id']);
    }

    #[Test]
    public function response_context_write_only_property_removed_from_required(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
            'required' => ['id', 'name', 'password'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertSame(['id', 'name'], $result['required']);
    }

    #[Test]
    public function response_context_required_key_dropped_when_it_becomes_empty(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
            'required' => ['password'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertArrayNotHasKey('required', $result);
    }

    #[Test]
    public function response_context_read_only_property_is_not_forbidden(): void
    {
        // readOnly is allowed in responses — it should pass through, and in 3.0 mode
        // the keyword itself is scrubbed as an OAS-only key (existing behaviour).
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
            ],
            'required' => ['id'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertSame(['type' => 'integer'], $result['properties']['id']);
        $this->assertSame(['id'], $result['required']);
    }

    #[Test]
    public function request_context_read_only_property_becomes_false_subschema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Request);

        $this->assertFalse($result['properties']['id']);
        $this->assertSame(['type' => 'string'], $result['properties']['name']);
    }

    #[Test]
    public function request_context_read_only_property_removed_from_required(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string'],
            ],
            'required' => ['id', 'name'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Request);

        $this->assertSame(['name'], $result['required']);
    }

    #[Test]
    public function request_context_write_only_property_is_not_forbidden(): void
    {
        // writeOnly is allowed in requests — it should pass through.
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
            'required' => ['password'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Request);

        $this->assertSame(['type' => 'string'], $result['properties']['password']);
        $this->assertSame(['password'], $result['required']);
    }

    #[Test]
    public function response_context_write_only_enforced_on_nested_properties(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'password' => ['type' => 'string', 'writeOnly' => true],
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['password', 'name'],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertFalse($result['properties']['user']['properties']['password']);
        $this->assertSame(['name'], $result['properties']['user']['required']);
    }

    #[Test]
    public function v31_write_only_property_still_enforced_in_response_context(): void
    {
        // In 3.1 the keyword is Draft-07-valid and normally preserved, but Response
        // context must still reject the property from appearing in a response body.
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => ['type' => 'string', 'writeOnly' => true],
                'name' => ['type' => 'string', 'readOnly' => true],
            ],
            'required' => ['password'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1, SchemaContext::Response);

        $this->assertFalse($result['properties']['password']);
        // The non-forbidden branch keeps the Draft-07-valid keyword in 3.1.
        $this->assertTrue($result['properties']['name']['readOnly']);
        $this->assertArrayNotHasKey('required', $result);
    }

    #[Test]
    public function default_context_is_response(): void
    {
        // Default is Response so callers that don't pass a context still get
        // the response-leak guard on writeOnly properties.
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => ['type' => 'string', 'writeOnly' => true],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertFalse($result['properties']['password']);
    }

    #[Test]
    public function response_context_write_only_enforced_inside_array_items(): void
    {
        // Array-of-object is the most common list-endpoint shape. The recursion
        // into `items` must carry the context so a writeOnly property inside an
        // element is still replaced.
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'password' => ['type' => 'string', 'writeOnly' => true],
                ],
                'required' => ['id', 'password'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertFalse($result['items']['properties']['password']);
        $this->assertSame(['id'], $result['items']['required']);
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_with_forbidden_properties(): void
    {
        // Immutability regression guard specifically for the enforcement path,
        // which writes to $schema['properties'] and $schema['required'].
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'password' => ['type' => 'string', 'writeOnly' => true],
                'name' => ['type' => 'string'],
            ],
            'required' => ['id', 'password', 'name'],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);
        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Request);

        $this->assertSame($original, $schema);
    }

    #[Test]
    public function marker_inside_combiner_child_is_not_enforced_known_limitation(): void
    {
        // Characterisation test pinning the documented limitation: detection
        // only looks at the property's own top-level readOnly/writeOnly, not
        // at markers buried inside allOf/oneOf/anyOf children. If this test
        // ever starts failing, the limitation has been lifted — update the
        // README and the enforceContextOnProperties docblock accordingly.
        $schema = [
            'type' => 'object',
            'properties' => [
                'password' => [
                    'allOf' => [
                        ['type' => 'string', 'writeOnly' => true],
                    ],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1, SchemaContext::Response);

        $this->assertNotFalse($result['properties']['password']);
        $this->assertArrayHasKey('allOf', $result['properties']['password']);
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_v31(): void
    {
        $schema = [
            'type' => 'object',
            'prefixItems' => [
                ['type' => 'string'],
            ],
            'examples' => [['key' => 'value']],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    '$dynamicRef' => '#meta',
                ],
            ],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame($original, $schema);
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_v31_with_sibling_items(): void
    {
        // handlePrefixItems writes `additionalItems` on the working copy
        // when `prefixItems + items` is present. Pin that the new write
        // path doesn't leak back to the caller's input array.
        $schema = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'items' => ['type' => 'boolean'],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame($original, $schema);
    }

    // ========================================
    // OAS 3.0 nullable + enum (regression)
    // ========================================

    #[Test]
    public function v30_nullable_with_enum_appends_null_to_enum(): void
    {
        // OAS 3.0 convention: `nullable: true` next to `enum: [...]` implies
        // null is also valid. The pre-fix converter only rewrote `type` and
        // left `enum` intact, causing opis to reject `null` against the enum.
        $schema = [
            'type' => 'string',
            'nullable' => true,
            'enum' => ['a', 'b'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['type']);
        $this->assertContains(null, $result['enum']);
        $this->assertCount(3, $result['enum']);
    }

    #[Test]
    public function v30_nullable_with_enum_already_containing_null_unchanged(): void
    {
        $schema = [
            'type' => 'string',
            'nullable' => true,
            'enum' => ['a', null, 'b'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        // null is already present — do not duplicate.
        $nullCount = 0;
        foreach ($result['enum'] as $value) {
            if ($value === null) {
                $nullCount++;
            }
        }
        $this->assertSame(1, $nullCount);
    }

    #[Test]
    public function v30_nullable_with_enum_and_no_type_still_appends_null(): void
    {
        // `enum` alone (no explicit `type`) is valid OAS — null still becomes
        // valid under nullable.
        $schema = [
            'nullable' => true,
            'enum' => ['a', 'b'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertContains(null, $result['enum']);
    }

    // ========================================
    // OAS 3.0 schema-level `examples` strip
    // ========================================

    #[Test]
    public function v30_examples_keyword_stripped(): void
    {
        // `examples` (the JSON Schema array form) is a Draft 2020-12 keyword
        // not understood by Draft 07. We strip it in 3.1; should also strip
        // in 3.0 for consistency since `example` (singular) is already removed.
        $schema = [
            'type' => 'string',
            'examples' => ['foo', 'bar'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertArrayNotHasKey('examples', $result);
    }

    // ========================================
    // OAS 3.1 const lowered to enum (Draft 07)
    // ========================================

    #[Test]
    public function v31_const_lowered_to_single_value_enum(): void
    {
        // Draft 07 doesn't support `const`. Lower to `enum: [value]` so opis
        // actually enforces the value rather than silently passing.
        $schema = [
            'type' => 'string',
            'const' => 'fixed',
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result);
        $this->assertSame(['fixed'], $result['enum']);
    }

    #[Test]
    public function v31_const_null_value_lowered_to_enum(): void
    {
        $schema = [
            'const' => null,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result);
        $this->assertSame([null], $result['enum']);
    }

    #[Test]
    public function v31_const_does_not_overwrite_existing_enum(): void
    {
        // If both keys are present the spec is malformed; preserve `enum` and
        // drop `const` (the conservative choice — `enum` is the wider constraint).
        $schema = [
            'enum' => ['a', 'b'],
            'const' => 'a',
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame(['a', 'b'], $result['enum']);
        $this->assertArrayNotHasKey('const', $result);
    }

    // ========================================
    // OAS 3.1 unsupported keywords (loud warning, not silent pass)
    //
    // Only `unevaluatedProperties` and `unevaluatedItems` are warned about:
    // opis Draft 06+ implements `patternProperties`, `contentMediaType`,
    // and `contentEncoding`, so warning that those are NOT enforced would
    // be misinformation.
    // ========================================

    #[Test]
    public function v31_unevaluated_properties_emits_warning(): void
    {
        $schema = [
            'type' => 'object',
            'unevaluatedProperties' => false,
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('unevaluatedProperties', (string) $captured);
    }

    #[Test]
    public function v31_unevaluated_items_emits_warning(): void
    {
        $schema = [
            'type' => 'array',
            'unevaluatedItems' => false,
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('unevaluatedItems', (string) $captured);
    }

    #[Test]
    public function pattern_properties_does_not_warn_because_opis_supports_it(): void
    {
        // Regression guard: a previous version of this converter wrongly
        // listed `patternProperties` as unsupported. opis Draft 06+ does
        // implement it (see vendor/opis/json-schema/src/Parsers/Drafts/Draft06.php),
        // so warning about it would mislead users into thinking their
        // constraint isn't enforced when it actually is.
        $schema = [
            'type' => 'object',
            'patternProperties' => ['^x-' => ['type' => 'string']],
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertNull($captured, 'patternProperties is supported by opis — must not warn');
    }

    #[Test]
    public function content_media_type_and_encoding_do_not_warn(): void
    {
        // Same as above: opis Draft 06+ implements both keywords.
        $schema = [
            'type' => 'string',
            'contentMediaType' => 'application/json',
            'contentEncoding' => 'base64',
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertNull($captured, 'contentMediaType / contentEncoding are supported — must not warn');
    }

    #[Test]
    public function repeated_calls_with_same_keyword_warn_only_once(): void
    {
        // Avoid log spam: warn once per process for a given keyword, not per
        // call. The schema converter keeps an internal seen-set.
        // (setUp() already reset the state.)
        $schema = [
            'type' => 'object',
            'unevaluatedProperties' => false,
        ];

        $count = 0;
        set_error_handler(static function (int $errno) use (&$count): bool {
            if ($errno === E_USER_WARNING) {
                $count++;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $count);
    }

    #[Test]
    public function multiple_unsupported_keywords_on_same_schema_warn_independently(): void
    {
        // The dedup is per-keyword, not per-call. A schema declaring two
        // unsupported keywords at once must surface two distinct warnings.
        $schema = [
            'type' => 'object',
            'unevaluatedProperties' => false,
            'unevaluatedItems' => false,
        ];

        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(2, $captured);
    }

    #[Test]
    public function unevaluated_properties_emits_warning_for_oas_3_0_too(): void
    {
        // unevaluatedProperties is a 2019-09 keyword that doesn't normally
        // appear in 3.0 specs but can in hand-rolled or generated specs that
        // mix dialects. The fix must run for both versions.
        $schema = [
            'type' => 'object',
            'unevaluatedProperties' => false,
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('unevaluatedProperties', (string) $captured);
    }

    // ========================================
    // dependentSchemas / dependentRequired silent-ignore warning (#216)
    // opis Draft 07 does not register either 2019-09 keyword, so the
    // property-dependency constraint is dropped wholesale and no error
    // surfaces. The warning makes that silent bypass loud.
    // ========================================

    #[Test]
    public function dependent_schemas_emits_warning(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['creditCard' => ['type' => 'string']],
            'dependentSchemas' => [
                'creditCard' => [
                    'type' => 'object',
                    'required' => ['cvv'],
                ],
            ],
        ];

        $warnings = $this->captureWarnings(static function () use ($schema): void {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('dependentSchemas', $warnings[0]);
    }

    #[Test]
    public function dependent_required_emits_warning(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'cvv' => ['type' => 'string'],
            ],
            'dependentRequired' => ['creditCard' => ['cvv']],
        ];

        $warnings = $this->captureWarnings(static function () use ($schema): void {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('dependentRequired', $warnings[0]);
    }

    #[Test]
    public function dependent_keywords_emit_warning_for_oas_3_0_too(): void
    {
        // Both keywords are 2019-09 but can appear in hand-rolled or
        // generated 3.0 specs that mix dialects. The warning must run for
        // both versions, mirroring the unevaluated* handling.
        $schema = [
            'type' => 'object',
            'dependentRequired' => ['creditCard' => ['cvv']],
        ];

        $warnings = $this->captureWarnings(static function () use ($schema): void {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);
        });

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('dependentRequired', $warnings[0]);
    }

    #[Test]
    public function dependent_keywords_warn_independently_and_point_to_if_then_else(): void
    {
        // Per-keyword dedup: a schema declaring both keywords at once must
        // surface two distinct warnings, each pointing the user at the
        // Draft 07 equivalent (if/then/else).
        $schema = [
            'type' => 'object',
            'dependentSchemas' => [
                'creditCard' => ['type' => 'object', 'required' => ['cvv']],
            ],
            'dependentRequired' => ['billingAddress' => ['country']],
        ];

        $warnings = $this->captureWarnings(static function () use ($schema): void {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(2, $warnings);
        $joined = implode("\n", $warnings);
        $this->assertStringContainsString('dependentSchemas', $joined);
        $this->assertStringContainsString('dependentRequired', $joined);
        $this->assertStringContainsString('if/then/else', $joined);
    }

    #[Test]
    public function dependent_keyword_warns_only_once_across_repeated_calls(): void
    {
        // Core "one-shot per keyword per process" contract: three convert()
        // calls must still surface only one warning. A single-call test
        // cannot tell correct dedup apart from no dedup at all.
        // (setUp() already reset the seen-set.)
        $schema = [
            'type' => 'object',
            'dependentRequired' => ['creditCard' => ['cvv']],
        ];

        $warnings = $this->captureWarnings(static function () use ($schema): void {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('dependentRequired', $warnings[0]);
    }

    #[Test]
    public function dependent_keyword_nested_below_root_emits_warning(): void
    {
        // The warning fires from convertInPlace(), which recurses into every
        // subschema position. Real specs almost always carry these keywords
        // on nested model definitions, not the root — pin that the warning
        // reaches a keyword buried inside `properties`.
        $schema = [
            'type' => 'object',
            'properties' => [
                'payment' => [
                    'type' => 'object',
                    'dependentSchemas' => [
                        'creditCard' => ['type' => 'object', 'required' => ['cvv']],
                    ],
                ],
            ],
        ];

        $warnings = $this->captureWarnings(static function () use ($schema): void {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('dependentSchemas', $warnings[0]);
    }

    #[Test]
    public function schema_without_dependent_keywords_emits_no_warning(): void
    {
        // Guard against a false positive: a schema declaring neither keyword
        // must stay silent, so the warning keeps signalling something real.
        $schema = [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'cvv' => ['type' => 'string'],
            ],
            'required' => ['creditCard'],
        ];

        $warnings = $this->captureWarnings(static function () use ($schema): void {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertSame([], $warnings);
    }

    // ========================================
    // discriminator.mapping enforcement via if/then lowering (#262)
    // ========================================

    #[Test]
    public function discriminator_with_mapping_lowers_to_if_then_when_enforced(): void
    {
        // With enforcement on, discriminator + mapping is rewritten into an
        // allOf of an unknown-value guard plus one if/then per mapping value,
        // so the discriminator value actually steers validation toward a single
        // branch — the gap #147's warning only narrated, now closed.
        $root = ['components' => ['schemas' => [
            'Cat' => ['type' => 'object', 'required' => ['meow'], 'properties' => ['meow' => ['type' => 'boolean']]],
            'Dog' => ['type' => 'object', 'required' => ['bark'], 'properties' => ['bark' => ['type' => 'boolean']]],
        ]]];
        $schema = [
            'type' => 'object',
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => [
                    'cat' => '#/components/schemas/Cat',
                    'dog' => '#/components/schemas/Dog',
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, $this->enforcing($root));

        $this->assertArrayNotHasKey('discriminator', $result);
        $this->assertArrayHasKey('allOf', $result);
        $this->assertCount(3, $result['allOf']);
        // [0] unknown-value guard: property present and one of the mapping keys.
        $this->assertSame(['kind' => ['enum' => ['cat', 'dog']]], $result['allOf'][0]['properties']);
        $this->assertSame(['kind'], $result['allOf'][0]['required']);
        // [1]/[2] per-value if/then routing to the resolved subtype.
        $this->assertSame(['kind' => ['enum' => ['cat']]], $result['allOf'][1]['if']['properties']);
        $this->assertSame(['kind'], $result['allOf'][1]['if']['required']);
        $this->assertSame(['meow'], $result['allOf'][1]['then']['required']);
        $this->assertSame(['kind' => ['enum' => ['dog']]], $result['allOf'][2]['if']['properties']);
        $this->assertSame(['bark'], $result['allOf'][2]['then']['required']);
    }

    #[Test]
    public function discriminator_mapping_accepts_bare_schema_name_shorthand(): void
    {
        // OAS allows a mapping value to be a bare schema name, shorthand for
        // `#/components/schemas/{name}`.
        $root = ['components' => ['schemas' => [
            'Cat' => ['type' => 'object', 'required' => ['meow']],
        ]]];
        $schema = [
            'type' => 'object',
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat']],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, $this->enforcing($root));

        $this->assertSame(['meow'], $result['allOf'][1]['then']['required']);
    }

    #[Test]
    public function openapi_32_discriminator_default_mapping_lowers_missing_and_unknown_branch(): void
    {
        $root = ['components' => ['schemas' => [
            'Cat' => ['type' => 'object', 'required' => ['meow']],
            'Other' => ['type' => 'object', 'required' => ['name']],
        ]]];
        $schema = [
            'type' => 'object',
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => ['cat' => 'Cat'],
                'defaultMapping' => 'Other',
            ],
        ];

        $result = OpenApiSchemaConverter::convert(
            $schema,
            OpenApiVersion::V3_2,
            SchemaContext::Response,
            $this->enforcing($root),
        );

        $this->assertSame(['kind' => ['enum' => ['cat']]], $result['allOf'][0]['if']['not']['properties']);
        $this->assertSame(['name'], $result['allOf'][0]['then']['required']);
        $this->assertSame(['meow'], $result['allOf'][1]['then']['required']);
    }

    #[Test]
    public function openapi_32_default_mapping_runs_after_implicit_schema_name_mapping(): void
    {
        $root = OpenApiRefResolver::resolve([
            'components' => ['schemas' => [
                'Cat' => ['type' => 'object', 'required' => ['meow']],
                'Dog' => ['type' => 'object', 'required' => ['bark']],
                'Other' => ['type' => 'object', 'required' => ['other']],
            ]],
            'schema' => [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    ['$ref' => '#/components/schemas/Dog'],
                    ['$ref' => '#/components/schemas/Other'],
                ],
                'discriminator' => [
                    'propertyName' => 'kind',
                    'mapping' => ['doggo' => 'Dog'],
                    'defaultMapping' => 'Other',
                ],
            ],
        ]);

        $lowered = OpenApiSchemaConverter::convert(
            $root['schema'],
            OpenApiVersion::V3_2,
            SchemaContext::Response,
            $this->enforcing($root),
        );
        $runner = new SchemaValidatorRunner(20);
        $schemaObject = ObjectConverter::convert($lowered);

        $this->assertSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert((object) ['kind' => 'Cat', 'meow' => true])),
            'an implicit Cat mapping must be selected before defaultMapping',
        );
        $this->assertSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert((object) ['kind' => 'doggo', 'bark' => true])),
            'an explicit mapping must continue to override the implicit schema name',
        );
        $this->assertSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert((object) ['kind' => 'unknown', 'other' => true])),
            'an unmapped value must use defaultMapping',
        );
        $this->assertNotSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert((object) ['kind' => 'Cat', 'other' => true])),
            'a known implicit value must not be routed to defaultMapping',
        );
    }

    #[Test]
    public function openapi_32_default_mapping_without_explicit_mapping_warns(): void
    {
        $root = ['components' => ['schemas' => [
            'Other' => ['type' => 'object', 'required' => ['name']],
        ]]];

        $warnings = $this->captureWarnings(fn() => OpenApiSchemaConverter::convert(
            [
                'type' => 'object',
                'discriminator' => [
                    'propertyName' => 'kind',
                    'defaultMapping' => 'Other',
                ],
            ],
            OpenApiVersion::V3_2,
            SchemaContext::Response,
            $this->enforcing($root),
        ));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('[OpenAPI 3.2 discriminator]', $warnings[0]);
    }

    #[Test]
    public function openapi_32_malformed_default_mapping_throws_when_enforced(): void
    {
        $this->expectException(MalformedDiscriminatorException::class);
        $this->expectExceptionMessage('discriminator.defaultMapping');

        OpenApiSchemaConverter::convert(
            ['discriminator' => ['propertyName' => 'kind', 'defaultMapping' => 42]],
            OpenApiVersion::V3_2,
            SchemaContext::Response,
            $this->enforcing(['components' => ['schemas' => []]]),
        );
    }

    #[Test]
    public function discriminator_then_subschema_is_recursively_converted(): void
    {
        // The resolved subtype is raw OAS and must itself be lowered — a 3.0
        // `nullable` inside it becomes a type array, not survive untouched.
        $root = ['components' => ['schemas' => [
            'Cat' => [
                'type' => 'object',
                'required' => ['meow'],
                'properties' => ['meow' => ['type' => 'string', 'nullable' => true]],
            ],
        ]]];
        $schema = [
            'type' => 'object',
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, $this->enforcing($root));

        $then = $result['allOf'][1]['then'];
        $this->assertSame(['string', 'null'], $then['properties']['meow']['type']);
        $this->assertArrayNotHasKey('nullable', $then['properties']['meow']);
    }

    #[Test]
    public function discriminator_with_sibling_oneof_is_preserved(): void
    {
        // When discriminator accompanies a oneOf, the union survives and the
        // lowered branches are appended alongside it (body must satisfy both).
        $root = ['components' => ['schemas' => [
            'Cat' => ['type' => 'object', 'required' => ['meow']],
        ]]];
        $schema = [
            'oneOf' => [['type' => 'object']],
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, $this->enforcing($root));

        $this->assertArrayHasKey('oneOf', $result);
        $this->assertArrayHasKey('allOf', $result);
        $this->assertCount(2, $result['allOf']); // guard + cat branch
    }

    #[Test]
    public function discriminator_self_referential_cycle_terminates_and_degrades(): void
    {
        // Eager $ref inlining means a subtype re-contains the base discriminator
        // (RsaJsonWebKey = allOf:[{inlined base}, {required:[n,e]}]). The
        // recursion guard strips the re-appearing discriminator instead of
        // re-lowering it — terminating without blow-up.
        $baseDiscriminator = [
            'propertyName' => 'kty',
            'mapping' => ['RSA' => '#/components/schemas/Rsa', 'EC' => '#/components/schemas/Ec'],
        ];
        $inlinedBase = [
            'type' => 'object',
            'required' => ['kty'],
            'properties' => ['kty' => ['enum' => ['RSA', 'EC']]],
            'discriminator' => $baseDiscriminator,
        ];
        $root = ['components' => ['schemas' => [
            'Rsa' => ['allOf' => [$inlinedBase, ['type' => 'object', 'required' => ['n', 'e']]]],
            'Ec' => ['allOf' => [$inlinedBase, ['type' => 'object', 'required' => ['crv', 'x', 'y']]]],
        ]]];
        $schema = [
            'type' => 'object',
            'required' => ['kty'],
            'properties' => ['kty' => ['enum' => ['RSA', 'EC']]],
            'discriminator' => $baseDiscriminator,
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, $this->enforcing($root));

        // Top level lowered, both branches present.
        $this->assertCount(3, $result['allOf']);
        // The RSA branch's resolved subtype: its inlined base discriminator was
        // stripped (no re-lowering), leaving the required[n,e] constraint.
        $rsaThen = $result['allOf'][1]['then'];
        $this->assertArrayNotHasKey('discriminator', $rsaThen['allOf'][0]);
        $this->assertSame(['n', 'e'], $rsaThen['allOf'][1]['required']);
    }

    #[Test]
    public function discriminator_enforced_lowering_rejects_lying_body_via_opis(): void
    {
        // End-to-end: the lowered schema, run through opis, fails a body that
        // lies about its type (the issue #262 motivating case) and passes a
        // truthful one.
        $root = ['components' => ['schemas' => [
            'Cat' => ['type' => 'object', 'required' => ['meow'], 'properties' => ['meow' => ['type' => 'boolean']]],
            'Dog' => ['type' => 'object', 'required' => ['bark'], 'properties' => ['bark' => ['type' => 'boolean']]],
        ]]];
        $schema = [
            'type' => 'object',
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog'],
            ],
        ];
        $lowered = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, $this->enforcing($root));

        $runner = new SchemaValidatorRunner(20);
        $schemaObject = ObjectConverter::convert($lowered);

        $this->assertSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert((object) ['kind' => 'cat', 'meow' => true])),
            'a truthful cat body must validate',
        );
        $this->assertNotSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert((object) ['kind' => 'cat', 'bark' => true])),
            'a body claiming kind=cat but carrying Dog-only fields must fail',
        );
        $this->assertNotSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert((object) ['kind' => 'fish', 'meow' => true])),
            'an unknown discriminator value must fail the guard',
        );
    }

    #[Test]
    public function discriminator_nested_distinct_discriminators_both_lower(): void
    {
        // Regression for the recursion-guard signature: a nested discriminator
        // that shares the outer's propertyName AND key set but maps to DIFFERENT
        // targets must still be lowered (not stripped as a false self-reference).
        // The signature folds in the resolved targets so the two do not collide.
        $root = ['components' => ['schemas' => [
            'OuterA' => [
                'type' => 'object',
                'discriminator' => [
                    'propertyName' => 'type',
                    'mapping' => ['a' => '#/components/schemas/Inner1', 'b' => '#/components/schemas/Inner2'],
                ],
            ],
            'OuterB' => ['type' => 'object'],
            'Inner1' => ['type' => 'object', 'required' => ['i1']],
            'Inner2' => ['type' => 'object', 'required' => ['i2']],
        ]]];
        $schema = [
            'type' => 'object',
            'discriminator' => [
                'propertyName' => 'type',
                'mapping' => ['a' => '#/components/schemas/OuterA', 'b' => '#/components/schemas/OuterB'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, $this->enforcing($root));

        // The "a" branch routes to OuterA, whose OWN (distinct) discriminator
        // must be lowered — not stripped — because its signature differs.
        $outerAThen = $result['allOf'][1]['then'];
        $this->assertArrayNotHasKey('discriminator', $outerAThen);
        $this->assertArrayHasKey('allOf', $outerAThen, 'nested distinct discriminator must be lowered, not stripped');
        $this->assertSame(['i1'], $outerAThen['allOf'][1]['then']['required']);
        $this->assertSame(['i2'], $outerAThen['allOf'][2]['then']['required']);
    }

    #[Test]
    public function discriminator_with_mapping_not_enforced_with_root_strips(): void
    {
        // The off path with a POPULATED root (the production "user set the flag
        // off" shape, distinct from the empty-root disabled() sentinel):
        // discriminator is stripped, nothing is lowered.
        $root = ['components' => ['schemas' => [
            'Cat' => ['type' => 'object', 'required' => ['meow']],
        ]]];
        $schema = [
            'type' => 'object',
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, new DiscriminatorContext($root, false));

        $this->assertArrayNotHasKey('discriminator', $result);
        $this->assertArrayNotHasKey('allOf', $result);
    }

    #[Test]
    public function discriminator_nullable_base_rejects_null_body_via_opis(): void
    {
        // Documented contract: a 3.0 `nullable` base carrying a discriminator
        // enforces the discriminated-object branch — a `null` body fails the
        // lowered guard (which requires the discriminator property).
        $root = ['components' => ['schemas' => [
            'Cat' => ['type' => 'object', 'required' => ['meow'], 'properties' => ['meow' => ['type' => 'boolean']]],
        ]]];
        $schema = [
            'type' => 'object',
            'nullable' => true,
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']],
        ];
        $lowered = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response, $this->enforcing($root));

        $runner = new SchemaValidatorRunner(20);
        $schemaObject = ObjectConverter::convert($lowered);

        $this->assertNotSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert(null)),
            'a null body must fail the discriminated-object branch',
        );
        $this->assertSame(
            [],
            $runner->validate($schemaObject, ObjectConverter::convert((object) ['kind' => 'cat', 'meow' => true])),
            'a valid cat body still validates',
        );
    }

    #[Test]
    public function discriminator_with_mapping_not_enforced_strips_without_warning(): void
    {
        // Default (no DiscriminatorContext) / disabled gate: discriminator is
        // stripped silently — the historical behaviour, now without the warning
        // (which was fatal under Laravel's error handler, #262).
        $schema = [
            'oneOf' => [['type' => 'object']],
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => ['cat' => '#/components/schemas/Cat'],
            ],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));
        $result = OpenApiSchemaConverter::convert($schema);

        $this->assertSame([], $warnings, 'no warning when not enforcing');
        $this->assertArrayNotHasKey('discriminator', $result);
        $this->assertArrayNotHasKey('allOf', $result, 'no lowering when not enforcing');
    }

    #[Test]
    public function discriminator_without_mapping_does_not_warn(): void
    {
        // Bare discriminator (propertyName only, no mapping) is just a
        // documentation hint — there is no "branch routing" semantics to
        // silently lose. Must not warn, otherwise users get noise for
        // spec-correct usage.
        $schema = [
            'oneOf' => [
                ['type' => 'object'],
                ['type' => 'object'],
            ],
            'discriminator' => [
                'propertyName' => 'kind',
            ],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

        $this->assertSame([], $warnings, 'discriminator without mapping must not warn');
    }

    #[Test]
    public function discriminator_with_empty_mapping_does_not_warn(): void
    {
        // `mapping: {}` is functionally equivalent to no mapping — nothing
        // to silently route, nothing to warn about.
        $schema = [
            'oneOf' => [['type' => 'object']],
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => [],
            ],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

        $this->assertSame([], $warnings);
    }

    #[Test]
    public function discriminator_missing_property_name_throws_when_enforced(): void
    {
        $this->expectException(MalformedDiscriminatorException::class);
        $this->expectExceptionMessage("'discriminator.propertyName'");

        OpenApiSchemaConverter::convert(
            ['discriminator' => ['mapping' => ['a' => '#/components/schemas/A']]],
            OpenApiVersion::V3_0,
            SchemaContext::Response,
            $this->enforcing(['components' => ['schemas' => ['A' => ['type' => 'object']]]]),
        );
    }

    #[Test]
    public function discriminator_non_string_property_name_throws_when_enforced(): void
    {
        $this->expectException(MalformedDiscriminatorException::class);

        OpenApiSchemaConverter::convert(
            ['discriminator' => ['propertyName' => 42, 'mapping' => ['a' => '#/components/schemas/A']]],
            OpenApiVersion::V3_0,
            SchemaContext::Response,
            $this->enforcing(['components' => ['schemas' => ['A' => ['type' => 'object']]]]),
        );
    }

    #[Test]
    public function discriminator_non_array_mapping_throws_when_enforced(): void
    {
        $this->expectException(MalformedDiscriminatorException::class);
        $this->expectExceptionMessage("'discriminator.mapping'");

        OpenApiSchemaConverter::convert(
            ['discriminator' => ['propertyName' => 'kind', 'mapping' => 'not-an-array']],
            OpenApiVersion::V3_0,
            SchemaContext::Response,
            $this->enforcing(['components' => ['schemas' => []]]),
        );
    }

    #[Test]
    public function discriminator_non_string_mapping_value_throws_when_enforced(): void
    {
        $this->expectException(MalformedDiscriminatorException::class);
        $this->expectExceptionMessage('discriminator.mapping[cat]');

        OpenApiSchemaConverter::convert(
            ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 123]]],
            OpenApiVersion::V3_0,
            SchemaContext::Response,
            $this->enforcing(['components' => ['schemas' => []]]),
        );
    }

    #[Test]
    public function discriminator_unresolvable_pointer_throws_when_enforced(): void
    {
        $this->expectException(MalformedDiscriminatorException::class);
        $this->expectExceptionMessage('does not resolve');

        OpenApiSchemaConverter::convert(
            ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Missing']]],
            OpenApiVersion::V3_0,
            SchemaContext::Response,
            $this->enforcing(['components' => ['schemas' => []]]),
        );
    }

    #[Test]
    public function discriminator_non_object_target_throws_when_enforced(): void
    {
        $this->expectException(MalformedDiscriminatorException::class);
        $this->expectExceptionMessage('must reference a schema object');

        OpenApiSchemaConverter::convert(
            ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']]],
            OpenApiVersion::V3_0,
            SchemaContext::Response,
            $this->enforcing(['components' => ['schemas' => ['Cat' => 'not-a-schema']]]),
        );
    }

    // ========================================
    // Unknown format silent-pass warning (#151)
    // ========================================

    #[Test]
    public function unknown_format_emits_warning(): void
    {
        // opis silently accepts unknown format values regardless of data,
        // so `format: emial` (typo) would pass any string. Pin that the
        // converter surfaces this as a loud signal.
        $schema = ['type' => 'string', 'format' => 'emial'];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("format 'emial'", $warnings[0]);
        $this->assertStringContainsString('not in opis', $warnings[0]);
    }

    #[Test]
    public function known_opis_formats_do_not_warn(): void
    {
        // Regression guard: every format opis Draft 06+ actually validates
        // must be silent. A future trim of the allowlist would surface here
        // as a noisy warning for spec-correct usage.
        $known = [
            'date', 'time', 'date-time', 'duration',
            'uri', 'uri-reference', 'uri-template', 'iri', 'iri-reference',
            'regex', 'ipv4', 'ipv6', 'hostname', 'idn-hostname',
            'uuid', 'email', 'idn-email',
            'json-pointer', 'relative-json-pointer',
        ];

        foreach ($known as $format) {
            OpenApiSchemaConverter::resetWarningStateForTesting();
            $schema = ['type' => 'string', 'format' => $format];

            $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

            $this->assertSame([], $warnings, "format '{$format}' is opis-supported and must not warn");
        }
    }

    #[Test]
    public function advisory_formats_do_not_warn(): void
    {
        // OAS hint formats are deliberately not enforced (README says so).
        // They must not trigger the unknown-format warning, otherwise users
        // get spammed for spec-correct usage of advisory hints.
        $advisory = ['int32', 'int64', 'float', 'double', 'byte', 'binary', 'password'];

        foreach ($advisory as $format) {
            OpenApiSchemaConverter::resetWarningStateForTesting();
            $schema = ['type' => 'string', 'format' => $format];

            $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

            $this->assertSame([], $warnings, "advisory format '{$format}' must not warn");
        }
    }

    #[Test]
    public function unknown_format_warns_once_per_format_value(): void
    {
        // Dedup is per format value, not per call. Two calls referencing the
        // same typo fire one warning; two distinct typos fire two.
        $count = 0;
        set_error_handler(static function (int $errno, string $errstr) use (&$count): bool {
            if ($errno === E_USER_WARNING && str_contains($errstr, "format '")) {
                $count++;

                return true;
            }

            return false;
        });

        try {
            OpenApiSchemaConverter::convert(['type' => 'string', 'format' => 'emial']);
            OpenApiSchemaConverter::convert(['type' => 'string', 'format' => 'emial']);
            OpenApiSchemaConverter::convert(['type' => 'string', 'format' => 'urll']);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(2, $count, 'two distinct unknown formats expected to fire two warnings');
    }

    #[Test]
    public function unknown_format_inside_nested_property_warns(): void
    {
        // Format keywords are most often nested inside `properties.X`. The
        // recursive descent in convertInPlace must reach them; pin that an
        // unknown format on a nested property triggers the warning, not just
        // a top-level one.
        $schema = [
            'type' => 'object',
            'properties' => [
                'contact' => ['type' => 'string', 'format' => 'phone'],
            ],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("format 'phone'", $warnings[0]);
    }

    #[Test]
    public function unknown_format_inside_array_items_warns(): void
    {
        // `convertInPlace` recurses into `items` (single-subschema form). A
        // typo'd format buried in an array element schema must reach the
        // walker, otherwise a refactor of the recursion paths could silently
        // regress this layer without any test catching it.
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'string', 'format' => 'phone'],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("format 'phone'", $warnings[0]);
    }

    #[Test]
    public function unknown_format_inside_oneof_branch_warns(): void
    {
        // Polymorphic schemas commonly hide format declarations inside
        // `oneOf` / `anyOf` / `allOf` branches. Pin that the recursion
        // reaches them — otherwise typo'd formats inside polymorphic specs
        // (the most common hand-rolled spec shape) silently pass.
        $schema = [
            'oneOf' => [
                ['type' => 'string', 'format' => 'phone'],
                ['type' => 'integer'],
            ],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("format 'phone'", $warnings[0]);
    }

    #[Test]
    public function unknown_format_inside_additional_properties_warns(): void
    {
        // `additionalProperties` as a typed subschema is the canonical
        // extension-fields shape. Format keywords there commonly come
        // through code-generated specs and rarely get human review.
        $schema = [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string', 'format' => 'phone'],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("format 'phone'", $warnings[0]);
    }

    #[Test]
    public function discriminator_with_non_array_mapping_is_stripped_when_not_enforced(): void
    {
        // Without enforcement the discriminator is stripped before its mapping
        // is ever inspected, so even a malformed `mapping` is tolerated
        // silently — the throw path is reserved for the enforced gate (see
        // discriminator_non_array_mapping_throws_when_enforced).
        $schema = [
            'oneOf' => [['type' => 'object']],
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => 'not-an-array',
            ],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));
        $result = OpenApiSchemaConverter::convert($schema);

        $this->assertSame([], $warnings);
        $this->assertArrayNotHasKey('discriminator', $result);
    }

    #[Test]
    public function unknown_keyword_warns_while_discriminator_is_stripped_when_not_enforced(): void
    {
        // `unevaluatedProperties` (Draft 07 doesn't implement) still warns, but
        // `discriminator.mapping` no longer does — it is lowered when enforced
        // and otherwise stripped silently (#262). So exactly one warning fires
        // here, and it is the unevaluatedProperties one.
        $schema = [
            'type' => 'object',
            'unevaluatedProperties' => false,
            'oneOf' => [['type' => 'object']],
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => ['a' => '#/components/schemas/A'],
            ],
        ];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('unevaluatedProperties', $warnings[0]);
    }

    #[Test]
    public function non_string_format_value_emits_malformed_warning(): void
    {
        // `format: 42` (or any non-string) is a malformed spec per OAS 3.x
        // §4.7. opis would silently swallow it; the converter surfaces the
        // defect with its own malformed-format warning so spec authors are
        // pushed to fix the type.
        $schema = ['type' => 'string', 'format' => 42];

        $warnings = $this->captureWarnings(static fn() => OpenApiSchemaConverter::convert($schema));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'format' must be a string", $warnings[0]);
        $this->assertStringContainsString('int', $warnings[0]);
    }

    // ========================================
    // $schema stripped from converter output (Draft 07 alignment)
    // ========================================

    #[Test]
    public function v31_schema_keyword_stripped(): void
    {
        // OAS 3.1 lets a spec author override the JSON Schema dialect via
        // `$schema` on an inline schema. If we keep that declaration, opis
        // will re-interpret our (already-lowered-to-Draft-07) schema under
        // 2020-12 and reject the array-form `items` we emit for prefixItems.
        // Strip `$schema` so the SchemaValidatorRunner's Draft 07 default
        // is the dialect that actually applies.
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('$schema', $result);
    }

    #[Test]
    public function v30_schema_keyword_stripped(): void
    {
        // 3.0 specs rarely declare `$schema`, but if they do, the same
        // alignment concern applies — strip it.
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'string',
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertArrayNotHasKey('$schema', $result);
    }

    #[Test]
    public function nested_schema_keyword_also_stripped(): void
    {
        // Recursion guard: $schema declared on an inner subschema must
        // also be stripped, not just the root.
        $schema = [
            'type' => 'object',
            'properties' => [
                'nested' => [
                    '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                    'type' => 'array',
                    'prefixItems' => [['type' => 'string']],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('$schema', $result['properties']['nested']);
    }

    // ========================================
    // OAS 3.1 examples-still-stripped (regression guard)
    // ========================================

    #[Test]
    public function v31_examples_keyword_still_stripped(): void
    {
        // `examples` was moved from DRAFT_2020_12_KEYS into OPENAPI_COMMON_KEYS.
        // The 3.1 path therefore reaches it through a different code branch
        // than before. Pin the behaviour explicitly so a future refactor
        // doesn't accidentally remove it from OPENAPI_COMMON_KEYS thinking
        // it is a 3.1-only concern (and breaks 3.0 too).
        $schema = [
            'type' => 'string',
            'examples' => ['foo', 'bar'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('examples', $result);
    }

    // ========================================
    // Recursion: handlers must apply at every depth
    // ========================================

    #[Test]
    public function v30_nullable_with_enum_appended_recursively_in_nested_property(): void
    {
        // Regression guard: handleNullable() runs on the outer schema only at
        // the outermost convert(), but convertInPlace recurses through
        // properties/items, so each subschema's nullable+enum should receive
        // the same treatment at its own level.
        $schema = [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'nullable' => true,
                    'enum' => ['active', 'inactive'],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertContains(null, $result['properties']['status']['enum']);
    }

    #[Test]
    public function v30_nullable_with_enum_appended_recursively_in_array_items(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'nullable' => true,
                'enum' => ['a', 'b'],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertContains(null, $result['items']['enum']);
    }

    #[Test]
    public function v31_const_lowered_recursively_in_nested_property(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'kind' => [
                    'const' => 'pet',
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result['properties']['kind']);
        $this->assertSame(['pet'], $result['properties']['kind']['enum']);
    }

    #[Test]
    public function v31_const_lowered_recursively_in_array_items(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'const' => 'fixed',
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result['items']);
        $this->assertSame(['fixed'], $result['items']['enum']);
    }

    // ========================================
    // Recursion: extended subschema positions (#214)
    // ----------------------------------------
    // convertInPlace must descend into every JSON-Schema subschema position
    // opis Draft 07 honours — otherwise OAS-only / 2020-12-only keywords
    // (nullable, readOnly/writeOnly, prefixItems, const, …) nested inside
    // them survive untouched and opis silently ignores them. Issue #214
    // identified five gaps: if/then/else, patternProperties, propertyNames,
    // contains, and dependentSchemas. The tests below pin each gap closed.
    // ========================================

    #[Test]
    public function v30_nullable_inside_pattern_properties_is_lowered(): void
    {
        $schema = [
            'type' => 'object',
            'patternProperties' => [
                '^x-' => ['type' => 'string', 'nullable' => true],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['patternProperties']['^x-']['type']);
        $this->assertArrayNotHasKey('nullable', $result['patternProperties']['^x-']);
    }

    #[Test]
    public function v30_nullable_inside_property_names_is_lowered(): void
    {
        // propertyNames values validate object keys, which are always
        // strings — `nullable` is semantically nonsensical here. The test
        // intentionally exercises a degenerate input to pin the structural
        // descent: it confirms convertInPlace reaches propertyNames at all.
        $schema = [
            'type' => 'object',
            'propertyNames' => ['type' => 'string', 'nullable' => true],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['propertyNames']['type']);
        $this->assertArrayNotHasKey('nullable', $result['propertyNames']);
    }

    #[Test]
    public function v30_nullable_inside_if_branch_is_lowered(): void
    {
        // The `if` schema drives branch selection; an unconverted nullable
        // here would fail opis Draft 07 type matching and silently choose
        // the wrong branch.
        $schema = [
            'if' => ['type' => 'string', 'nullable' => true],
            'then' => ['type' => 'object'],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0);

        $this->assertSame(['string', 'null'], $result['if']['type']);
        $this->assertArrayNotHasKey('nullable', $result['if']);
    }

    #[Test]
    public function v31_prefix_items_inside_then_is_lowered_to_tuple_items(): void
    {
        // Bug 1 from issue #214: prefixItems nested inside `then` was not
        // recognised by opis Draft 07 (which only registers prefixItems on
        // Draft 2020-12). The lowering must happen at every depth.
        $schema = [
            'if' => ['properties' => ['kind' => ['const' => 'tuple']], 'required' => ['kind']],
            'then' => [
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
                    ],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey(
            'prefixItems',
            $result['then']['properties']['data'],
        );
        $this->assertSame(
            [['type' => 'string'], ['type' => 'integer']],
            $result['then']['properties']['data']['items'],
        );
    }

    #[Test]
    public function v31_prefix_items_inside_contains_is_lowered(): void
    {
        $schema = [
            'type' => 'array',
            'contains' => [
                'type' => 'array',
                'prefixItems' => [['type' => 'string']],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('prefixItems', $result['contains']);
        $this->assertSame([['type' => 'string']], $result['contains']['items']);
    }

    #[Test]
    public function v31_const_inside_else_is_lowered_to_enum(): void
    {
        $schema = [
            'if' => ['properties' => ['kind' => ['const' => 'a']]],
            'then' => ['type' => 'object'],
            'else' => [
                'properties' => [
                    'fallback' => ['type' => 'string', 'const' => 'use-default'],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result['else']['properties']['fallback']);
        $this->assertSame(['use-default'], $result['else']['properties']['fallback']['enum']);
    }

    #[Test]
    public function v31_const_lowered_inside_dependent_schemas(): void
    {
        // dependentSchemas is a 2019-09 keyword; opis Draft 07 ignores the
        // outer keyword entirely, but the inner schemas should still be
        // lowered for hygiene and to stay symmetric with peer positions.
        $schema = [
            'type' => 'object',
            'dependentSchemas' => [
                'creditCard' => [
                    'properties' => [
                        'currency' => ['type' => 'string', 'const' => 'USD'],
                    ],
                ],
            ],
        ];

        // The outer `dependentSchemas` keyword triggers the #216 silent-ignore
        // warning; this test is about inner-subschema lowering. Capture the
        // warning and assert its exact count so an unexpected extra warning
        // fails the test instead of being silently absorbed.
        $result = null;
        $warnings = $this->captureWarnings(static function () use ($schema, &$result): void {
            $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(1, $warnings, 'only the #216 dependentSchemas warning is expected');
        $this->assertArrayNotHasKey(
            'const',
            $result['dependentSchemas']['creditCard']['properties']['currency'],
        );
        $this->assertSame(
            ['USD'],
            $result['dependentSchemas']['creditCard']['properties']['currency']['enum'],
        );
    }

    #[Test]
    public function response_context_write_only_inside_pattern_properties_property_becomes_false(): void
    {
        // Bug 2 from issue #214: a writeOnly subproperty nested inside a
        // patternProperties entry must be replaced with boolean `false`
        // (forbidden) in Response context, the same way top-level
        // writeOnly properties are.
        $schema = [
            'type' => 'object',
            'patternProperties' => [
                '^x-' => [
                    'type' => 'object',
                    'properties' => [
                        'secret' => ['type' => 'string', 'writeOnly' => true],
                        'public' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert(
            $schema,
            OpenApiVersion::V3_0,
            SchemaContext::Response,
        );

        $this->assertFalse($result['patternProperties']['^x-']['properties']['secret']);
        $this->assertSame(
            ['type' => 'string'],
            $result['patternProperties']['^x-']['properties']['public'],
        );
    }

    #[Test]
    public function response_context_write_only_inside_then_property_becomes_false(): void
    {
        $schema = [
            'if' => ['properties' => ['kind' => ['enum' => ['admin']]], 'required' => ['kind']],
            'then' => [
                'properties' => [
                    'admin_secret' => ['type' => 'string', 'writeOnly' => true],
                ],
            ],
        ];

        $result = OpenApiSchemaConverter::convert(
            $schema,
            OpenApiVersion::V3_0,
            SchemaContext::Response,
        );

        $this->assertFalse($result['then']['properties']['admin_secret']);
    }

    #[Test]
    public function prefix_items_inside_then_actually_rejects_tuple_violation(): void
    {
        // End-to-end regression guard for the silent contract bypass from
        // issue #214: feed the converted schema through opis via the runner
        // and confirm a violating payload is actually surfaced as an error.
        $runner = new SchemaValidatorRunner(20);
        $schema = OpenApiSchemaConverter::convert(
            [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string'],
                    'data' => ['type' => 'array'],
                ],
                'if' => ['properties' => ['kind' => ['const' => 'tuple']], 'required' => ['kind']],
                'then' => [
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
            OpenApiVersion::V3_1,
        );

        $errors = $runner->validate(
            ObjectConverter::convert($schema),
            ObjectConverter::convert((object) ['kind' => 'tuple', 'data' => [123, 'oops']]),
        );

        $this->assertNotSame([], $errors, 'tuple ordering must be enforced inside `then`');
    }

    #[Test]
    public function write_only_inside_pattern_properties_actually_rejects_response_leak(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = OpenApiSchemaConverter::convert(
            [
                'type' => 'object',
                'patternProperties' => [
                    '^x-' => [
                        'type' => 'object',
                        'properties' => [
                            'secret' => ['type' => 'string', 'writeOnly' => true],
                            'public' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            OpenApiVersion::V3_0,
            SchemaContext::Response,
        );

        $errors = $runner->validate(
            ObjectConverter::convert($schema),
            ObjectConverter::convert((object) [
                'x-meta' => (object) ['secret' => 'leaked', 'public' => 'ok'],
            ]),
        );

        $this->assertNotSame([], $errors, 'writeOnly leak inside patternProperties must surface in Response');
    }

    #[Test]
    public function prefix_items_inside_pattern_properties_actually_rejects_tuple_violation(): void
    {
        // End-to-end guard for the map-loop body of the new recursion
        // (`patternProperties` values). `prefixItems` is opis-Draft-07-unknown,
        // so an un-lowered tuple silently passes — the regression we are
        // pinning. Cannot reuse `const` here because opis Draft 06+ enforces
        // `const` natively, masking the silent bypass and rendering the test
        // useless as a pre-fix regression guard.
        $runner = new SchemaValidatorRunner(20);
        $schema = OpenApiSchemaConverter::convert(
            [
                'type' => 'object',
                'patternProperties' => [
                    '^x-' => [
                        'type' => 'array',
                        'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
                    ],
                ],
            ],
            OpenApiVersion::V3_1,
        );

        $errors = $runner->validate(
            ObjectConverter::convert($schema),
            ObjectConverter::convert((object) ['x-tuple' => [123, 'oops']]),
        );

        $this->assertNotSame([], $errors, 'tuple ordering must be enforced inside patternProperties');
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_with_recursion_into_pattern_properties(): void
    {
        // Mirror `convert_does_not_mutate_input_schema_v31_with_sibling_items`
        // (the #213 immutability pin) for the newly added recursion sites:
        // writing through &$sub references must not leak back into the
        // caller's input array.
        $schema = [
            'type' => 'object',
            'patternProperties' => [
                '^x-' => [
                    'type' => 'object',
                    'properties' => [
                        'secret' => ['type' => 'string', 'writeOnly' => true],
                    ],
                ],
            ],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertSame($original, $schema);
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_with_recursion_into_if_then_else(): void
    {
        $schema = [
            'if' => ['type' => 'string', 'nullable' => true],
            'then' => [
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'prefixItems' => [['type' => 'string']],
                    ],
                ],
            ],
            'else' => [
                'properties' => [
                    'fallback' => ['type' => 'string', 'const' => 'x'],
                ],
            ],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        $this->assertSame($original, $schema);
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_with_multi_entry_pattern_properties(): void
    {
        // The classic `foreach (... as &$sub)` leak only manifests with
        // multiple entries: the by-reference alias survives the loop bound
        // to the last element, and any later write through that name would
        // overwrite that entry. The single-entry pin above passes even if
        // the trailing `unset($sub)` is dropped — this multi-entry version
        // is what genuinely guards the hazard.
        $schema = [
            'type' => 'object',
            'patternProperties' => [
                '^x-' => [
                    'type' => 'object',
                    'properties' => [
                        'secret' => ['type' => 'string', 'writeOnly' => true],
                    ],
                ],
                '^y-' => [
                    'type' => 'object',
                    'properties' => [
                        'another' => ['type' => 'string', 'writeOnly' => true],
                    ],
                ],
            ],
        ];
        $original = $schema;

        OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_0, SchemaContext::Response);

        $this->assertSame($original, $schema);
    }

    #[Test]
    public function convert_does_not_mutate_input_schema_with_multi_entry_dependent_schemas(): void
    {
        $schema = [
            'type' => 'object',
            'dependentSchemas' => [
                'creditCard' => [
                    'properties' => [
                        'currency' => ['type' => 'string', 'const' => 'USD'],
                    ],
                ],
                'wireTransfer' => [
                    'properties' => [
                        'routingNumber' => ['type' => 'string', 'const' => 'US'],
                    ],
                ],
            ],
        ];
        $original = $schema;

        // `dependentSchemas` triggers the #216 warning; this test asserts the
        // input array is not mutated. Assert the exact warning count so an
        // unexpected extra warning fails instead of being silently absorbed.
        $warnings = $this->captureWarnings(static function () use ($schema): void {
            OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(1, $warnings, 'only the #216 dependentSchemas warning is expected');
        $this->assertSame($original, $schema);
    }

    #[Test]
    public function boolean_schemas_at_new_recursion_sites_are_preserved(): void
    {
        // Boolean schemas (`true`/`false`) are legal at every subschema
        // position. The `is_array` guards in the recursion block skip them
        // by design — converting a bool would crash. Pin the no-op so a
        // future refactor that drops the guard surfaces here.
        $schema = [
            'if' => true,
            'then' => false,
            'else' => true,
            'contains' => false,
            'propertyNames' => true,
            'patternProperties' => ['^x-' => false],
            'dependentSchemas' => ['k' => true],
        ];

        // The `dependentSchemas` key triggers the #216 warning; this test is
        // about boolean-subschema preservation. Assert the exact warning
        // count so an unexpected extra warning fails instead of being
        // silently absorbed.
        $result = null;
        $warnings = $this->captureWarnings(static function () use ($schema, &$result): void {
            $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(1, $warnings, 'only the #216 dependentSchemas warning is expected');
        $this->assertSame($schema, $result);
    }

    #[Test]
    public function dependent_schemas_list_shaped_value_is_skipped(): void
    {
        // `dependentSchemas: { k: ["a", "b"] }` is a spec defect — that
        // list shape belongs under the sibling `dependentRequired`
        // keyword. The converter must not descend into it (descending a
        // list of property-name strings as if it were a schema is the
        // exact silent-routing class the rest of convertInPlace exists to
        // surface). Pin the no-op via shape equality.
        $schema = [
            'dependentSchemas' => [
                'creditCard' => ['billingAddress', 'cvv'],
            ],
        ];

        // The `dependentSchemas` key triggers the #216 warning; this test is
        // about list-shaped-value skipping. Assert the exact warning count so
        // an unexpected extra warning fails instead of being silently
        // absorbed.
        $result = null;
        $warnings = $this->captureWarnings(static function () use ($schema, &$result): void {
            $result = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);
        });

        $this->assertCount(1, $warnings, 'only the #216 dependentSchemas warning is expected');
        $this->assertSame($schema, $result);
    }

    #[Test]
    public function unknown_format_inside_pattern_properties_warns(): void
    {
        // warnIfUnknownFormat already dedups by format value across the
        // process; confirming it fires at depth is enough.
        $warnings = $this->captureWarnings(static function (): void {
            OpenApiSchemaConverter::convert(
                [
                    'type' => 'object',
                    'patternProperties' => [
                        '^x-' => ['type' => 'string', 'format' => 'emial'],
                    ],
                ],
                OpenApiVersion::V3_0,
            );
        });

        $this->assertNotSame([], $warnings, 'unknown format inside patternProperties must warn');
        $this->assertTrue(
            str_contains($warnings[0], "format 'emial'"),
            'warning must name the offending format value',
        );
    }

    #[Test]
    public function discriminator_inside_then_is_lowered_when_enforced(): void
    {
        // The converter recurses into `then` (#214), so a discriminator nested
        // there is lowered with the same enforce context — it does not survive
        // into the validator unenforced.
        $root = ['components' => ['schemas' => [
            'A' => ['type' => 'object', 'required' => ['foo']],
        ]]];
        $result = OpenApiSchemaConverter::convert(
            [
                'if' => ['properties' => ['kind' => ['type' => 'string']]],
                'then' => [
                    'discriminator' => [
                        'propertyName' => 'kind',
                        'mapping' => ['a' => '#/components/schemas/A'],
                    ],
                    'oneOf' => [['type' => 'object']],
                ],
            ],
            OpenApiVersion::V3_0,
            SchemaContext::Response,
            $this->enforcing($root),
        );

        $then = $result['then'];
        $this->assertArrayNotHasKey('discriminator', $then);
        $this->assertArrayHasKey('allOf', $then);
        $this->assertSame(['foo'], $then['allOf'][1]['then']['required']);
    }

    // ========================================
    // Falsy / collection const values
    // ========================================

    #[Test]
    public function v31_const_false_lowered_to_enum(): void
    {
        // `array_key_exists` correctly detects const: false; isset() would not.
        // Pin the implementation choice with an explicit test.
        $result = OpenApiSchemaConverter::convert(['const' => false], OpenApiVersion::V3_1);

        $this->assertArrayNotHasKey('const', $result);
        $this->assertSame([false], $result['enum']);
    }

    #[Test]
    public function v31_const_zero_lowered_to_enum(): void
    {
        $result = OpenApiSchemaConverter::convert(['const' => 0], OpenApiVersion::V3_1);

        $this->assertSame([0], $result['enum']);
    }

    #[Test]
    public function v31_const_empty_string_lowered_to_enum(): void
    {
        $result = OpenApiSchemaConverter::convert(['const' => ''], OpenApiVersion::V3_1);

        $this->assertSame([''], $result['enum']);
    }

    #[Test]
    public function v31_const_empty_array_lowered_to_enum(): void
    {
        $result = OpenApiSchemaConverter::convert(['const' => []], OpenApiVersion::V3_1);

        $this->assertSame([[]], $result['enum']);
    }

    /**
     * Build an enforcing DiscriminatorContext over a stub root spec for the
     * `discriminator.mapping` lowering tests (#262).
     *
     * @param array<string, mixed> $root
     */
    private function enforcing(array $root): DiscriminatorContext
    {
        return new DiscriminatorContext($root, true);
    }

    /**
     * Run the conversion and collect every `E_USER_WARNING` it triggers, in
     * order. Returns the empty list when no warnings fire. Multi-warning
     * tests can inspect the array directly; single-warning tests typically
     * read `$warnings[0] ?? null` and assert on that.
     *
     * Earlier revisions of this helper captured only the first warning and
     * silently absorbed subsequent ones — a code-review audit flagged that
     * shape as a silent-failure factory: a future test schema firing two
     * warnings would lose the second without trace. Collecting everything
     * keeps the visibility surface honest.
     *
     * @return string[]
     */
    private function captureWarnings(callable $fn): array
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $captured;
    }
}
