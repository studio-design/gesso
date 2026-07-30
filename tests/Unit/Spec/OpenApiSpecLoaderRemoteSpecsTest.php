<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use const JSON_THROW_ON_ERROR;

use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Spec\RemoteSpecSource;
use Studio\Gesso\Tests\Helpers\FakeHttpClient;

use function file_put_contents;
use function hash;
use function json_encode;
use function mkdir;
use function putenv;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class OpenApiSpecLoaderRemoteSpecsTest extends TestCase
{
    private const ENTRY_URL = 'https://specs.example.com/openapi.json';
    private const AUTH_ENV = 'GESSO_TEST_REMOTE_SPEC_TOKEN';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        $this->clearAuthEnv();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        $this->clearAuthEnv();
        parent::tearDown();
    }

    #[Test]
    public function configure_rejects_remote_specs_without_allow_remote_refs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('remoteSpecs');

        OpenApiSpecLoader::configure(
            '/path/to/specs',
            remoteSpecs: ['api' => self::ENTRY_URL],
        );
    }

    #[Test]
    public function configure_rejects_remote_spec_url_host_outside_allowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allowedRemoteRefHosts');

        OpenApiSpecLoader::configure(
            '/path/to/specs',
            httpClient: new FakeHttpClient(),
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['other.example.com'],
            remoteSpecs: ['api' => self::ENTRY_URL],
        );
    }

    #[Test]
    public function configure_rejects_empty_remote_spec_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('spec name');

        OpenApiSpecLoader::configure(
            '/path/to/specs',
            httpClient: new FakeHttpClient(),
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['specs.example.com'],
            remoteSpecs: ['  ' => self::ENTRY_URL],
        );
    }

    #[Test]
    public function load_fetches_remote_entry_document(): void
    {
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse(self::minimalSpecJson('Remote API')),
        ]);
        self::configureRemote($client, ['api' => self::ENTRY_URL]);

        $spec = OpenApiSpecLoader::load('api');

        $this->assertSame('Remote API', $spec['info']['title']);
        $this->assertSame([self::ENTRY_URL], $client->sentUrls());
    }

    #[Test]
    public function load_accepts_remote_spec_source_object(): void
    {
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse(self::minimalSpecJson('Remote API')),
        ]);
        self::configureRemote($client, ['api' => new RemoteSpecSource(self::ENTRY_URL)]);

        $spec = OpenApiSpecLoader::load('api');

        $this->assertSame('Remote API', $spec['info']['title']);
    }

    #[Test]
    public function load_prefers_remote_mapping_over_local_file(): void
    {
        $tempDir = sys_get_temp_dir() . '/openapi-remote-spec-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/api.json', self::minimalSpecJson('Local API'));

        try {
            $client = new FakeHttpClient([
                self::ENTRY_URL => FakeHttpClient::jsonResponse(self::minimalSpecJson('Remote API')),
            ]);
            self::configureRemote($client, ['api' => self::ENTRY_URL], basePath: $tempDir);

            $spec = OpenApiSpecLoader::load('api');

            $this->assertSame('Remote API', $spec['info']['title']);
        } finally {
            @unlink($tempDir . '/api.json');
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function load_caches_remote_spec_per_process(): void
    {
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse(self::minimalSpecJson('Remote API')),
        ]);
        self::configureRemote($client, ['api' => self::ENTRY_URL]);

        OpenApiSpecLoader::load('api');
        OpenApiSpecLoader::load('api');

        $this->assertCount(1, $client->sentUrls());
    }

    #[Test]
    public function load_sends_authorization_header_from_env(): void
    {
        putenv(self::AUTH_ENV . '=Bearer secret-token');

        $seenAuthorization = null;
        $client = new FakeHttpClient([
            self::ENTRY_URL => static function (RequestInterface $request) use (&$seenAuthorization) {
                $seenAuthorization = $request->getHeaderLine('Authorization');

                return FakeHttpClient::jsonResponse(self::minimalSpecJson('Remote API'));
            },
        ]);
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, authorizationEnv: self::AUTH_ENV),
        ]);

        OpenApiSpecLoader::load('api');

        $this->assertSame('Bearer secret-token', $seenAuthorization);
    }

    #[Test]
    public function load_sends_authorization_to_same_host_relative_ref(): void
    {
        putenv(self::AUTH_ENV . '=Bearer secret-token');

        $entry = json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Remote API', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => ['Pet' => ['$ref' => './pet.json']]],
        ], JSON_THROW_ON_ERROR);

        $authorizations = [];
        $record = static function (RequestInterface $request, string $body) use (&$authorizations) {
            $authorizations[(string) $request->getUri()] = $request->getHeaderLine('Authorization');

            return FakeHttpClient::jsonResponse($body);
        };

        $client = new FakeHttpClient([
            self::ENTRY_URL => static fn(RequestInterface $r) => $record($r, $entry),
            'https://specs.example.com/pet.json' => static fn(RequestInterface $r) => $record($r, '{"type":"object"}'),
        ]);
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, authorizationEnv: self::AUTH_ENV),
        ]);

        OpenApiSpecLoader::load('api');

        $this->assertSame(
            [
                self::ENTRY_URL => 'Bearer secret-token',
                'https://specs.example.com/pet.json' => 'Bearer secret-token',
            ],
            $authorizations,
        );
    }

    #[Test]
    public function load_does_not_send_authorization_to_other_hosts(): void
    {
        putenv(self::AUTH_ENV . '=Bearer secret-token');

        $crossHostUrl = 'https://shared.example.org/error.json';
        $entry = json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Remote API', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => ['Error' => ['$ref' => $crossHostUrl]]],
        ], JSON_THROW_ON_ERROR);

        $crossHostAuthorization = null;
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse($entry),
            $crossHostUrl => static function (RequestInterface $request) use (&$crossHostAuthorization) {
                $crossHostAuthorization = $request->getHeaderLine('Authorization');

                return FakeHttpClient::jsonResponse('{"type":"object"}');
            },
        ]);
        self::configureRemote(
            $client,
            ['api' => new RemoteSpecSource(self::ENTRY_URL, authorizationEnv: self::AUTH_ENV)],
            allowedHosts: ['specs.example.com', 'shared.example.org'],
        );

        OpenApiSpecLoader::load('api');

        $this->assertSame('', $crossHostAuthorization);
    }

    #[Test]
    public function load_does_not_send_authorization_on_scheme_downgrade(): void
    {
        putenv(self::AUTH_ENV . '=Bearer secret-token');

        $downgradeUrl = 'http://specs.example.com/private.json';
        $entry = json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Remote API', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => ['Private' => ['$ref' => $downgradeUrl]]],
        ], JSON_THROW_ON_ERROR);

        $downgradeAuthorization = null;
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse($entry),
            $downgradeUrl => static function (RequestInterface $request) use (&$downgradeAuthorization) {
                $downgradeAuthorization = $request->getHeaderLine('Authorization');

                return FakeHttpClient::jsonResponse('{"type":"object"}');
            },
        ]);
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, authorizationEnv: self::AUTH_ENV),
        ]);

        OpenApiSpecLoader::load('api');

        $this->assertSame('', $downgradeAuthorization);
    }

    #[Test]
    public function load_does_not_send_authorization_to_other_ports(): void
    {
        putenv(self::AUTH_ENV . '=Bearer secret-token');

        $otherPortUrl = 'https://specs.example.com:8443/private.json';
        $entry = json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Remote API', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => ['Private' => ['$ref' => $otherPortUrl]]],
        ], JSON_THROW_ON_ERROR);

        $otherPortAuthorization = null;
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse($entry),
            $otherPortUrl => static function (RequestInterface $request) use (&$otherPortAuthorization) {
                $otherPortAuthorization = $request->getHeaderLine('Authorization');

                return FakeHttpClient::jsonResponse('{"type":"object"}');
            },
        ]);
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, authorizationEnv: self::AUTH_ENV),
        ]);

        OpenApiSpecLoader::load('api');

        $this->assertSame('', $otherPortAuthorization);
    }

    #[Test]
    public function load_sends_authorization_when_ref_spells_out_the_default_port(): void
    {
        putenv(self::AUTH_ENV . '=Bearer secret-token');

        $explicitPortUrl = 'https://specs.example.com:443/pet.json';
        $entry = json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Remote API', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => ['Pet' => ['$ref' => $explicitPortUrl]]],
        ], JSON_THROW_ON_ERROR);

        $explicitPortAuthorization = null;
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse($entry),
            // PSR-7 URI normalization drops the default port from the wire
            // request, so the mock is keyed on the normalized form.
            'https://specs.example.com/pet.json' => static function (RequestInterface $request) use (&$explicitPortAuthorization) {
                $explicitPortAuthorization = $request->getHeaderLine('Authorization');

                return FakeHttpClient::jsonResponse('{"type":"object"}');
            },
        ]);
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, authorizationEnv: self::AUTH_ENV),
        ]);

        OpenApiSpecLoader::load('api');

        $this->assertSame('Bearer secret-token', $explicitPortAuthorization);
    }

    #[Test]
    public function load_reuses_the_verified_entry_document_for_self_refs(): void
    {
        $pinnedBody = (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Pinned API', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => [
                'Base' => ['type' => 'object'],
                'Self' => ['$ref' => self::ENTRY_URL . '#/components/schemas/Base'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $fetches = 0;
        $client = new FakeHttpClient([
            self::ENTRY_URL => static function () use (&$fetches, $pinnedBody) {
                $fetches++;

                // A second fetch would bypass the SHA-256 pin: serve
                // different bytes so a refetch cannot pass unnoticed.
                return FakeHttpClient::jsonResponse(
                    $fetches === 1 ? $pinnedBody : '{"type":"string","x-tampered":true}',
                );
            },
        ]);
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, expectedSha256: hash('sha256', $pinnedBody)),
        ]);

        $spec = OpenApiSpecLoader::load('api');

        $this->assertSame(1, $fetches);
        $this->assertSame(['type' => 'object'], $spec['components']['schemas']['Self']);
    }

    #[Test]
    public function load_throws_when_authorization_env_is_missing(): void
    {
        $client = new FakeHttpClient();
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, authorizationEnv: self::AUTH_ENV),
        ]);

        try {
            OpenApiSpecLoader::load('api');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteSpecAuthEnvMissing, $e->reason);
            $this->assertStringContainsString(self::AUTH_ENV, $e->getMessage());
            $this->assertSame('api', $e->specName);
            // No request may leave the process without the credential.
            $this->assertSame([], $client->sentUrls());
        }
    }

    #[Test]
    public function load_accepts_matching_expected_sha256(): void
    {
        $body = self::minimalSpecJson('Pinned API');
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse($body),
        ]);
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, expectedSha256: hash('sha256', $body)),
        ]);

        $spec = OpenApiSpecLoader::load('api');

        $this->assertSame('Pinned API', $spec['info']['title']);
    }

    #[Test]
    public function load_rejects_expected_sha256_mismatch(): void
    {
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse(self::minimalSpecJson('Remote API')),
        ]);
        self::configureRemote($client, [
            'api' => new RemoteSpecSource(self::ENTRY_URL, expectedSha256: str_repeat('0', 64)),
        ]);

        try {
            OpenApiSpecLoader::load('api');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteSpecHashMismatch, $e->reason);
            $this->assertStringContainsString(str_repeat('0', 64), $e->getMessage());
            $this->assertSame('api', $e->specName);
        }
    }

    #[Test]
    public function load_validates_remote_document_version(): void
    {
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse('{"info":{"title":"No version","version":"1"}}'),
        ]);
        self::configureRemote($client, ['api' => self::ENTRY_URL]);

        try {
            OpenApiSpecLoader::load('api');
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedVersion, $e->reason);
            $this->assertSame('api', $e->specName);
        }
    }

    #[Test]
    public function reset_clears_remote_specs(): void
    {
        $client = new FakeHttpClient([
            self::ENTRY_URL => FakeHttpClient::jsonResponse(self::minimalSpecJson('Remote API')),
        ]);
        self::configureRemote($client, ['api' => self::ENTRY_URL]);
        OpenApiSpecLoader::reset();

        OpenApiSpecLoader::configure('/path/to/nowhere');

        $this->expectException(SpecFileNotFoundException::class);
        OpenApiSpecLoader::load('api');
    }

    /**
     * @param array<string, RemoteSpecSource|string> $remoteSpecs
     * @param null|list<string> $allowedHosts
     */
    private static function configureRemote(
        FakeHttpClient $client,
        array $remoteSpecs,
        string $basePath = '/path/to/specs',
        ?array $allowedHosts = null,
    ): void {
        OpenApiSpecLoader::configure(
            $basePath,
            httpClient: $client,
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
            allowedRemoteRefHosts: $allowedHosts ?? ['specs.example.com'],
            remoteSpecs: $remoteSpecs,
        );
    }

    private static function minimalSpecJson(string $title): string
    {
        return (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => $title, 'version' => '1'],
            'paths' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function clearAuthEnv(): void
    {
        putenv(self::AUTH_ENV);
        unset($_ENV[self::AUTH_ENV], $_SERVER[self::AUTH_ENV]);
    }
}
