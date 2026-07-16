<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Internal\HttpRefLoader;
use Studio\Gesso\Tests\Helpers\FakeHttpClient;
use Studio\Gesso\Tests\Helpers\FakeHttpClientUnexpectedRequest;

use function ini_set;
use function substr;

class HttpRefLoaderTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new HttpFactory();
    }

    #[Test]
    public function fetches_and_decodes_json_via_url_extension(): void
    {
        $url = 'https://example.com/schemas/pet.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{"type":"object","required":["id"]}'),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);

        $this->assertSame($url, $result->canonicalIdentifier);
        $this->assertSame(['type' => 'object', 'required' => ['id']], $result->decoded);
    }

    #[Test]
    public function fetches_and_decodes_yaml_via_url_extension(): void
    {
        $url = 'https://example.com/schemas/pet.yaml';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::yamlResponse("type: object\nrequired:\n  - id\n"),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);

        $this->assertSame(['type' => 'object', 'required' => ['id']], $result->decoded);
    }

    #[Test]
    public function falls_back_to_content_type_when_url_has_no_extension(): void
    {
        // Some servers expose JSON via opaque URLs (e.g. `/registry/Pet`)
        // without a file extension. The Content-Type header is the only
        // format cue.
        $url = 'https://registry.example.com/Pet';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/json'], '{"type":"string"}'),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['registry.example.com']);

        $this->assertSame(['type' => 'string'], $result->decoded);
    }

    #[Test]
    public function falls_back_to_application_problem_json_content_type(): void
    {
        $url = 'https://example.com/schemas/error';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/problem+json'], '{"type":"object"}'),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);

        $this->assertSame(['type' => 'object'], $result->decoded);
    }

    #[Test]
    public function honours_text_yaml_content_type(): void
    {
        $url = 'https://example.com/registry/Schema';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'text/yaml; charset=utf-8'], "type: integer\n"),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);

        $this->assertSame(['type' => 'integer'], $result->decoded);
    }

    #[Test]
    public function caches_response_and_does_not_re_fetch_on_second_call(): void
    {
        $url = 'https://example.com/pet.json';
        $callCount = 0;
        $client = new FakeHttpClient([
            $url => static function (RequestInterface $req) use (&$callCount): Response {
                $callCount++;

                return new Response(200, ['Content-Type' => 'application/json'], '{"v":1}');
            },
        ]);

        $cache = [];
        HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
        HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);

        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function throws_remote_ref_fetch_failed_on_404(): void
    {
        $url = 'https://example.com/missing.json';
        $client = new FakeHttpClient([
            $url => new Response(404),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('404', $e->getMessage());
            $this->assertStringContainsString($url, $e->getMessage());
        }
    }

    #[Test]
    public function throws_remote_ref_fetch_failed_on_5xx(): void
    {
        $url = 'https://example.com/server-error.json';
        $client = new FakeHttpClient([
            $url => new Response(500),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }

    #[Test]
    public function throws_remote_ref_fetch_failed_on_network_exception(): void
    {
        $url = 'https://example.com/unreachable.json';
        $client = new FakeHttpClient([
            $url => static function (): Response {
                throw new FakeHttpClientUnexpectedRequest('connection refused');
            },
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('connection refused', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    #[Test]
    public function throws_unsupported_extension_when_neither_url_nor_content_type_indicates_format(): void
    {
        $url = 'https://example.com/opaque-resource';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'text/plain'], 'whatever'),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedExtension, $e->reason);
            $this->assertStringContainsString($url, $e->getMessage());
        }
    }

    #[Test]
    public function throws_malformed_json_on_invalid_body(): void
    {
        $url = 'https://example.com/bad.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('{ not valid json'),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedJson, $e->reason);
            $this->assertNotNull($e->getPrevious());
        }
    }

    #[Test]
    public function throws_malformed_yaml_on_invalid_body(): void
    {
        $url = 'https://example.com/bad.yaml';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::yamlResponse("key: value\n  bad: indent\n"),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedYaml, $e->reason);
        }
    }

    #[Test]
    public function rejects_3xx_redirect_with_hint_pointing_at_location(): void
    {
        // Redirect following would happen below Gesso's allowlist boundary,
        // so the diagnostic must keep it disabled and point users at the
        // canonical URL instead of recommending an SSRF bypass.
        $url = 'https://example.com/redirect.json';
        $client = new FakeHttpClient([
            $url => new Response(302, ['Location' => 'https://example.com/canonical.json']),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('302', $e->getMessage());
            $this->assertStringContainsString('https://example.com/canonical.json', $e->getMessage());
            $this->assertStringContainsString('Keep redirects disabled', $e->getMessage());
            $this->assertStringContainsString('canonical URL', $e->getMessage());
            $this->assertStringNotContainsString('follow redirects', $e->getMessage());
        }
    }

    #[Test]
    public function redacts_credentials_from_redirect_location(): void
    {
        $url = 'https://example.com/redirect.json';
        $client = new FakeHttpClient([
            $url => new Response(302, [
                'Location' => 'https://alice:secret@example.com/canonical.json?token=redirect-secret',
            ]),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertStringNotContainsString('alice', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringContainsString('https://example.com/canonical.json?token=[redacted]', $e->getMessage());
        }
    }

    #[Test]
    public function url_extension_takes_precedence_over_conflicting_content_type(): void
    {
        // Pin the documented priority: extension wins, even if the
        // server's Content-Type disagrees. A YAML URL whose server
        // mistakenly returns `application/json` should still parse as YAML.
        $url = 'https://example.com/pet.yaml';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/json'], "type: object\n"),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);

        $this->assertSame(['type' => 'object'], $result->decoded);
    }

    #[Test]
    public function throws_unsupported_extension_when_content_type_header_is_empty(): void
    {
        $url = 'https://example.com/opaque-resource';
        $client = new FakeHttpClient([
            $url => new Response(200, [], 'whatever'),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedExtension, $e->reason);
        }
    }

    #[Test]
    public function tolerates_duplicate_content_type_headers_concatenated_with_comma(): void
    {
        // RFC 7230 §3.2.2 forbids it, but real servers occasionally send
        // duplicate Content-Type headers. PSR-7 getHeaderLine() concatenates
        // them with `, `; the detector must split on `,` as well as `;`
        // so the first usable type wins.
        $url = 'https://example.com/registry/Schema';
        $client = new FakeHttpClient([
            $url => new Response(
                200,
                ['Content-Type' => ['application/json', 'application/json']],
                '{"type":"object"}',
            ),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);

        $this->assertSame(['type' => 'object'], $result->decoded);
    }

    #[Test]
    public function redacts_userinfo_credentials_from_error_messages(): void
    {
        // Spec authors occasionally embed credentials in $ref URLs for
        // testing; those must not leak into stderr / CI logs via error
        // messages.
        $url = 'https://alice:secret@example.com/private/pet.json';
        $client = new FakeHttpClient([
            $url => new Response(404),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringNotContainsString('alice', $e->getMessage());
            $this->assertStringContainsString('example.com', $e->getMessage());
        }
    }

    #[Test]
    public function redacts_credentials_from_transport_exception_chain(): void
    {
        $url = 'https://alice:secret@example.com/private/pet.json?token=query-secret';
        $client = new FakeHttpClient([
            $url => static function () use ($url): Response {
                throw new FakeHttpClientUnexpectedRequest('request failed for ' . $url);
            },
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertStringNotContainsString('alice', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringContainsString('https://example.com/private/pet.json?token=[redacted]', $e->getMessage());
            $this->assertSame('https://example.com/private/pet.json?token=[redacted]', $e->ref);
            $this->assertNull($e->getPrevious());
        }
    }

    #[Test]
    public function does_not_reconnect_a_sensitive_nested_transport_exception(): void
    {
        $url = 'https://alice:secret@example.com/pet.json?token=query-secret';
        $client = new FakeHttpClient([
            $url => static function (): Response {
                $cause = new RuntimeException(
                    'request failed for https://nested:nested-secret@example.com/private.json?token=nested-query-secret',
                );

                throw new FakeHttpClientUnexpectedRequest('transport failed', 0, $cause);
            },
        ]);

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
                $cache = [];
                HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
                $this->fail('expected InvalidOpenApiSpecException');
            } catch (InvalidOpenApiSpecException $e) {
                $rendered = (string) $e;

                $this->assertStringContainsString('transport failed', $rendered);
                $this->assertStringNotContainsString('alice:secret', $rendered);
                $this->assertStringNotContainsString('query-secret', $rendered);
                $this->assertStringNotContainsString('nested:nested-secret', $rendered);
                $this->assertStringNotContainsString('nested-query-secret', $rendered);
                $this->assertNull($e->getPrevious());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            ini_set('zend.exception_string_param_max_len', $previousMaxLength);
        }
    }

    #[Test]
    public function redacts_credentials_from_response_body_exception_chain(): void
    {
        $url = 'https://example.com/private/pet.json';
        $cause = new RuntimeException(
            'socket failed for https://nested:nested-secret@example.com/body.json?token=nested-query-secret',
        );
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'isSeekable' => static fn(): bool => false,
            'read' => static function (int $length) use ($cause): never {
                throw new RuntimeException(
                    'body read failed for https://alice:secret@example.com/body.json?token=query-secret',
                    0,
                    $cause,
                );
            },
        ]);
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/json'], $stream),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $rendered = (string) $e;

            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('body read failed', $rendered);
            $this->assertStringContainsString('https://example.com/body.json?token=[redacted]', $rendered);
            $this->assertStringNotContainsString('alice', $rendered);
            $this->assertStringNotContainsString('secret', $rendered);
            $this->assertStringNotContainsString('query-secret', $rendered);
            $this->assertStringNotContainsString('nested-query-secret', $rendered);
            $this->assertNull($e->getPrevious());
        }
    }

    #[Test]
    public function tolerates_a_transient_empty_read_before_response_data_is_available(): void
    {
        $url = 'https://example.com/delayed.json';
        $readCount = 0;
        $finished = false;
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'getSize' => static fn(): ?int => null,
            'eof' => static function () use (&$finished): bool {
                return $finished;
            },
            'read' => static function (int $length) use (&$readCount, &$finished): string {
                $readCount++;
                if ($readCount === 1) {
                    return '';
                }

                $finished = true;

                return substr('{"type":"object"}', 0, $length);
            },
        ]);
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/json'], $stream),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);

        $this->assertSame(['type' => 'object'], $result->decoded);
        $this->assertSame(2, $readCount);
    }

    #[Test]
    public function rejects_a_response_stream_that_repeatedly_makes_no_progress(): void
    {
        $url = 'https://example.com/stalled.json';
        $readCount = 0;
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'getSize' => static fn(): ?int => null,
            'eof' => static fn(): bool => false,
            'read' => static function (int $length) use (&$readCount): string {
                $readCount++;

                return '';
            },
        ]);
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/json'], $stream),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('made no progress', $e->getMessage());
            $this->assertLessThanOrEqual(10, $readCount);
        }
    }

    #[Test]
    public function accepts_response_exactly_at_the_configured_limit(): void
    {
        $url = 'https://example.com/exact.json';
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/json'], '{"a":1}'),
        ]);

        $cache = [];
        $result = HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com'], 7);

        $this->assertSame(['a' => 1], $result->decoded);
    }

    #[Test]
    public function rejects_response_whose_content_length_exceeds_the_configured_limit(): void
    {
        $url = 'https://example.com/oversized.json';
        $client = new FakeHttpClient([
            $url => new Response(200, [
                'Content-Type' => 'application/json',
                'Content-Length' => '1024',
            ], '{}'),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com'], 10);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('exceeds', $e->getMessage());
            $this->assertStringContainsString('10 bytes', $e->getMessage());
        }
    }

    #[Test]
    public function rejects_streamed_response_that_exceeds_the_configured_limit(): void
    {
        $url = 'https://example.com/streamed.json';
        $stream = FnStream::decorate(Utils::streamFor('{"value":"too large"}'), [
            'getSize' => static fn(): ?int => null,
        ]);
        $client = new FakeHttpClient([
            $url => new Response(200, ['Content-Type' => 'application/json'], $stream),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com'], 10);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::RemoteRefFetchFailed, $e->reason);
            $this->assertStringContainsString('exceeds', $e->getMessage());
            $this->assertStringContainsString('10 bytes', $e->getMessage());
        }
    }

    #[Test]
    public function throws_non_mapping_root_when_response_decodes_to_scalar(): void
    {
        $url = 'https://example.com/scalar.json';
        $client = new FakeHttpClient([
            $url => FakeHttpClient::jsonResponse('"just a string"'),
        ]);

        try {
            $cache = [];
            HttpRefLoader::loadDocument($url, $client, $this->factory, $cache, ['example.com']);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::NonMappingRoot, $e->reason);
        }
    }
}
