<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Psr7;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Nyholm\Psr7\Request as NyholmRequest;
use Nyholm\Psr7\Response as NyholmResponse;
use Nyholm\Psr7\ServerRequest as NyholmServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Psr7\OpenApiPsr7Validator;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

final class OpenApiPsr7ValidatorTest extends TestCase
{
    private OpenApiPsr7Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        $this->validator = new OpenApiPsr7Validator('psr7');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function providePreserves_json_null_scalar_and_empty_body_distinctionsCases(): iterable
    {
        yield 'literal null' => ['/body/null', 200, 'null'];
        yield 'scalar' => ['/body/scalar', 200, '42'];
        yield 'empty' => ['/body/empty', 204, ''];
    }

    #[Test]
    public function validates_a_server_request_and_response_as_one_exchange(): void
    {
        $request = (new ServerRequest(
            'POST',
            'https://example.test/widgets/42?q=blue',
            ['Content-Type' => 'application/json', 'X-Token' => 'secret'],
            '{"message":"hello"}',
        ))
            ->withQueryParams(['q' => 'blue'])
            ->withCookieParams(['session' => 'abc']);
        $response = new Response(
            201,
            ['Content-Type' => 'application/json; charset=utf-8', 'X-Trace' => 'trace-1'],
            '{"id":42}',
        );

        $result = $this->validator->validateExchange($request, $response);

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/widgets/{id}', $result->requestResult()->matchedPath());
        $this->assertSame('/widgets/{id}', $result->responseResult()->matchedPath());

        $coverage = OpenApiCoverageTracker::computeCoverage('psr7');
        $this->assertSame(1, $coverage['responseCovered']);
        $this->assertArrayHasKey('POST /widgets/{id}', OpenApiCoverageTracker::getCovered()['psr7']);
    }

    #[Test]
    public function validates_nyholm_psr7_messages_through_the_same_api(): void
    {
        $request = (new NyholmServerRequest(
            'POST',
            'https://example.test/widgets/42?q=blue',
            ['Content-Type' => 'application/json', 'X-Token' => 'secret'],
            '{"message":"hello"}',
        ))->withCookieParams(['session' => 'abc']);
        $response = new NyholmResponse(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            '{"id":42}',
        );

        $result = $this->validator->validateExchange($request, $response);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function parses_query_and_cookie_values_from_a_client_request(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/widgets/42?q=blue',
            [
                'Content-Type' => 'application/json',
                'Cookie' => 'session=abc',
                'X-Token' => 'secret',
            ],
            '{"message":"hello"}',
        );

        $result = $this->validator->validateRequest($request);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function reports_a_missing_cookie_from_a_client_request(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/widgets/42?q=blue',
            ['Content-Type' => 'application/json', 'X-Token' => 'secret'],
            '{"message":"hello"}',
        );

        $result = $this->validator->validateRequest($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("api key 'session' is missing from the cookie", $result->errorMessage());
    }

    #[Test]
    public function validates_a_response_with_an_explicit_operation_address(): void
    {
        $response = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            '{"id":42}',
        );

        $result = $this->validator->validateResponseForOperation('POST', '/widgets/42', $response);

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame('/widgets/{id}', $result->matchedPath());
    }

    #[Test]
    public function preserves_custom_openapi_32_method_casing(): void
    {
        $validator = new OpenApiPsr7Validator('openapi-3.2');
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"id":2,"name":"Copy"}',
        );

        $matching = $validator->validateResponse(
            new NyholmRequest('COPY', 'https://example.test/v1/pets/1'),
            $response,
        );
        $wrongCase = $validator->validateResponse(
            new NyholmRequest('copy', 'https://example.test/v1/pets/1'),
            $response,
        );

        $this->assertTrue($matching->isValid(), $matching->errorMessage());
        $this->assertFalse($wrongCase->isValid());
    }

    #[Test]
    public function restores_the_original_position_of_a_seekable_stream(): void
    {
        $stream = Utils::streamFor('{"id":42}');
        $stream->read(4);
        $response = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            $stream,
        );

        $result = $this->validator->validateResponseForOperation('POST', '/widgets/42', $response);

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame(4, $stream->tell());
    }

    #[Test]
    public function refuses_a_non_seekable_stream_without_consuming_it(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('{"id":42}'));
        $response = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            $stream,
        );

        $result = $this->validator->validateResponseForOperation('POST', '/widgets/42', $response);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('not seekable', $result->errorMessage());
        $this->assertSame(0, $stream->tell());
        $this->assertSame('{"id":42}', $stream->getContents());
    }

    #[DataProvider('providePreserves_json_null_scalar_and_empty_body_distinctionsCases')]
    #[Test]
    public function preserves_json_null_scalar_and_empty_body_distinctions(
        string $path,
        int $status,
        string $body,
    ): void {
        $request = new Request('GET', 'https://example.test' . $path);
        $response = new Response($status, ['Content-Type' => 'application/json'], $body);

        $result = $this->validator->validateResponse($request, $response);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function reports_invalid_json_as_an_adapter_error(): void
    {
        $response = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            '{invalid',
        );

        $result = $this->validator->validateResponseForOperation('POST', '/widgets/42', $response);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('could not be parsed as JSON', $result->errorMessage());
        $this->assertSame('/widgets/{id}', $result->matchedPath());
    }
}
