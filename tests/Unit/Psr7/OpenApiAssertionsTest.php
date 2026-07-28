<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiAssertions;
use Studio\Gesso\Spec\OpenApiSpecLoader;

final class OpenApiAssertionsTest extends TestCase
{
    use OpenApiAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function asserts_a_psr7_response_for_an_explicit_operation(): void
    {
        $response = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            '{"id":42}',
        );

        $this->assertPsr7ResponseForOperationMatchesOpenApiSchema('POST', '/widgets/42', $response);
    }

    #[Test]
    public function assertion_failure_includes_the_operation_and_spec(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar');
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('GET /body/scalar', $e->getMessage());
            $this->assertStringContainsString('spec: psr7', $e->getMessage());
        }
    }

    #[Test]
    public function assertion_failure_includes_a_redacted_curl_reproduction(): void
    {
        $request = new Request(
            'GET',
            'https://example.test/body/scalar',
            ['Authorization' => 'Bearer real-token'],
        );
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString(
                "Reproduce: curl -X GET 'https://example.test/body/scalar'",
                $e->getMessage(),
            );
            $this->assertStringContainsString("-H 'Authorization: <redacted>'", $e->getMessage());
            $this->assertStringNotContainsString('real-token', $e->getMessage());
        }
    }

    #[Test]
    public function request_assertion_failure_includes_curl_with_json_body(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/widgets/42',
            ['Content-Type' => 'application/json'],
            '{"message":123}',
        );

        try {
            $this->assertPsr7RequestMatchesOpenApiSchema($request);
            $this->fail('Expected the PSR-7 request assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Reproduce: curl -X POST', $e->getMessage());
            $this->assertStringContainsString('--data \'{"message":123}\'', $e->getMessage());
        }
    }

    #[Test]
    public function operation_assertion_failure_includes_a_method_and_path_curl(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseForOperationMatchesOpenApiSchema('GET', '/body/scalar', $response);
            $this->fail('Expected the PSR-7 assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("Reproduce: curl -X GET '/body/scalar'", $e->getMessage());
        }
    }

    #[Test]
    public function successful_assertion_does_not_disturb_the_request_body_stream_cursor(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar', [], 'ignored-body');
        $request->getBody()->seek(3);
        $response = new Response(200, ['Content-Type' => 'application/json'], '5');

        $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);

        $this->assertSame(3, $request->getBody()->tell());
    }

    #[Test]
    public function failed_assertion_restores_the_request_body_stream_cursor(): void
    {
        $request = new Request('GET', 'https://example.test/body/scalar', [], 'ignored-body');
        $request->getBody()->seek(3);
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 assertion to fail.');
        } catch (AssertionFailedError) {
            $this->assertSame(3, $request->getBody()->tell());
        }
    }

    #[Test]
    public function unreadable_seekable_stream_degrades_to_a_bodyless_curl(): void
    {
        $stream = FnStream::decorate(Utils::streamFor('{"x":1}'), [
            'rewind' => static function (): void {
                throw new RuntimeException('stream is not readable');
            },
        ]);
        $request = new Request('GET', 'https://example.test/body/scalar', [], $stream);
        $response = new Response(200, ['Content-Type' => 'application/json'], '"wrong"');

        try {
            $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
            $this->fail('Expected the PSR-7 assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Reproduce: curl -X GET', $e->getMessage());
            $this->assertStringNotContainsString('--data', $e->getMessage());
        }
    }

    protected function openApiSpec(): string
    {
        return 'psr7';
    }
}
