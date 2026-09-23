<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Spec\OpenApiRefResolver;
use Studio\Gesso\Tests\Helpers\FakeHttpClient;
use Studio\Gesso\Tests\Helpers\RemovesDirectories;

use function file_put_contents;
use function ini_set;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

class OpenApiRefResolverHttpRefsTest extends TestCase
{
    use RemovesDirectories;
    private HttpFactory $factory;
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new HttpFactory();
        $this->workDir = sys_get_temp_dir() . '/oct-resolver-http-' . uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
        parent::tearDown();
    }

    #[Test]
    public function resolves_https_ref_when_opt_in_and_client_provided(): void
    {
        $url = 'https://example.com/schemas/pet.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"type":"object","required":["id"]}'),
        ]);

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $url]]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            sourceFile: null,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(
            ['type' => 'object', 'required' => ['id']],
            $resolved['components']['schemas']['Pet'],
        );
    }

    #[Test]
    public function rejects_unlisted_host_before_sending_a_request(): void
    {
        $url = 'http://169.254.169.254/latest/meta-data.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"type":"string"}'),
        ]);
        $spec = ['components' => ['schemas' => ['Metadata' => ['$ref' => $url]]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['specs.example.com'],
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefHostDisallowed, $e->reason);
            $this->assertStringContainsString('169.254.169.254', $e->getMessage());
            $this->assertSame([], $client->sentUrls());
        }
    }

    #[Test]
    public function rechecks_the_allowlist_for_nested_cross_host_refs(): void
    {
        $entry = 'https://specs.example.com/root.json';
        $nested = 'http://169.254.169.254/latest/meta-data.json';
        $client = new FakeHttpClient([
            $entry => FakeHttpClient::jsonResponse('{"$ref":"' . $nested . '"}'),
            $nested => FakeHttpClient::jsonResponse('{"type":"string"}'),
        ]);
        $spec = ['components' => ['schemas' => ['Metadata' => ['$ref' => $entry]]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['SPECS.EXAMPLE.COM.'],
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefHostDisallowed, $e->reason);
            $this->assertSame([$entry], $client->sentUrls());
        }
    }

    #[Test]
    public function resolves_https_ref_with_json_pointer_fragment(): void
    {
        $url = 'https://example.com/schemas.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse(
                '{"Pet":{"type":"object"},"Owner":{"type":"string"}}',
            ),
        ]);

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $url . '#/Pet']]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(['type' => 'object'], $resolved['components']['schemas']['Pet']);
    }

    #[Test]
    public function resolves_chain_of_https_refs(): void
    {
        $a = 'https://example.com/a.json';
        $b = 'https://example.com/b.json';
        $c = 'https://example.com/c.json';
        $client = new FakeHttpClient([
            $a => FakeHttpClient::jsonResponse('{"$ref":"' . $b . '"}'),
            $b => FakeHttpClient::jsonResponse('{"$ref":"' . $c . '"}'),
            $c => FakeHttpClient::jsonResponse('{"type":"string"}'),
        ]);

        $spec = ['components' => ['schemas' => ['Leaf' => ['$ref' => $a]]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(['type' => 'string'], $resolved['components']['schemas']['Leaf']);
    }

    #[Test]
    public function detects_cycle_between_two_https_refs(): void
    {
        $a = 'https://example.com/a.json';
        $b = 'https://example.com/b.json';
        $client = new FakeHttpClient([
            $a => FakeHttpClient::jsonResponse('{"$ref":"' . $b . '#/Loop"}'),
            $b => FakeHttpClient::jsonResponse('{"Loop":{"$ref":"' . $a . '"}}'),
        ]);

        $spec = ['components' => ['schemas' => ['Start' => ['$ref' => $a]]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['example.com'],
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::CircularRef, $e->reason);
        }
    }

    #[Test]
    public function resolves_cross_source_local_to_remote_chain(): void
    {
        // Local root → local file → remote URL → leaf. Pins that the
        // resolver correctly switches loaders when a `$ref` crosses
        // protocols mid-chain.
        $rootPath = $this->workDir . '/openapi.json';
        file_put_contents($rootPath, '{}');
        file_put_contents(
            $this->workDir . '/local-bridge.json',
            '{"$ref":"https://example.com/leaf.json"}',
        );

        $client = new FakeHttpClient([
            'https://example.com/leaf.json' => FakeHttpClient::jsonResponse('{"type":"integer"}'),
        ]);

        $spec = ['components' => ['schemas' => ['Final' => ['$ref' => './local-bridge.json']]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            sourceFile: $rootPath,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(['type' => 'integer'], $resolved['components']['schemas']['Final']);
    }

    #[Test]
    public function reuses_loaded_url_for_sibling_fragment_refs(): void
    {
        $url = 'https://example.com/schemas.json';
        $callCount = 0;
        $client = new FakeHttpClient([
            $url => static function (RequestInterface $request) use (&$callCount): Response {
                $callCount++;

                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"Pet":{"type":"object"},"Owner":{"type":"string"}}',
                );
            },
        ]);

        $spec = [
            'components' => [
                'schemas' => [
                    'Pet' => ['$ref' => $url . '#/Pet'],
                    'Owner' => ['$ref' => $url . '#/Owner'],
                ],
            ],
        ];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(1, $callCount, 'duplicate URLs should hit the per-resolution cache');
        $this->assertSame(['type' => 'object'], $resolved['components']['schemas']['Pet']);
        $this->assertSame(['type' => 'string'], $resolved['components']['schemas']['Owner']);
    }

    #[Test]
    public function throws_remote_ref_disallowed_when_allow_flag_is_off_even_with_client(): void
    {
        $url = 'https://example.com/pet.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"type":"object"}'),
        ]);

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $url]]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: false,
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefDisallowed, $e->reason);
        }

        $this->assertSame([], $client->sentUrls(), 'client must not be invoked when flag is off');
    }

    #[Test]
    public function throws_http_client_not_configured_when_flag_on_but_client_missing(): void
    {
        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => 'https://example.com/pet.json']]]];

        try {
            OpenApiRefResolver::resolve($spec, allowRemoteRefs: true);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::HttpClientNotConfigured, $e->reason);
            $this->assertStringContainsString('PSR-18', $e->getMessage());
        }
    }

    #[Test]
    public function throws_http_client_not_configured_when_flag_on_but_factory_missing(): void
    {
        $client = new FakeHttpClient();
        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => 'https://example.com/pet.json']]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: null,
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['example.com'],
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::HttpClientNotConfigured, $e->reason);
        }
    }

    #[Test]
    public function decodes_json_pointer_escapes_in_remote_fragment(): void
    {
        $url = 'https://example.com/schemas.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"a/b":{"type":"object"}}'),
        ]);

        $spec = ['components' => ['schemas' => ['Slash' => ['$ref' => $url . '#/a~1b']]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(['type' => 'object'], $resolved['components']['schemas']['Slash']);
    }

    #[Test]
    public function throws_unresolvable_ref_when_remote_fragment_missing(): void
    {
        $url = 'https://example.com/schemas.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"Pet":{"type":"object"}}'),
        ]);

        $spec = ['components' => ['schemas' => ['Missing' => ['$ref' => $url . '#/Owner']]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['example.com'],
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnresolvableRef, $e->reason);
        }
    }

    #[Test]
    public function document_cache_is_per_resolution_call_not_global_for_http_refs(): void
    {
        // The same client is used across two separate resolve() calls;
        // each call must perform its own fetch. If a future refactor
        // hoists the cache to a static field, hot-reloading specs in
        // test watchers would silently see stale URLs.
        $url = 'https://example.com/pet.json';
        $callCount = 0;
        $client = new FakeHttpClient([
            $url => static function () use (&$callCount): Response {
                $callCount++;

                return new Response(200, ['Content-Type' => 'application/json'], '{"v":' . $callCount . '}');
            },
        ]);

        $spec1 = ['components' => ['schemas' => ['Pet' => ['$ref' => $url]]]];
        $first = OpenApiRefResolver::resolve(
            $spec1,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );
        $this->assertSame(['v' => 1], $first['components']['schemas']['Pet']);

        $spec2 = ['components' => ['schemas' => ['Pet' => ['$ref' => $url]]]];
        $second = OpenApiRefResolver::resolve(
            $spec2,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );
        $this->assertSame(['v' => 2], $second['components']['schemas']['Pet']);
        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function relative_ref_inside_http_document_resolves_against_url_per_rfc3986(): void
    {
        // Inside a document loaded over HTTP, `$ref: './pet.yaml'` must
        // resolve against the source URL — not against any local
        // filesystem path. Without this, dirname() on the URL would
        // produce nonsense like `dirname('https:')` and the user would
        // see a confusing LocalRefNotFound.
        $rootUrl = 'https://example.com/openapi.json';
        $childUrl = 'https://example.com/schemas/pet.yaml';
        $client = new FakeHttpClient([
            $rootUrl => FakeHttpClient::jsonResponse('{"$ref":"./schemas/pet.yaml"}'),
            $childUrl => FakeHttpClient::yamlResponse("type: object\nrequired:\n  - id\n"),
        ]);

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $rootUrl]]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(
            ['type' => 'object', 'required' => ['id']],
            $resolved['components']['schemas']['Pet'],
        );
    }

    #[Test]
    public function relative_ref_with_parent_dir_resolves_against_http_base(): void
    {
        $rootUrl = 'https://example.com/api/v1/openapi.json';
        $childUrl = 'https://example.com/api/shared.json';
        $client = new FakeHttpClient([
            $rootUrl => FakeHttpClient::jsonResponse('{"$ref":"../shared.json"}'),
            $childUrl => FakeHttpClient::jsonResponse('{"type":"string"}'),
        ]);

        $spec = ['components' => ['schemas' => ['X' => ['$ref' => $rootUrl]]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(['type' => 'string'], $resolved['components']['schemas']['X']);
    }

    #[Test]
    public function absolute_path_ref_inside_http_document_resolves_to_same_host(): void
    {
        $rootUrl = 'https://example.com/openapi.json';
        $childUrl = 'https://example.com/registry/Pet.json';
        $client = new FakeHttpClient([
            $rootUrl => FakeHttpClient::jsonResponse('{"$ref":"/registry/Pet.json"}'),
            $childUrl => FakeHttpClient::jsonResponse('{"type":"integer"}'),
        ]);

        $spec = ['components' => ['schemas' => ['X' => ['$ref' => $rootUrl]]]];

        $resolved = OpenApiRefResolver::resolve(
            $spec,
            httpClient: $client,
            requestFactory: $this->factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame(['type' => 'integer'], $resolved['components']['schemas']['X']);
    }

    #[Test]
    public function fetch_failure_includes_chain_for_multi_hop_diagnostics(): void
    {
        // When a fetch fails 3+ hops deep, the leaf URL alone is poor
        // diagnostic. The error message should include the $ref chain
        // so the developer knows which spec entry triggered the cascade.
        $a = 'https://example.com/a.json';
        $b = 'https://example.com/b.json';
        $c = 'https://example.com/missing.json';
        $client = new FakeHttpClient([
            $a => FakeHttpClient::jsonResponse('{"$ref":"' . $b . '"}'),
            $b => FakeHttpClient::jsonResponse('{"$ref":"' . $c . '"}'),
            $c => new Response(404),
        ]);

        $spec = ['components' => ['schemas' => ['Leaf' => ['$ref' => $a]]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['example.com'],
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('via $ref chain', $e->getMessage());
            $this->assertStringContainsString($a, $e->getMessage());
        }
    }

    #[Test]
    public function fetch_failure_trace_redacts_the_original_remote_ref_argument(): void
    {
        $url = 'https://alice:secret@example.com/missing.json?token=query-secret';
        $client = new FakeHttpClient([$url => new Response(404)]);
        $spec = ['components' => ['schemas' => ['Leaf' => ['$ref' => $url]]]];

        $previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
        if ($previousIgnoreArgs === false) {
            $this->markTestSkipped('zend.exception_ignore_args cannot be changed at runtime');
        }
        $previousMaxLength = ini_set('zend.exception_string_param_max_len', '1024');
        if ($previousMaxLength === false) {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            $this->markTestSkipped('zend.exception_string_param_max_len cannot be changed at runtime');
        }

        try {
            try {
                OpenApiRefResolver::resolve(
                    $spec,
                    httpClient: $client,
                    requestFactory: $this->factory,
                    allowRemoteRefs: true,
                    allowedRemoteRefHosts: ['example.com'],
                );
                $this->fail('expected InvalidOpenApiSpecException');
            } catch (InvalidOpenApiSpecException $e) {
                $rendered = (string) $e;

                $this->assertStringContainsString('https://example.com/missing.json?token=[redacted]', $rendered);
                $this->assertStringNotContainsString('alice:secret', $rendered);
                $this->assertStringNotContainsString('query-secret', $rendered);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            ini_set('zend.exception_string_param_max_len', $previousMaxLength);
        }
    }

    #[Test]
    public function throws_bare_fragment_for_trailing_hash_on_https_ref(): void
    {
        $url = 'https://example.com/pet.json';
        $client = new FakeHttpClient();

        $spec = ['components' => ['schemas' => ['Pet' => ['$ref' => $url . '#']]]];

        try {
            OpenApiRefResolver::resolve(
                $spec,
                httpClient: $client,
                requestFactory: $this->factory,
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['example.com'],
            );
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::BareFragmentRef, $e->reason);
        }
    }
}
