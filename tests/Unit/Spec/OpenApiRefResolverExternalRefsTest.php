<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Spec\OpenApiRefResolver;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class OpenApiRefResolverExternalRefsTest extends TestCase
{
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
            ['allOf' => [['type' => 'string'], ['minLength' => 4]]],
            $schema['properties']['name'],
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
