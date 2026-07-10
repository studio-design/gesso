<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\Spec\OpenApiRefResolver;

class OpenApiRefResolverTest extends TestCase
{
    #[Test]
    public function resolves_simple_internal_ref(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['id']],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['type' => 'object', 'required' => ['id']], $schema);
    }

    #[Test]
    public function records_implicit_schema_name_only_for_direct_composition_refs(): void
    {
        $marker = OpenApiRefResolver::IMPLICIT_SCHEMA_NAME_EXTENSION;
        $resolved = OpenApiRefResolver::resolve([
            'components' => ['schemas' => [
                'Cat' => ['type' => 'object', 'required' => ['meow']],
            ]],
            'schema' => [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    ['type' => 'object', $marker => 'Spoofed'],
                ],
            ],
        ]);

        $this->assertSame('Cat', $resolved['schema']['oneOf'][0][$marker]);
        $this->assertArrayNotHasKey($marker, $resolved['schema']['oneOf'][1]);
    }

    #[Test]
    public function resolves_nested_refs(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'A' => ['$ref' => '#/components/schemas/B'],
                    'B' => ['$ref' => '#/components/schemas/C'],
                    'C' => ['type' => 'string'],
                ],
            ],
            'paths' => [
                '/x' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/A'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/x']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['type' => 'string'], $schema);
    }

    #[Test]
    public function resolves_ref_inside_resolved_target(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['$ref' => '#/components/schemas/Category'],
                        ],
                    ],
                    'Category' => ['type' => 'string'],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string'],
                ],
            ],
            $schema,
        );
    }

    #[Test]
    public function resolves_ref_with_json_pointer_escapes(): void
    {
        // Segment containing '/' is encoded as '~1'; '~' is encoded as '~0'.
        // Order when decoding matters: '~1' first, then '~0'.
        $spec = [
            'components' => [
                'schemas' => [
                    'weird/name~with~tildes' => ['type' => 'string'],
                ],
            ],
            'paths' => [
                '/x' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => '#/components/schemas/weird~1name~0with~0tildes',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/x']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['type' => 'string'], $schema);
    }

    #[Test]
    public function resolves_ref_with_url_encoded_segment(): void
    {
        // OpenAPI path keys with '/' are legal; inside a JSON Pointer they get '~1',
        // but consumers sometimes URL-encode too. Accept both.
        $spec = [
            'paths' => [
                '/pets' => ['get' => ['operationId' => 'listPets']],
            ],
            'x-alias' => ['$ref' => '#/paths/~1pets'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(['get' => ['operationId' => 'listPets']], $resolved['x-alias']);
    }

    #[Test]
    public function ref_with_siblings_drops_siblings(): void
    {
        // Per OAS 3.0, sibling keys of $ref are ignored. The resolver should
        // replace the node entirely with the target.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['id']],
                ],
            ],
            'x-holder' => [
                '$ref' => '#/components/schemas/Pet',
                'description' => 'ignored per OAS 3.0',
                'example' => 'ignored too',
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $resolved['x-holder']);
    }

    #[Test]
    public function leaves_spec_without_refs_unchanged(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'no refs', 'version' => '1.0'],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'description' => 'ok',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($spec, OpenApiRefResolver::resolve($spec));
    }

    #[Test]
    public function resolves_ref_targeting_array_index(): void
    {
        // RFC 6901 allows numeric segments to address list elements. `$ref`s
        // pointing to `parameters[0]` are common when aliasing operation-level
        // definitions; the walker must descend through numeric keys correctly.
        $spec = [
            'paths' => [
                '/pets' => [
                    'parameters' => [
                        [
                            'name' => 'petId',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/paths/~1pets/parameters/0'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame('petId', $resolved['x-alias']['name']);
        $this->assertSame('path', $resolved['x-alias']['in']);
    }

    #[Test]
    public function resolves_ref_to_empty_object_target(): void
    {
        // An empty-object schema is legal (matches anything). The walker's
        // foreach must handle the zero-child case cleanly.
        $spec = [
            'components' => [
                'schemas' => [
                    'AnyShape' => [],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/AnyShape'],
        ];

        $this->assertSame([], OpenApiRefResolver::resolve($spec)['x-alias']);
    }

    #[Test]
    public function resolves_ref_with_utf8_segment_url_encoded(): void
    {
        // Non-ASCII schema names are valid JSON. URL-aware tooling often
        // percent-encodes them in refs; rawurldecode() must run before the
        // JSON Pointer escape unwinding so both forms resolve.
        $spec = [
            'components' => [
                'schemas' => [
                    'ペット' => ['type' => 'string'],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/%E3%83%9A%E3%83%83%E3%83%88'],
        ];

        $this->assertSame(
            ['type' => 'string'],
            OpenApiRefResolver::resolve($spec)['x-alias'],
        );
    }

    #[Test]
    public function resolves_ref_inside_all_of_composition(): void
    {
        // allOf/oneOf/anyOf arrays are the most common place `$ref` appears
        // in practice. A regression in list-index walking would surface here
        // first.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['id']],
                    'Named' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Pet'],
                            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/Named'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['type' => 'object', 'required' => ['id']],
            $resolved['x-alias']['allOf'][0],
        );
    }

    #[Test]
    public function throws_local_ref_requires_source_file_when_no_source_passed(): void
    {
        // Legacy single-arg `resolve()` cannot decide where `./other.json`
        // lives, so external refs are rejected up front rather than
        // silently mishandled. See OpenApiRefResolverExternalRefsTest for
        // the positive paths when a source file is supplied.
        $spec = [
            'components' => [
                'schemas' => [
                    'Ref' => ['$ref' => 'other.json#/components/schemas/Pet'],
                ],
            ],
        ];

        try {
            OpenApiRefResolver::resolve($spec);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::LocalRefRequiresSourceFile, $e->reason);
            $this->assertStringContainsString('other.json', $e->getMessage());
        }
    }

    #[Test]
    public function throws_remote_ref_disallowed_when_allow_remote_refs_is_off(): void
    {
        // HTTP(S) ref resolution is opt-in. The default `allowRemoteRefs:
        // false` rejects every remote ref before any HTTP client check
        // runs, so a misconfigured wiring cannot accidentally hit the
        // network.
        $spec = [
            'components' => [
                'schemas' => [
                    'Ref' => ['$ref' => 'https://example.com/spec.json#/components/schemas/Pet'],
                ],
            ],
        ];

        try {
            OpenApiRefResolver::resolve($spec);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefDisallowed, $e->reason);
        }
    }

    #[Test]
    public function throws_on_direct_self_reference(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Node' => [
                        'type' => 'object',
                        'properties' => [
                            'self' => ['$ref' => '#/components/schemas/Node'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/nodes' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Node'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_indirect_circular_ref(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'A' => [
                        'type' => 'object',
                        'properties' => [
                            'b' => ['$ref' => '#/components/schemas/B'],
                        ],
                    ],
                    'B' => [
                        'type' => 'object',
                        'properties' => [
                            'a' => ['$ref' => '#/components/schemas/A'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/a' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/A'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_three_node_circular_ref(): void
    {
        // The 2-node case is covered above. A 3-node cycle exercises the
        // chain length beyond the trivial minimum; a regression that only
        // checks the immediate parent would slip past.
        $spec = [
            'components' => [
                'schemas' => [
                    'A' => [
                        'properties' => [
                            'next' => ['$ref' => '#/components/schemas/B'],
                        ],
                    ],
                    'B' => [
                        'properties' => [
                            'next' => ['$ref' => '#/components/schemas/C'],
                        ],
                    ],
                    'C' => [
                        'properties' => [
                            'next' => ['$ref' => '#/components/schemas/A'],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/A'],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_unresolvable_ref(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object'],
                ],
            ],
            'paths' => [
                '/x' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DoesNotExist'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_unresolvable_ref_under_non_json_media_type(): void
    {
        // Pins the invariant that resolution is media-type-agnostic: a future
        // "skip non-JSON branches to save walk cost" optimization must not
        // silently mask broken $refs hiding under application/xml, text/plain,
        // multipart/*, etc. A broken spec is broken regardless of Content-Type.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object'],
                ],
            ],
            'paths' => [
                '/x' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/xml' => [
                                    'schema' => ['$ref' => '#/components/schemas/DoesNotExist'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_non_string_ref(): void
    {
        $spec = [
            'paths' => [
                '/x' => [
                    'post' => [
                        'requestBody' => [
                            '$ref' => ['not', 'a', 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Invalid $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_when_ref_target_is_not_an_object(): void
    {
        // Refs must point to object/array structures (schemas, responses, parameters, etc).
        // A ref landing on a scalar (e.g. info.version) is a malformed spec.
        $spec = [
            'info' => ['version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'Bad' => ['$ref' => '#/info/version'],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('not an object');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_when_ref_target_is_literal_null_not_unresolvable(): void
    {
        // A key that exists but holds `null` is a present-but-invalid target.
        // Distinguishing this from a genuinely missing segment avoids telling
        // the author "target not found" when the target is right there.
        $spec = [
            'x-placeholder' => null,
            'components' => [
                'schemas' => [
                    'Bad' => ['$ref' => '#/x-placeholder'],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('not an object');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_bare_fragment_ref_with_clear_message(): void
    {
        // A bare fragment like '#foo' or just '#' is neither a JSON Pointer
        // nor an external URL. Surface it with a dedicated message so the
        // author doesn't mistake it for a bundling instruction.
        $spec = [
            'components' => [
                'schemas' => [
                    'Bad' => ['$ref' => '#foo'],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('bare fragment');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function throws_on_root_pointer_ref(): void
    {
        // `$ref: "#/"` points at the spec root. Substituting it would recurse
        // unboundedly before cycle detection kicks in; reject with a specific
        // message so the author sees the real problem.
        $spec = [
            'x-bad' => ['$ref' => '#/'],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('root pointer');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function resolves_the_same_ref_from_multiple_sites(): void
    {
        // A schema referenced twice from different operations should resolve
        // at both sites without any cross-talk or cycle false-positive.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                ],
            ],
            'paths' => [
                '/a' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/b' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $expected = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        $this->assertSame($expected, $resolved['paths']['/a']['get']['responses']['200']['content']['application/json']['schema']);
        $this->assertSame($expected, $resolved['paths']['/b']['get']['responses']['200']['content']['application/json']['schema']);
    }

    #[Test]
    public function resolves_refs_across_mixed_json_and_non_json_content(): void
    {
        // Pins first-match-wins independence: when a single content map
        // declares multiple media types, every sibling $ref must be resolved,
        // not just the first JSON-compatible one. Guards against a future
        // "resolve the JSON branch and stop" optimization leaving non-JSON
        // refs untouched — downstream code must never observe a half-inlined
        // content map.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['name']],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'post' => [
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Pet'],
                                ],
                                'application/xml' => [
                                    'schema' => ['$ref' => '#/components/schemas/Pet'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $expectedSchema = ['type' => 'object', 'required' => ['name']];
        $content = $resolved['paths']['/pets']['post']['requestBody']['content'];
        $this->assertSame($expectedSchema, $content['application/json']['schema']);
        $this->assertSame($expectedSchema, $content['application/xml']['schema']);
    }

    #[Test]
    public function resolves_refs_under_non_json_response_content(): void
    {
        // Symmetric counterpart of the requestBody test above: non-JSON $refs
        // in response content must also resolve. Without this pin, a future
        // "walk only requestBody.content" optimization could ship undetected
        // because the sibling test above wouldn't exercise the responses tree.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['id']],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/xml' => [
                                        'schema' => ['$ref' => '#/components/schemas/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/pets']['get']['responses']['200']['content']['application/xml']['schema'];
        $this->assertSame(['type' => 'object', 'required' => ['id']], $schema);
    }

    #[Test]
    public function resolves_schema_with_ref_as_property_name(): void
    {
        // Specs for JSON Patch / JSON-LD / JSON Schema payloads legally describe
        // an object with a property literally named `$ref`. The walker must
        // treat keys inside a `properties` map as property names, not as
        // Reference Object markers — otherwise load throws on a valid spec.
        $spec = [
            'components' => [
                'schemas' => [
                    'JsonPatch' => [
                        'type' => 'object',
                        'properties' => [
                            '$ref' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/patches' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/JsonPatch'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $schema = $resolved['paths']['/patches']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(
            [
                'type' => 'object',
                'properties' => [
                    '$ref' => ['type' => 'string'],
                    'value' => ['type' => 'string'],
                ],
            ],
            $schema,
        );
    }

    #[Test]
    public function resolves_ref_inside_schema_with_ref_property_name(): void
    {
        // Pins that the property-name guard is one level deep only: the entry
        // `properties.$ref` is a schema, and if that schema itself is a
        // Reference Object it must still resolve at the next level.
        $spec = [
            'components' => [
                'schemas' => [
                    'Label' => ['type' => 'string'],
                    'Holder' => [
                        'type' => 'object',
                        'properties' => [
                            '$ref' => ['$ref' => '#/components/schemas/Label'],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/Holder'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            [
                'type' => 'object',
                'properties' => [
                    '$ref' => ['type' => 'string'],
                ],
            ],
            $resolved['x-alias'],
        );
    }

    #[Test]
    public function resolves_schema_with_ref_as_pattern_property_name(): void
    {
        // `patternProperties` has the same shape as `properties` (dict of
        // schemas keyed by arbitrary strings). The same context guard applies.
        $spec = [
            'components' => [
                'schemas' => [
                    'PatternHolder' => [
                        'type' => 'object',
                        'patternProperties' => [
                            '$ref' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/PatternHolder'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            [
                'type' => 'object',
                'patternProperties' => [
                    '$ref' => ['type' => 'string'],
                ],
            ],
            $resolved['x-alias'],
        );
    }

    #[Test]
    public function resolves_schema_with_ref_as_defs_name(): void
    {
        $spec = [
            'schema' => [
                '$defs' => [
                    '$ref' => ['type' => 'string'],
                ],
            ],
        ];

        $this->assertSame($spec, OpenApiRefResolver::resolve($spec));
    }

    #[Test]
    public function resolves_schema_with_ref_as_dependent_schema_property_name(): void
    {
        $spec = [
            'schema' => [
                'dependentSchemas' => [
                    '$ref' => ['type' => 'string'],
                ],
            ],
        ];

        $this->assertSame($spec, OpenApiRefResolver::resolve($spec));
    }

    #[Test]
    public function resolves_ref_inside_defs_entry_named_like_a_named_map_keyword(): void
    {
        $resolved = OpenApiRefResolver::resolve([
            'components' => [
                'schemas' => [
                    'Target' => ['type' => 'string'],
                ],
            ],
            'schema' => [
                '$defs' => [
                    '$defs' => [
                        '$ref' => '#/components/schemas/Target',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['type' => 'string'],
            $resolved['schema']['$defs']['$defs'],
        );
    }

    #[Test]
    public function resolves_ref_as_additional_properties_schema(): void
    {
        // `additionalProperties` holds a single schema, not a dict of schemas,
        // so a $ref directly under it is a legitimate Reference Object and
        // must still resolve. Pins that `additionalProperties` is deliberately
        // excluded from the property-name guard.
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object', 'required' => ['id']],
                ],
            ],
            'x-alias' => [
                'type' => 'object',
                'additionalProperties' => ['$ref' => '#/components/schemas/Pet'],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['type' => 'object', 'required' => ['id']],
            $resolved['x-alias']['additionalProperties'],
        );
    }

    #[Test]
    public function resolves_nested_ref_as_property_name_inside_all_of(): void
    {
        // Pins that the property-name guard applies exactly at the children
        // of properties/patternProperties, not at every descendant. A sibling
        // $ref at a deeper schema level (inside allOf, here) must still
        // resolve as a Reference Object.
        $spec = [
            'components' => [
                'schemas' => [
                    'Label' => ['type' => 'string'],
                    'Holder' => [
                        'allOf' => [
                            [
                                'type' => 'object',
                                'properties' => [
                                    '$ref' => ['type' => 'string'],
                                    'label' => ['$ref' => '#/components/schemas/Label'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/Holder'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            [
                'allOf' => [
                    [
                        'type' => 'object',
                        'properties' => [
                            '$ref' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            $resolved['x-alias'],
        );
    }

    #[Test]
    public function throws_on_malformed_ref_as_value_of_ref_property(): void
    {
        // The property-name guard resets exactly one level deep. When a
        // `properties.$ref` entry's VALUE is itself a malformed Reference
        // Object (non-string $ref), the resolver must still throw — otherwise
        // the one-level reset would widen into a broader silent-pass hole.
        $spec = [
            'x-holder' => [
                'properties' => [
                    '$ref' => [
                        '$ref' => ['not', 'a', 'string'],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Invalid $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function preserves_ref_key_inside_schema_default_value(): void
    {
        // Schema Object's `default` is opaque user data per JSON Schema —
        // a `$ref` key inside it is a literal data field, not a Reference Object.
        $spec = [
            'components' => [
                'schemas' => [
                    'Config' => [
                        'type' => 'object',
                        'default' => ['$ref' => '#/components/schemas/Other'],
                    ],
                    'Other' => ['type' => 'string'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['$ref' => '#/components/schemas/Other'],
            $resolved['components']['schemas']['Config']['default'],
        );
    }

    #[Test]
    public function preserves_ref_key_inside_schema_example_value(): void
    {
        // Schema Object's singular `example` is opaque user data per OAS 3.0.
        $spec = [
            'components' => [
                'schemas' => [
                    'Doc' => [
                        'type' => 'object',
                        'example' => ['$ref' => '#/components/schemas/Other'],
                    ],
                    'Other' => ['type' => 'string'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['$ref' => '#/components/schemas/Other'],
            $resolved['components']['schemas']['Doc']['example'],
        );
    }

    #[Test]
    public function preserves_ref_key_inside_enum_member_object(): void
    {
        // JSON Schema permits object-shaped enum members; each is opaque.
        $spec = [
            'components' => [
                'schemas' => [
                    'Choices' => [
                        'enum' => [
                            ['$ref' => '#/components/schemas/Other'],
                            ['kind' => 'b'],
                        ],
                    ],
                    'Other' => ['type' => 'string'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            [
                ['$ref' => '#/components/schemas/Other'],
                ['kind' => 'b'],
            ],
            $resolved['components']['schemas']['Choices']['enum'],
        );
    }

    #[Test]
    public function preserves_ref_key_inside_const_value(): void
    {
        // OAS 3.1 `const` is opaque. Object-shaped const is rare but valid.
        $spec = [
            'components' => [
                'schemas' => [
                    'Fixed' => [
                        'const' => ['$ref' => '#/components/schemas/Other'],
                    ],
                    'Other' => ['type' => 'string'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['$ref' => '#/components/schemas/Other'],
            $resolved['components']['schemas']['Fixed']['const'],
        );
    }

    #[Test]
    public function preserves_ref_keys_inside_schema_3_1_examples_list(): void
    {
        // OAS 3.1 Schema Object `examples` is a list of opaque values
        // (distinct from the Parameter/MediaType `examples` MAP).
        $spec = [
            'components' => [
                'schemas' => [
                    'Doc' => [
                        'type' => 'object',
                        'examples' => [
                            ['$ref' => '#/components/schemas/Other'],
                            ['plain' => 'data'],
                        ],
                    ],
                    'Other' => ['type' => 'string'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            [
                ['$ref' => '#/components/schemas/Other'],
                ['plain' => 'data'],
            ],
            $resolved['components']['schemas']['Doc']['examples'],
        );
    }

    #[Test]
    public function resolves_examples_map_entry_that_is_a_reference_object(): void
    {
        // REGRESSION GUARD (issue #219): when `examples` is a MAP (Parameter /
        // MediaType / RequestBody shape), each entry MAY itself be a
        // Reference Object — those must still resolve. This is what
        // distinguishes the map form from the Schema 3.1 list form
        // (all-opaque).
        $spec = [
            'components' => [
                'examples' => [
                    'BarExample' => [
                        'summary' => 'Shared library entry',
                        'value' => ['greeting' => 'hello'],
                    ],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'tag',
                                'in' => 'query',
                                'schema' => ['type' => 'string'],
                                'examples' => [
                                    'Foo' => ['$ref' => '#/components/examples/BarExample'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            [
                'summary' => 'Shared library entry',
                'value' => ['greeting' => 'hello'],
            ],
            $resolved['paths']['/pets']['get']['parameters'][0]['examples']['Foo'],
        );
    }

    #[Test]
    public function preserves_ref_key_inside_example_object_value_field(): void
    {
        // Example Object's `value` field is opaque per OAS 3.x. The Example
        // Object itself may resolve as a Reference Object (pinned by
        // `resolves_examples_map_entry_that_is_a_reference_object`), but its
        // `value` content is literal data.
        $spec = [
            'components' => [
                'examples' => [
                    'PatchExample' => [
                        'summary' => 'A JSON Patch example',
                        'value' => ['$ref' => '#/components/schemas/Other'],
                    ],
                ],
                'schemas' => [
                    'Other' => ['type' => 'string'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['$ref' => '#/components/schemas/Other'],
            $resolved['components']['examples']['PatchExample']['value'],
        );
    }

    #[Test]
    public function preserves_ref_key_inside_server_variable_default(): void
    {
        // Server Variable's `default` is documented to be a string, but the
        // walker is structural — confirm the universal-opaque-key carve-out
        // really IS universal across all OAS object types, not just Schema.
        $spec = [
            'servers' => [
                [
                    'url' => 'https://{tenant}.api.example.com',
                    'variables' => [
                        'tenant' => [
                            'default' => ['$ref' => '#/components/schemas/Other'],
                            'description' => 'Tenant slug',
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Other' => ['type' => 'string'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['$ref' => '#/components/schemas/Other'],
            $resolved['servers'][0]['variables']['tenant']['default'],
        );
    }

    #[Test]
    public function throws_on_non_string_ref_inside_examples_map_entry(): void
    {
        // Pins that the `walkExamplesMap()` ref-validation produces the same
        // NonStringRef diagnostic as the main walk() — drift between the two
        // sites would let a malformed examples-map entry surface a less
        // informative error than the rest of the spec.
        $spec = [
            'paths' => [
                '/pets' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'tag',
                                'in' => 'query',
                                'schema' => ['type' => 'string'],
                                'examples' => [
                                    'Bad' => ['$ref' => ['not', 'a', 'string']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Invalid $ref: expected string, got array');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function detects_circular_ref_through_examples_map_entry(): void
    {
        // Cycles that go through the map-entry Reference Object path must
        // still be detected — the new walkExamplesMap() helper threads the
        // resolver's `$chain` into the recursive walk and resolveRef calls.
        $spec = [
            'components' => [
                'examples' => [
                    'A' => ['$ref' => '#/components/examples/B'],
                    'B' => ['$ref' => '#/components/examples/A'],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'tag',
                                'in' => 'query',
                                'schema' => ['type' => 'string'],
                                'examples' => [
                                    'Entry' => ['$ref' => '#/components/examples/A'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiRefResolver::resolve($spec);
    }

    #[Test]
    public function examples_map_entry_ref_drops_sibling_fields(): void
    {
        // Existing `ref_with_siblings_drops_siblings` covers the general case
        // via the main walk(); this pins the same OAS 3.0 rule on the new
        // examples-map code path so the two stay in agreement.
        $spec = [
            'components' => [
                'examples' => [
                    'Bar' => ['summary' => 'shared', 'value' => 'real'],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'tag',
                                'in' => 'query',
                                'schema' => ['type' => 'string'],
                                'examples' => [
                                    'Foo' => [
                                        '$ref' => '#/components/examples/Bar',
                                        'summary' => 'sibling — dropped',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            ['summary' => 'shared', 'value' => 'real'],
            $resolved['paths']['/pets']['get']['parameters'][0]['examples']['Foo'],
        );
    }

    #[Test]
    public function resolves_ref_inside_property_literally_named_default(): void
    {
        // The opaque-key carve-out is structural (skip the value of a
        // `default` field) — it MUST NOT widen to "skip anywhere a key
        // named `default` appears." A schema with a property *named*
        // `default` whose value is a Reference Object must still resolve.
        $spec = [
            'components' => [
                'schemas' => [
                    'Label' => ['type' => 'string'],
                    'Settings' => [
                        'type' => 'object',
                        'properties' => [
                            'default' => ['$ref' => '#/components/schemas/Label'],
                        ],
                    ],
                ],
            ],
            'x-alias' => ['$ref' => '#/components/schemas/Settings'],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'default' => ['type' => 'string'],
                ],
            ],
            $resolved['x-alias'],
        );
    }

    #[Test]
    public function resolves_ref_inside_example_object_non_value_field(): void
    {
        // Pins the documented scope of the Example Object carve-out: only
        // the `value` field is opaque. Other array-shaped fields (typically
        // a vendor extension like `x-shared`) must still have `$ref`s
        // resolved so the carve-out cannot accidentally widen into
        // "skip everything except `$ref` inside an examples-map entry".
        $spec = [
            'components' => [
                'schemas' => [
                    'Label' => ['type' => 'string'],
                ],
            ],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'parameters' => [
                            [
                                'name' => 'tag',
                                'in' => 'query',
                                'schema' => ['type' => 'string'],
                                'examples' => [
                                    'Foo' => [
                                        'summary' => 'with vendor extension',
                                        'value' => ['plain' => 'data'],
                                        'x-shared' => ['$ref' => '#/components/schemas/Label'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $entry = $resolved['paths']['/pets']['get']['parameters'][0]['examples']['Foo'];
        $this->assertSame(['type' => 'string'], $entry['x-shared']);
        // Sanity: value still opaque.
        $this->assertSame(['plain' => 'data'], $entry['value']);
    }
}
