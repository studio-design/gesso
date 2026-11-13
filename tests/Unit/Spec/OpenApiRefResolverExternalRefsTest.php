<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Spec\OpenApiRefResolver;
use Studio\Gesso\Tests\Helpers\RemovesDirectories;

use function file_put_contents;
use function json_encode;
use function memory_get_usage;
use function mkdir;
use function serialize;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unserialize;

class OpenApiRefResolverExternalRefsTest extends TestCase
{
    use RemovesDirectories;
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/oct-resolver-ext-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
        parent::tearDown();
    }

    #[Test]
    public function resolves_local_file_ref_without_fragment(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents(
            $this->workDir . '/pet.json',
            '{"type":"object","required":["id"],"properties":{"id":{"type":"integer"}}}',
        );

        $spec = [
            'openapi' => '3.0.3',
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => './pet.json'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($rootPath, '{}');

        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);

        $schema = $resolved['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['id'], $schema['required']);
    }

    #[Test]
    public function resolves_local_file_ref_with_json_pointer_fragment(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents(
            $this->workDir . '/schemas.json',
            '{"Pet":{"type":"object","required":["id"]},"Owner":{"type":"object"}}',
        );

        $spec = [
            'openapi' => '3.0.3',
            'paths' => [
                '/pets' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => './schemas.json#/Pet'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);

        $schema = $resolved['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['type' => 'object', 'required' => ['id']], $schema);
    }

    #[Test]
    public function resolves_yaml_external_ref_from_json_root(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/pet.yaml', "type: object\nrequired:\n  - id\n");

        $spec = [
            'openapi' => '3.0.3',
            'components' => ['schemas' => ['Pet' => ['$ref' => './pet.yaml']]],
        ];

        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $resolved['components']['schemas']['Pet']);
    }

    #[Test]
    public function resolves_chain_of_external_refs_across_files(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/a.json', '{"$ref":"./b.json"}');
        file_put_contents($this->workDir . '/b.json', '{"$ref":"./c.json"}');
        file_put_contents($this->workDir . '/c.json', '{"type":"string"}');

        $spec = ['components' => ['schemas' => ['Leaf' => ['$ref' => './a.json']]]];

        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);

        $this->assertSame(['type' => 'string'], $resolved['components']['schemas']['Leaf']);
    }

    #[Test]
    public function resolves_internal_ref_inside_external_document_against_that_documents_root(): void
    {
        // pet.json defines its own internal #/definitions/Pet — this must
        // resolve against pet.json's root, not the calling spec's root.
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents(
            $this->workDir . '/pet.json',
            '{"definitions":{"Pet":{"type":"object","required":["id"]}},"alias":{"$ref":"#/definitions/Pet"}}',
        );

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => './pet.json#/alias']]]];

        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $resolved['components']['schemas']['Pet']);
    }

    #[Test]
    public function detects_cycle_across_two_files(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/a.json', '{"$ref":"./b.json#/Loop"}');
        file_put_contents($this->workDir . '/b.json', '{"Loop":{"$ref":"./a.json"}}');

        $spec = ['components' => ['schemas' => ['Start' => ['$ref' => './a.json']]]];

        try {
            OpenApiRefResolver::resolve($spec, $rootPath);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::CircularRef, $e->reason);
        }
    }

    #[Test]
    public function reuses_loaded_document_for_sibling_refs_to_same_file(): void
    {
        // Two refs into the same file should not cause a cycle (cycle
        // detection must use the canonical absolute path + pointer, not
        // just file paths).
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents(
            $this->workDir . '/schemas.json',
            '{"Pet":{"type":"object"},"Owner":{"type":"string"}}',
        );

        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['$ref' => './schemas.json#/Pet'],
                    'Owner' => ['$ref' => './schemas.json#/Owner'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);

        $this->assertSame(['type' => 'object'], $resolved['components']['schemas']['Pet']);
        $this->assertSame(['type' => 'string'], $resolved['components']['schemas']['Owner']);
    }

    #[Test]
    public function throws_local_ref_requires_source_file_when_called_without_source(): void
    {
        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => './pet.json']]]];

        try {
            OpenApiRefResolver::resolve($spec);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::LocalRefRequiresSourceFile, $e->reason);
            $this->assertStringContainsString('./pet.json', $e->getMessage());
        }
    }

    #[Test]
    public function throws_remote_ref_disallowed_for_https_ref_when_flag_is_off(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => 'https://example.com/pet.json']]]];

        try {
            OpenApiRefResolver::resolve($spec, $rootPath);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefDisallowed, $e->reason);
            $this->assertStringContainsString('https://example.com/pet.json', $e->getMessage());
        }
    }

    #[Test]
    public function throws_remote_ref_disallowed_for_http_ref_when_flag_is_off(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => 'http://example.com/pet.json']]]];

        try {
            OpenApiRefResolver::resolve($spec, $rootPath);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefDisallowed, $e->reason);
        }
    }

    #[Test]
    public function throws_file_scheme_not_supported_for_file_url_ref(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => 'file:///etc/passwd']]]];

        try {
            OpenApiRefResolver::resolve($spec, $rootPath);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::FileSchemeNotSupported, $e->reason);
        }
    }

    #[Test]
    public function detects_whole_file_cycle_between_two_files(): void
    {
        // a.json -> b.json -> a.json with no fragment on either side.
        // This exercises the chainKey = absolutePath branch in
        // resolveExternalRef, which is distinct from the fragment branch
        // covered by detects_cycle_across_two_files.
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/a.json', '{"$ref":"./b.json"}');
        file_put_contents($this->workDir . '/b.json', '{"$ref":"./a.json"}');

        $spec = ['components' => ['schemas' => ['Start' => ['$ref' => './a.json']]]];

        try {
            OpenApiRefResolver::resolve($spec, $rootPath);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::CircularRef, $e->reason);
        }
    }

    #[Test]
    public function document_cache_is_per_resolution_call_not_global(): void
    {
        // The cache lives only for the duration of one resolve() call.
        // A second call against the same root spec must re-decode the
        // external file, picking up any change that happened between
        // calls. Without per-call scoping, hot-reloading specs in test
        // watchers would silently see stale data.
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        $petPath = $this->workDir . '/pet.json';
        file_put_contents($petPath, '{"type":"object"}');

        $spec1 = ['components' => ['schemas' => ['Pet' => ['$ref' => './pet.json']]]];
        $first = OpenApiRefResolver::resolve($spec1, $rootPath);
        $this->assertSame(['type' => 'object'], $first['components']['schemas']['Pet']);

        // Mutate the external file and resolve a fresh spec; the second
        // call must reflect the new content.
        file_put_contents($petPath, '{"type":"string"}');
        $spec2 = ['components' => ['schemas' => ['Pet' => ['$ref' => './pet.json']]]];
        $second = OpenApiRefResolver::resolve($spec2, $rootPath);
        $this->assertSame(['type' => 'string'], $second['components']['schemas']['Pet']);
    }

    #[Test]
    public function decodes_json_pointer_escapes_in_external_fragment(): void
    {
        // The external file has keys with literal `/` and `~` characters,
        // which JSON Pointer escapes as `~1` and `~0`. The fragment
        // splitter must hand the raw escapes to lookup() rather than
        // pre-decoding around the `#`.
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents(
            $this->workDir . '/schemas.json',
            '{"a/b":{"type":"object","required":["id"]},"c~d":{"type":"string"}}',
        );

        $spec = [
            'components' => [
                'schemas' => [
                    'Slash' => ['$ref' => './schemas.json#/a~1b'],
                    'Tilde' => ['$ref' => './schemas.json#/c~0d'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $resolved['components']['schemas']['Slash']);
        $this->assertSame(['type' => 'string'], $resolved['components']['schemas']['Tilde']);
    }

    #[Test]
    public function throws_non_object_target_for_external_scalar_fragment(): void
    {
        // Fragment lookup that lands on a scalar should throw the same
        // NonObjectRefTarget as the internal-ref path. Without an
        // external-ref-specific test for this branch, a regression
        // would silently substitute a string into a schema slot.
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/schemas.json', '{"Greeting":"hello"}');

        $spec = ['components' => ['schemas' => ['Bad' => ['$ref' => './schemas.json#/Greeting']]]];

        try {
            OpenApiRefResolver::resolve($spec, $rootPath);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::NonObjectRefTarget, $e->reason);
        }
    }

    #[Test]
    public function throws_bare_fragment_for_trailing_hash_without_pointer(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/pet.json', '{"type":"object"}');

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => './pet.json#']]]];

        try {
            OpenApiRefResolver::resolve($spec, $rootPath);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::BareFragmentRef, $e->reason);
        }
    }

    #[Test]
    public function throws_empty_ref_on_empty_string(): void
    {
        $spec = ['components' => ['schemas' => ['Bad' => ['$ref' => '']]]];

        try {
            OpenApiRefResolver::resolve($spec);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::EmptyRef, $e->reason);
        }
    }

    #[Test]
    public function legacy_call_without_source_file_still_resolves_internal_refs(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['type' => 'object'],
                    'Alias' => ['$ref' => '#/components/schemas/Pet'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve($spec);

        $this->assertSame(['type' => 'object'], $resolved['components']['schemas']['Alias']);
    }

    #[Test]
    public function applies_schema_ref_siblings_inside_an_external_document(): void
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/name.json', '{"type":"string"}');
        file_put_contents(
            $this->workDir . '/user.json',
            '{"type":"object","properties":{"name":{"$ref":"./name.json","minLength":4}}}',
        );

        $spec = [
            'openapi' => '3.1.0',
            'paths' => ['/users' => ['get' => ['responses' => ['200' => ['content' => [
                'application/json' => ['schema' => ['$ref' => './user.json']],
            ]]]]]],
        ];

        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);

        $schema = $resolved['paths']['/users']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(
            ['type' => 'string', 'minLength' => 4],
            $schema['properties']['name'],
        );
    }

    #[Test]
    public function shares_a_whole_file_target_referenced_many_times_instead_of_copying_it(): void
    {
        $properties = [];
        for ($i = 0; $i < 300; $i++) {
            $properties['field' . $i] = ['type' => 'string', 'description' => str_repeat('d', 40) . $i];
        }
        $big = ['type' => 'object', 'properties' => $properties];
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/big.json', json_encode($big));

        $paths = [];
        for ($i = 0; $i < 200; $i++) {
            $paths['/r' . $i]['get']['responses']['200']['content']['application/json']['schema'] = ['$ref' => './big.json'];
        }
        $spec = ['openapi' => '3.1.0', 'paths' => $paths];

        $before = memory_get_usage();
        $copy = unserialize(serialize($big));
        $oneCopy = memory_get_usage() - $before;
        unset($copy);

        $before = memory_get_usage();
        $resolved = OpenApiRefResolver::resolve($spec, $rootPath);
        $retained = memory_get_usage() - $before;

        $this->assertSame($big, $resolved['paths']['/r199']['get']['responses']['200']['content']['application/json']['schema']);
        // Walking the file per reference site retains about 200 copies.
        // Sharing keeps one; the bound is generous.
        $this->assertLessThan(20 * $oneCopy, $retained);
    }

    /**
     * `$ref` siblings apply in a Schema Object only, so the same file walks
     * to two results depending on where it is referenced from. Whichever site
     * comes first must not decide what the other one gets.
     */
    #[Test]
    public function walks_a_whole_file_target_once_per_schema_position(): void
    {
        $rootPath = $this->writeUserSchemaFiles();
        $schemaSite = ['/users' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['$ref' => './user.json']],
        ]]]]]];
        $elsewhere = ['$ref' => './user.json'];

        $schemaFirst = OpenApiRefResolver::resolve(['openapi' => '3.1.0', 'paths' => $schemaSite, 'x-meta' => $elsewhere], $rootPath);
        $schemaLast = OpenApiRefResolver::resolve(['openapi' => '3.1.0', 'x-meta' => $elsewhere, 'paths' => $schemaSite], $rootPath);

        foreach ([$schemaFirst, $schemaLast] as $resolved) {
            $schema = $resolved['paths']['/users']['get']['responses']['200']['content']['application/json']['schema'];
            $this->assertSame(['type' => 'string', 'minLength' => 4], $schema['properties']['name']);
            $this->assertSame(['type' => 'string'], $resolved['x-meta']['properties']['name']);
        }
    }

    /**
     * A file that declares no `$schema` is read under the dialect of whoever
     * references it: `$ref` siblings apply from the 2020-12 entry document and
     * not from a Draft 07 one.
     */
    #[Test]
    public function walks_a_whole_file_target_once_per_dialect(): void
    {
        $rootPath = $this->writeUserSchemaFiles();
        file_put_contents(
            $this->workDir . '/draft07.json',
            '{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","properties":{"user":{"$ref":"./user.json"}}}',
        );
        $site = static fn(string $file): array => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['$ref' => $file]],
        ]]]]];
        $schemaAt = static fn(array $resolved, string $path): mixed => $resolved['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

        foreach ([['/direct', '/draft07'], ['/draft07', '/direct']] as $order) {
            $paths = [];
            foreach ($order as $path) {
                $paths[$path] = $site($path === '/direct' ? './user.json' : './draft07.json');
            }
            $resolved = OpenApiRefResolver::resolve(['openapi' => '3.1.0', 'paths' => $paths], $rootPath);

            $this->assertSame(['type' => 'string', 'minLength' => 4], $schemaAt($resolved, '/direct')['properties']['name']);
            $this->assertSame(['type' => 'string'], $schemaAt($resolved, '/draft07')['properties']['user']['properties']['name']);
        }
    }

    private function writeUserSchemaFiles(): string
    {
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents($this->workDir . '/name.json', '{"type":"string"}');
        file_put_contents(
            $this->workDir . '/user.json',
            '{"type":"object","properties":{"name":{"$ref":"./name.json","minLength":4}}}',
        );

        return $rootPath;
    }
}
