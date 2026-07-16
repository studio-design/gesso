<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Studio\Gesso\Internal\YamlAvailability;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\FakeHttpClient;
use Studio\Gesso\Validation\Request\SecurityValidator;
use Symfony\Component\Yaml\Yaml;

use function class_exists;
use function file_put_contents;
use function json_encode;
use function ltrim;
use function mkdir;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class OpenApiSpecLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function configure_sets_base_path_and_strip_prefixes(): void
    {
        OpenApiSpecLoader::configure('/path/to/specs', ['/api', '/internal']);

        $this->assertSame('/path/to/specs', OpenApiSpecLoader::getBasePath());
        $this->assertSame(['/api', '/internal'], OpenApiSpecLoader::getStripPrefixes());
    }

    #[Test]
    public function configure_trims_trailing_slash(): void
    {
        OpenApiSpecLoader::configure('/path/to/specs/');

        $this->assertSame('/path/to/specs', OpenApiSpecLoader::getBasePath());
    }

    #[Test]
    public function configure_preserves_filesystem_roots(): void
    {
        OpenApiSpecLoader::configure('/', enumBasePath: '/');

        $this->assertSame('/', OpenApiSpecLoader::getBasePath());
        $this->assertSame('/', OpenApiSpecLoader::getEnumBasePath());

        OpenApiSpecLoader::configure('C:/', enumBasePath: 'C:/');

        $this->assertSame('C:/', OpenApiSpecLoader::getBasePath());
        $this->assertSame('C:/', OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function load_supports_the_posix_filesystem_root_as_the_base_path(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX filesystem root regression');
        }

        $scratchDir = sys_get_temp_dir() . '/openapi-root-base-' . uniqid('', true);
        $path = $scratchDir . '/root.json';
        mkdir($scratchDir);
        file_put_contents($path, '{"openapi":"3.0.3","info":{"title":"Root base","version":"1"},"paths":{}}');

        try {
            OpenApiSpecLoader::configure('/');
            $specName = ltrim(substr($path, 0, -5), '/');

            $this->assertSame('Root base', OpenApiSpecLoader::load($specName)['info']['title']);
        } finally {
            unlink($path);
            rmdir($scratchDir);
        }
    }

    #[Test]
    public function configure_defaults_strip_prefixes_to_empty(): void
    {
        OpenApiSpecLoader::configure('/path/to/specs');

        $this->assertSame([], OpenApiSpecLoader::getStripPrefixes());
    }

    #[Test]
    public function get_enum_base_path_returns_null_when_not_configured(): void
    {
        // Issue #170: enum_spec_base_path is opt-in. Absence is the
        // documented default — getEnumBasePath() must not throw the way
        // getBasePath() does, because the asserter relies on the null
        // return to fall back to spec_base_path.
        $this->assertNull(OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function configure_stores_enum_base_path_with_trailing_slash_trimmed(): void
    {
        OpenApiSpecLoader::configure(
            basePath: '/path/to/specs',
            enumBasePath: '/path/to/openapi/',
        );

        $this->assertSame('/path/to/openapi', OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function reset_clears_enum_base_path(): void
    {
        OpenApiSpecLoader::configure(
            basePath: '/path/to/specs',
            enumBasePath: '/path/to/openapi',
        );
        OpenApiSpecLoader::reset();

        $this->assertNull(OpenApiSpecLoader::getEnumBasePath());
    }

    #[Test]
    public function configure_rejects_empty_enum_base_path(): void
    {
        // Reject at the API surface. Without this, an empty string would
        // surface later as `EnumBasePathNotFound: ... is not a directory: `
        // (with nothing after the colon) — a confusing diagnostic that hides
        // the real misconfiguration.
        try {
            OpenApiSpecLoader::configure(
                basePath: '/path/to/specs',
                enumBasePath: '',
            );
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('$enumBasePath is empty', $e->getMessage());
        }
    }

    #[Test]
    public function configure_rejects_whitespace_only_enum_base_path(): void
    {
        try {
            OpenApiSpecLoader::configure(
                basePath: '/path/to/specs',
                enumBasePath: "  \n\t ",
            );
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('whitespace-only', $e->getMessage());
        }
    }

    #[Test]
    public function get_base_path_throws_when_not_configured(): void
    {
        try {
            OpenApiSpecLoader::getBasePath();
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::BasePathNotConfigured, $e->reason);
            $this->assertStringContainsString('OpenApiSpecLoader base path not configured', $e->getMessage());
        }
    }

    #[Test]
    public function load_returns_parsed_spec(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('petstore-3.0');

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Petstore', $spec['info']['title']);
        $this->assertArrayHasKey('/v1/pets', $spec['paths']);
    }

    #[Test]
    public function load_rejects_unsupported_openapi_version_with_spec_context(): void
    {
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');

        try {
            OpenApiSpecLoader::load('unsupported-version');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedVersion, $e->reason);
            $this->assertSame('unsupported-version', $e->specName);
            $this->assertStringContainsString("'3.3.0' (string)", $e->getMessage());
            $this->assertStringContainsString('3.0.x, 3.1.x, or 3.2.x', $e->getMessage());
        }
    }

    #[Test]
    public function openapi_32_self_base_uri_is_never_silently_ignored(): void
    {
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $spec = OpenApiSpecLoader::load('openapi-3.2-self');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('3.2.0', $spec['openapi']);
        $this->assertStringContainsString('[OpenAPI 3.2 $self]', $warning ?? '');
        $this->assertStringContainsString('pre-bundle', $warning ?? '');
    }

    #[Test]
    public function load_caches_result(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $first = OpenApiSpecLoader::load('petstore-3.0');
        $second = OpenApiSpecLoader::load('petstore-3.0');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function load_throws_for_nonexistent_spec(): void
    {
        OpenApiSpecLoader::configure('/nonexistent/path');

        try {
            OpenApiSpecLoader::load('nonexistent');
            $this->fail('expected SpecFileNotFoundException');
        } catch (SpecFileNotFoundException $e) {
            $this->assertSame('nonexistent', $e->specName);
            $this->assertSame('/nonexistent/path', $e->basePath);
            $this->assertStringContainsString('OpenAPI bundled spec not found', $e->getMessage());
        }
    }

    #[Test]
    public function load_confines_entry_specs_to_the_canonical_base_path(): void
    {
        $scratchDir = sys_get_temp_dir() . '/openapi-entry-root-' . uniqid('', true);
        $specDir = $scratchDir . '/specs';
        $nestedDir = $specDir . '/nested';
        mkdir($scratchDir);
        mkdir($specDir);
        mkdir($nestedDir);
        file_put_contents($scratchDir . '/outside.json', '{"openapi":"3.0.3","info":{"title":"Outside","version":"1"},"paths":{}}');
        file_put_contents($nestedDir . '/inside.json', '{"openapi":"3.0.3","info":{"title":"Inside","version":"1"},"paths":{}}');

        try {
            OpenApiSpecLoader::configure($specDir);

            foreach (['../outside', '..\\outside', '../absent', '/outside', 'C:/outside'] as $specName) {
                try {
                    OpenApiSpecLoader::load($specName);
                    $this->fail('expected SpecFileNotFoundException');
                } catch (SpecFileNotFoundException $e) {
                    $this->assertSame($specName, $e->specName);
                    $this->assertSame($specDir, $e->basePath);
                    $this->assertStringContainsString('OpenAPI bundled spec not found', $e->getMessage());
                }
            }

            $this->assertSame('Inside', OpenApiSpecLoader::load('nested/inside')['info']['title']);
        } finally {
            unlink($scratchDir . '/outside.json');
            unlink($nestedDir . '/inside.json');
            rmdir($nestedDir);
            rmdir($specDir);
            rmdir($scratchDir);
        }
    }

    #[Test]
    public function load_rejects_an_entry_spec_symlinked_outside_the_base_path(): void
    {
        $scratchDir = sys_get_temp_dir() . '/openapi-entry-symlink-' . uniqid('', true);
        $specDir = $scratchDir . '/specs';
        $outside = $scratchDir . '/outside.json';
        $link = $specDir . '/linked.json';
        mkdir($scratchDir);
        mkdir($specDir);
        file_put_contents($outside, '{"openapi":"3.0.3","info":{"title":"Outside","version":"1"},"paths":{}}');
        if (!@symlink($outside, $link)) {
            unlink($outside);
            rmdir($specDir);
            rmdir($scratchDir);
            $this->markTestSkipped('symlinks are unavailable on this platform');
        }

        try {
            OpenApiSpecLoader::configure($specDir);

            $this->expectException(SpecFileNotFoundException::class);
            OpenApiSpecLoader::load('linked');
        } finally {
            unlink($link);
            unlink($outside);
            rmdir($specDir);
            rmdir($scratchDir);
        }
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        OpenApiSpecLoader::configure('/path/to/specs', ['/api']);

        OpenApiSpecLoader::reset();

        $this->assertSame([], OpenApiSpecLoader::getStripPrefixes());

        $this->expectException(InvalidOpenApiSpecException::class);
        OpenApiSpecLoader::getBasePath();
    }

    #[Test]
    public function clear_cache_keeps_config(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath, ['/api']);

        // Load to populate cache
        OpenApiSpecLoader::load('petstore-3.0');

        OpenApiSpecLoader::clearCache();

        // Config is preserved
        $this->assertSame($fixturesPath, OpenApiSpecLoader::getBasePath());
        $this->assertSame(['/api'], OpenApiSpecLoader::getStripPrefixes());

        // Cache is cleared — next load reads from disk again
        $spec = OpenApiSpecLoader::load('petstore-3.0');
        $this->assertSame('3.0.3', $spec['openapi']);
    }

    #[Test]
    public function load_resolves_internal_refs(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('refs-valid');

        // The paths schema should no longer contain '$ref' after resolution.
        $listSchema = $spec['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('array', $listSchema['type']);
        $this->assertArrayNotHasKey('$ref', $listSchema['items']);
        $this->assertSame('object', $listSchema['items']['type']);

        // Transitive nested ref Pet -> Category -> Label should be fully resolved.
        $this->assertSame(
            ['type' => 'string', 'minLength' => 1],
            $listSchema['items']['properties']['category']['properties']['label'],
        );

        // Path-level parameter $ref is resolved inline.
        $pathParam = $spec['paths']['/pets/{petId}']['parameters'][0];
        $this->assertArrayNotHasKey('$ref', $pathParam);
        $this->assertSame('petId', $pathParam['name']);
        $this->assertSame('path', $pathParam['in']);

        // Response-level $ref is resolved.
        $responseSpec = $spec['paths']['/pets/{petId}']['get']['responses']['200'];
        $this->assertArrayNotHasKey('$ref', $responseSpec);
        $this->assertSame('A single pet', $responseSpec['description']);
    }

    #[Test]
    public function load_resolves_internal_refs_from_yaml_spec(): void
    {
        // Mirrors `load_resolves_internal_refs` but loads the YAML twin of
        // refs-valid to pin that `$ref` resolution works end-to-end for YAML-
        // decoded specs. symfony/yaml applies YAML 1.2 scalar coercion that
        // JSON never does (e.g. bare `1.0` -> float, bare `true`/`false` ->
        // bool, bare `YYYY-MM-DD` -> int timestamp), so the strict-equality
        // assertions below double as drift pins: if a scalar's decoded type
        // ever changes, the resolved-array comparisons will fail.
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('petstore-yaml-with-refs');

        // Distinctive title pins that the YAML fixture (not a stray JSON
        // twin) was actually loaded. SEARCH_EXTENSIONS is json-first, so if a
        // `petstore-yaml-with-refs.json` ever appears in this directory it
        // would shadow the YAML fixture and this assertion would fail loudly
        // rather than silently bypass the YAML decode path.
        $this->assertSame('Refs valid (YAML)', $spec['info']['title']);

        // Array-item $ref inlined.
        $listSchema = $spec['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('array', $listSchema['type']);
        $this->assertArrayNotHasKey('$ref', $listSchema['items']);
        $this->assertSame('object', $listSchema['items']['type']);

        // Transitive chain Pet -> Category -> Label fully resolved. The int
        // `minLength => 1` here is the actual coercion pin: strict `===`
        // comparison fails if symfony/yaml ever decodes `1` as a string or
        // float through this ref-inlined subtree.
        $this->assertSame(
            ['type' => 'string', 'minLength' => 1],
            $listSchema['items']['properties']['category']['properties']['label'],
        );

        // Path-level parameter $ref inlined.
        $pathParam = $spec['paths']['/pets/{petId}']['parameters'][0];
        $this->assertArrayNotHasKey('$ref', $pathParam);
        $this->assertSame('petId', $pathParam['name']);
        $this->assertSame('path', $pathParam['in']);

        // Response-level $ref inlined.
        $responseSpec = $spec['paths']['/pets/{petId}']['get']['responses']['200'];
        $this->assertArrayNotHasKey('$ref', $responseSpec);
        $this->assertSame('A single pet', $responseSpec['description']);
    }

    #[Test]
    public function load_throws_on_circular_ref(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Circular $ref');

        OpenApiSpecLoader::load('refs-circular');
    }

    #[Test]
    public function load_throws_local_ref_not_found_when_external_target_missing(): void
    {
        // The fixture references `other-spec.json` which intentionally
        // does not exist in the fixtures directory. Now that local
        // external refs are supported, the resolver attempts the load
        // and surfaces a precise file-not-found error instead of the
        // old blanket "external refs unsupported" rejection.
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('refs-external');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::LocalRefNotFound, $e->reason);
            $this->assertSame('refs-external', $e->specName);
            $this->assertStringContainsString('other-spec.json', $e->getMessage());
        }
    }

    #[Test]
    public function load_preserves_previous_chain_when_attaching_spec_name(): void
    {
        // The loader re-wraps resolver-originated throws via
        // InvalidOpenApiSpecException::withSpecName(). The wrap must
        // not drop the original exception (or its own $previous), or
        // operators lose the underlying decoder diagnostic when an
        // external $ref target has malformed JSON.
        $tempDir = sys_get_temp_dir() . '/oct-spec-loader-prev-' . uniqid();
        mkdir($tempDir);

        try {
            file_put_contents($tempDir . '/root.json', json_encode([
                'openapi' => '3.0.3',
                'info' => ['title' => 'Prev', 'version' => '1.0.0'],
                'components' => ['schemas' => ['Bad' => ['$ref' => './bad.json']]],
            ]));
            file_put_contents($tempDir . '/bad.json', '{ not valid json');

            OpenApiSpecLoader::configure($tempDir);

            try {
                OpenApiSpecLoader::load('root');
                $this->fail('expected InvalidOpenApiSpecException');
            } catch (InvalidOpenApiSpecException $e) {
                $this->assertSame('root', $e->specName);
                // First link is the original (pre-wrap) exception.
                $original = $e->getPrevious();
                $this->assertInstanceOf(InvalidOpenApiSpecException::class, $original);
                // Second link is the underlying JsonException carried
                // by the resolver throw — proves the chain isn't
                // truncated at the wrap boundary.
                $this->assertNotNull($original->getPrevious());
            }
        } finally {
            @unlink($tempDir . '/bad.json');
            @unlink($tempDir . '/root.json');
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function configure_throws_invalid_argument_when_allow_remote_refs_without_client(): void
    {
        try {
            OpenApiSpecLoader::configure(
                '/path/to/specs',
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['example.com'],
            );
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('allowRemoteRefs', $e->getMessage());
            $this->assertStringContainsString('PSR-18', $e->getMessage());
        }
    }

    #[Test]
    public function configure_throws_when_client_is_set_without_allow_flag(): void
    {
        $client = new Client();
        $factory = new HttpFactory();

        try {
            OpenApiSpecLoader::configure(
                '/path/to/specs',
                httpClient: $client,
                requestFactory: $factory,
                allowRemoteRefs: false,
            );
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('allowRemoteRefs is false', $e->getMessage());
            $this->assertStringContainsString('HTTP client was provided', $e->getMessage());
        }
    }

    #[Test]
    public function configure_accepts_full_remote_setup(): void
    {
        $client = new Client();
        $factory = new HttpFactory();

        OpenApiSpecLoader::configure(
            '/path/to/specs',
            httpClient: $client,
            requestFactory: $factory,
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        $this->assertSame('/path/to/specs', OpenApiSpecLoader::getBasePath());
    }

    #[Test]
    public function configure_rejects_non_positive_remote_response_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRemoteRefBytes');

        OpenApiSpecLoader::configure('/path/to/specs', maxRemoteRefBytes: 0);
    }

    #[Test]
    public function load_enforces_the_configured_remote_response_limit(): void
    {
        $tempDir = sys_get_temp_dir() . '/openapi-remote-limit-' . uniqid('', true);
        mkdir($tempDir);
        $url = 'https://example.com/schema.json';
        file_put_contents($tempDir . '/root.json', (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => ['Remote' => ['$ref' => $url]]],
        ], JSON_THROW_ON_ERROR));

        try {
            OpenApiSpecLoader::configure(
                $tempDir,
                httpClient: new FakeHttpClient([
                    $url => FakeHttpClient::jsonResponse('{"type":"object"}'),
                ]),
                requestFactory: new HttpFactory(),
                allowRemoteRefs: true,
                allowedRemoteRefHosts: ['example.com'],
                maxRemoteRefBytes: 10,
            );

            $this->expectException(InvalidOpenApiSpecException::class);
            $this->expectExceptionMessage('configured limit of 10 bytes');
            OpenApiSpecLoader::load('root');
        } finally {
            @unlink($tempDir . '/root.json');
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function configure_rejects_remote_refs_without_an_allowed_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allowedRemoteRefHosts');

        OpenApiSpecLoader::configure(
            '/path/to/specs',
            httpClient: new Client(),
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
        );
    }

    #[Test]
    public function configure_rejects_allowed_hosts_when_remote_refs_are_disabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allowRemoteRefs is false');

        OpenApiSpecLoader::configure(
            '/path/to/specs',
            allowedRemoteRefHosts: ['example.com'],
        );
    }

    #[Test]
    public function configure_rejects_urls_in_the_host_allowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pass a host only');

        OpenApiSpecLoader::configure(
            '/path/to/specs',
            httpClient: new Client(),
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['https://example.com/specs'],
        );
    }

    #[Test]
    public function configure_evicts_cached_specs(): void
    {
        // A previously-cached spec resolved under one remote-refs policy
        // must not be served after configure() flips the policy. Pin the
        // eviction so the next load() reads from disk again.
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);
        $first = OpenApiSpecLoader::load('petstore-3.0');
        $this->assertSame('3.0.3', $first['openapi']);

        OpenApiSpecLoader::configure(
            $fixturesPath,
            httpClient: new Client(),
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        // Reload — by-value-equal but not the cached array from before.
        $reloaded = OpenApiSpecLoader::load('petstore-3.0');
        $this->assertSame($first, $reloaded);
    }

    #[Test]
    public function reset_clears_http_client_and_remote_flag_state(): void
    {
        OpenApiSpecLoader::configure(
            '/path/to/specs',
            httpClient: new Client(),
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['example.com'],
        );

        OpenApiSpecLoader::reset();

        // Reconfiguring with allowRemoteRefs:true but no client must
        // throw — proving the prior client/factory weren't sticky.
        try {
            OpenApiSpecLoader::configure('/path/to/specs', allowRemoteRefs: true);
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('allowRemoteRefs requires', $e->getMessage());
        }
    }

    #[Test]
    public function load_resolves_local_external_refs_in_multi_file_yaml_spec(): void
    {
        // The multi-file fixture's root yaml references ./schemas/pet.yaml
        // for both list and detail responses, plus a JSON-pointer fragment
        // ref into schemas/error.json. All should inline cleanly without
        // any pre-bundling step.
        $fixturesPath = __DIR__ . '/../../fixtures/specs/external-refs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('multi-file');

        $listSchema = $spec['paths']['/pets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('array', $listSchema['type']);
        $this->assertSame(['id', 'name'], $listSchema['items']['required']);

        $detailSchema = $spec['paths']['/pets/{petId}']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('object', $detailSchema['type']);
        $this->assertSame('integer', $detailSchema['properties']['id']['type']);

        $errorSchema = $spec['paths']['/pets/{petId}']['get']['responses']['404']['content']['application/problem+json']['schema'];
        $this->assertSame(['code', 'message'], $errorSchema['required']);
    }

    #[Test]
    public function load_confines_local_refs_to_the_spec_base_path_and_allows_a_shared_common_base(): void
    {
        $scratchDir = sys_get_temp_dir() . '/openapi-local-ref-root-' . uniqid('', true);
        $specDir = $scratchDir . '/specs';
        $sharedDir = $scratchDir . '/shared';
        mkdir($scratchDir);
        mkdir($specDir);
        mkdir($sharedDir);

        try {
            file_put_contents(
                $specDir . '/root.json',
                '{"openapi":"3.0.3","info":{"title":"Root","version":"1"},"paths":{},'
                . '"components":{"schemas":{"Shared":{"$ref":"../shared/schema.json"}}}}',
            );
            file_put_contents($sharedDir . '/schema.json', '{"type":"string"}');

            OpenApiSpecLoader::configure($specDir);

            try {
                OpenApiSpecLoader::load('root');
                $this->fail('expected InvalidOpenApiSpecException');
            } catch (InvalidOpenApiSpecException $e) {
                $this->assertSame(InvalidOpenApiSpecReason::LocalRefOutsideAllowedRoot, $e->reason);
            }

            OpenApiSpecLoader::configure($scratchDir);
            $spec = OpenApiSpecLoader::load('specs/root');

            $this->assertSame('string', $spec['components']['schemas']['Shared']['type']);
        } finally {
            unlink($specDir . '/root.json');
            unlink($sharedDir . '/schema.json');
            rmdir($specDir);
            rmdir($sharedDir);
            rmdir($scratchDir);
        }
    }

    #[Test]
    public function load_throws_on_unresolvable_ref(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('refs-unresolvable');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnresolvableRef, $e->reason);
            // Loader re-wraps resolver throws with the spec name it knows;
            // pins that wrap so consumers can surface the spec in diagnostics.
            $this->assertSame('refs-unresolvable', $e->specName);
            $this->assertSame('#/components/schemas/DoesNotExist', $e->ref);
            $this->assertStringContainsString('Unresolvable $ref', $e->getMessage());
        }
    }

    #[Test]
    public function evict_removes_single_spec_from_cache(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        // Load two specs
        $first30 = OpenApiSpecLoader::load('petstore-3.0');
        $first31 = OpenApiSpecLoader::load('petstore-3.1');

        // Evict only 3.0
        OpenApiSpecLoader::evict('petstore-3.0');

        // 3.1 still cached (by-value equal)
        $this->assertSame($first31, OpenApiSpecLoader::load('petstore-3.1'));

        // 3.0 reload produces the same content (by-value; array equality, not instance identity)
        $reloaded30 = OpenApiSpecLoader::load('petstore-3.0');
        $this->assertSame($first30, $reloaded30);
    }

    #[Test]
    public function failed_load_does_not_poison_cache(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('refs-unresolvable');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException) {
            // Expected — next load must re-attempt from disk, not return a
            // partially-resolved array captured before the throw.
        }

        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('Unresolvable $ref');
        OpenApiSpecLoader::load('refs-unresolvable');
    }

    #[Test]
    public function failed_load_does_not_affect_other_specs(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('refs-circular');
        } catch (InvalidOpenApiSpecException) {
            // Swallow so we can verify a different spec still loads afterwards.
        }

        // A sibling spec with clean refs must still load successfully even if
        // an earlier failure left any intermediate state behind.
        $spec = OpenApiSpecLoader::load('refs-valid');
        $this->assertSame('Refs valid', $spec['info']['title']);
    }

    #[Test]
    public function load_returns_independent_copies_of_cached_specs(): void
    {
        // Pins the PHP copy-on-write assumption that OpenApiRefResolver relies
        // on: mutating the returned array must not corrupt the cached copy.
        // Swapping to by-reference returns (or accidentally sharing the root
        // snapshot) would show up here.
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $first = OpenApiSpecLoader::load('refs-valid');
        $first['info']['title'] = 'mutated';

        $second = OpenApiSpecLoader::load('refs-valid');
        $this->assertSame('Refs valid', $second['info']['title']);
    }

    #[Test]
    public function load_parses_yaml_spec(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('petstore-yaml');

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Petstore YAML', $spec['info']['title']);
        $this->assertArrayHasKey('/v1/pets', $spec['paths']);
    }

    #[Test]
    public function load_parses_yml_spec(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $spec = OpenApiSpecLoader::load('petstore-yml');

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Petstore YML', $spec['info']['title']);
    }

    #[Test]
    public function load_caches_yaml_spec(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        $first = OpenApiSpecLoader::load('petstore-yaml');
        $second = OpenApiSpecLoader::load('petstore-yaml');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function load_prefers_json_when_both_json_and_yaml_exist(): void
    {
        // Write both a .json and a .yaml for the same basename into a scratch
        // dir so the precedence guarantee in SEARCH_EXTENSIONS is covered
        // without mutating the shared tests/fixtures directory.
        $scratchDir = sys_get_temp_dir() . '/openapi-spec-loader-test-' . uniqid('', true);
        mkdir($scratchDir);

        try {
            file_put_contents(
                $scratchDir . '/dual.json',
                '{"openapi":"3.0.3","info":{"title":"JSON wins","version":"1.0.0"},"paths":{}}',
            );
            file_put_contents(
                $scratchDir . '/dual.yaml',
                "openapi: 3.0.3\ninfo:\n  title: YAML should lose\n  version: 1.0.0\npaths: {}\n",
            );

            OpenApiSpecLoader::configure($scratchDir);
            $spec = OpenApiSpecLoader::load('dual');

            $this->assertSame('JSON wins', $spec['info']['title']);
        } finally {
            @unlink($scratchDir . '/dual.json');
            @unlink($scratchDir . '/dual.yaml');
            @rmdir($scratchDir);
        }
    }

    #[Test]
    public function load_preserves_security_empty_object_and_list_shapes_for_json_and_yaml(): void
    {
        $scratchDir = sys_get_temp_dir() . '/openapi-spec-loader-test-' . uniqid('', true);
        mkdir($scratchDir);

        try {
            file_put_contents(
                $scratchDir . '/security-shapes-json.json',
                '{"openapi":"3.2.0","info":{"title":"Shapes","version":"1.0.0"},'
                . '"paths":{"/anonymous":{"get":{"security":[{}]}},'
                . '"/malformed":{"get":{"security":[[]]}},'
                . '"/malformed-container":{"get":{"security":{}}}},'
                . '"components":{"schemas":{"Payload":{"type":"object","required":["security"],'
                . '"properties":{"security":{}}}}}}',
            );
            file_put_contents(
                $scratchDir . '/security-shapes-yaml.yaml',
                "openapi: 3.2.0\ninfo:\n  title: Shapes\n  version: 1.0.0\npaths:\n"
                . "  /anonymous:\n    get:\n      security:\n        - {}\n"
                . "  /malformed:\n    get:\n      security:\n        - []\n"
                . "  /malformed-container:\n    get:\n      security: {}\n"
                . "components:\n  schemas:\n    Payload:\n      type: object\n      required: [security]\n"
                . "      properties:\n        security: {}\n",
            );

            OpenApiSpecLoader::configure($scratchDir);
            foreach (['security-shapes-json', 'security-shapes-yaml'] as $specName) {
                $spec = OpenApiSpecLoader::load($specName);

                $this->assertInstanceOf(stdClass::class, $spec['paths']['/anonymous']['get']['security'][0]);
                $this->assertSame([], $spec['paths']['/malformed']['get']['security'][0]);
                $this->assertInstanceOf(stdClass::class, $spec['paths']['/malformed-container']['get']['security']);
                $this->assertSame([], $spec['components']['schemas']['Payload']['properties']['security']);

                $errors = (new SecurityValidator())->validate(
                    'GET',
                    '/malformed-container',
                    $spec,
                    $spec['paths']['/malformed-container']['get'],
                    [],
                    [],
                    [],
                );
                $this->assertCount(1, $errors);
                $this->assertStringContainsString('must be a list of requirement objects', $errors[0]);
            }
        } finally {
            @unlink($scratchDir . '/security-shapes-json.json');
            @unlink($scratchDir . '/security-shapes-yaml.yaml');
            @rmdir($scratchDir);
        }
    }

    #[Test]
    public function load_throws_when_yaml_is_malformed(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('malformed-yaml');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedYaml, $e->reason);
            $this->assertSame('malformed-yaml', $e->specName);
            $this->assertStringContainsString('Failed to parse YAML', $e->getMessage());
        }
    }

    #[Test]
    public function load_throws_when_yaml_root_is_not_an_array(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('non-array-root');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::NonMappingRoot, $e->reason);
            $this->assertSame('non-array-root', $e->specName);
            $this->assertStringContainsString('YAML OpenAPI spec must decode to a mapping', $e->getMessage());
        }
    }

    #[Test]
    public function load_throws_when_json_root_is_not_an_array(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('non-array-json-root');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::NonMappingRoot, $e->reason);
            $this->assertSame('non-array-json-root', $e->specName);
            $this->assertStringContainsString('JSON OpenAPI spec must decode to a mapping', $e->getMessage());
        }
    }

    #[Test]
    public function load_throws_when_json_is_malformed(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);

        try {
            OpenApiSpecLoader::load('malformed-json');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedJson, $e->reason);
            $this->assertSame('malformed-json', $e->specName);
            $this->assertStringContainsString('Failed to parse JSON OpenAPI spec', $e->getMessage());
        }
    }

    #[Test]
    public function load_yaml_throws_with_install_hint_when_symfony_yaml_missing(): void
    {
        $fixturesPath = __DIR__ . '/../../fixtures/specs';
        OpenApiSpecLoader::configure($fixturesPath);
        YamlAvailability::overrideForTesting(false);

        try {
            OpenApiSpecLoader::load('petstore-yaml');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::YamlLibraryMissing, $e->reason);
            $this->assertStringContainsString('symfony/yaml', $e->getMessage());
            $this->assertStringContainsString('composer require', $e->getMessage());
        }
    }

    #[Test]
    public function reset_clears_yaml_availability_override_to_prevent_leaks_between_tests(): void
    {
        YamlAvailability::overrideForTesting(false);
        $this->assertFalse(
            YamlAvailability::isAvailable(),
            'sanity: override(false) should force isAvailable() to false',
        );

        OpenApiSpecLoader::reset();

        // After reset, the override must be cleared so isAvailable() falls
        // through to the real class_exists() probe. Compare against the probe
        // result directly rather than a hard-coded true/false, so this test
        // stays meaningful even if symfony/yaml ever moves out of require-dev
        // or the suite is run with --no-dev.
        $this->assertSame(
            class_exists(Yaml::class),
            YamlAvailability::isAvailable(),
            'OpenApiSpecLoader::reset() must clear the YAML availability override '
            . 'so it cannot leak into other tests via the shared static state.',
        );
    }

    #[Test]
    public function load_error_lists_all_searched_extensions_when_no_file_exists(): void
    {
        OpenApiSpecLoader::configure('/nonexistent/path');

        try {
            OpenApiSpecLoader::load('nowhere');
            $this->fail('expected SpecFileNotFoundException');
        } catch (SpecFileNotFoundException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('.json', $message);
            $this->assertStringContainsString('.yaml', $message);
            $this->assertStringContainsString('.yml', $message);
            $this->assertStringContainsString('nowhere', $message);
        }
    }
}
