<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use const UPLOAD_ERR_OK;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Nyholm\Psr7\Request as NyholmRequest;
use Nyholm\Psr7\Response as NyholmResponse;
use Nyholm\Psr7\ServerRequest as NyholmServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiPsr7Validator;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_map;
use function implode;

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
    public function validates_a_multipart_server_request_from_its_parsed_parts(): void
    {
        // Issue #405: a ServerRequest already carries the parsed fields and
        // uploaded files, so the multipart body reaches its media-type schema.
        $validator = new OpenApiPsr7Validator('non-json-content-schema');

        $request = (new ServerRequest(
            'POST',
            'https://example.test/multipart-encoded',
            ['Content-Type' => 'multipart/form-data; boundary=----x'],
        ))
            ->withParsedBody(['meta' => '{"label": "hero"}'])
            ->withUploadedFiles([
                'avatar' => new UploadedFile(Utils::streamFor('png-bytes'), 9, UPLOAD_ERR_OK, 'avatar.png', 'image/png'),
            ]);

        $result = $validator->validateRequest($request);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors()));

        $rejected = $request->withUploadedFiles([
            'avatar' => new UploadedFile(Utils::streamFor('pdf-bytes'), 9, UPLOAD_ERR_OK, 'avatar.pdf', 'application/pdf'),
        ]);

        $failure = $validator->validateRequest($rejected);

        $this->assertFalse($failure->isValid());
        $this->assertStringContainsString('application/pdf', implode(' | ', $failure->errors()));
    }

    #[Test]
    public function parses_a_raw_urlencoded_body_from_a_client_request(): void
    {
        // A client RequestInterface has no parsed bag; the raw bytes are
        // forwarded and parsed by the validator instead of being skipped.
        $validator = new OpenApiPsr7Validator('non-json-content-schema');

        $result = $validator->validateRequest(new Request(
            'POST',
            'https://example.test/form-required',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'name=Fido&age=three',
        ));

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('/age', implode(' | ', $result->errors()));
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
    public function preserves_repeated_form_explode_query_values_as_an_array(): void
    {
        $request = new Request('GET', 'https://example.test/search?tags=a&tags=b');

        $result = $this->validator->validateRequest($request);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function splits_non_exploded_query_arrays_before_percent_decoding(): void
    {
        // Logical value ["owner,admin", "member"]: the comma inside the first
        // element is %2C on the wire, the element delimiter is a literal
        // comma. Splitting after decoding could not tell them apart.
        $request = new Request('GET', 'https://example.test/filter?role=owner%2Cadmin,member');

        $result = $this->validator->validateRequest($request);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function splits_non_exploded_query_arrays_from_a_server_request_uri(): void
    {
        $request = (new ServerRequest('GET', 'https://example.test/filter?role=owner%2Cadmin,member'))
            ->withQueryParams(['role' => 'owner,admin,member']);

        $result = $this->validator->validateRequest($request);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function validates_the_parsed_query_map_when_it_diverges_from_the_uri(): void
    {
        // PSR-7 allows withQueryParams() to diverge from the URI; the parsed
        // map is what the application saw, so the URI's valid value must not
        // mask the parsed map's invalid one.
        $request = (new ServerRequest('GET', 'https://example.test/filter?role=owner'))
            ->withQueryParams(['role' => 'bogus']);

        $result = $this->validator->validateRequest($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('query.role/0', $result->errorMessage());
    }

    #[Test]
    public function reports_genuine_violations_in_non_exploded_query_arrays(): void
    {
        $request = new Request('GET', 'https://example.test/filter?role=owner,bogus');

        $result = $this->validator->validateRequest($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('query.role/1', $result->errorMessage());
    }

    #[Test]
    public function retains_an_invalid_value_before_a_repeated_query_key(): void
    {
        $request = new Request('GET', 'https://example.test/search?tags=invalid&tags=b');

        $result = $this->validator->validateRequest($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('query.tags/0', $result->errorMessage());
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

    #[Test]
    public function response_adapter_errors_preserve_structured_issues(): void
    {
        $response = new Response(
            201,
            ['Content-Type' => 'application/json', 'X-Trace' => 'trace-1'],
            '{invalid',
        );

        $result = $this->validator->validateResponseForOperation('POST', '/widgets/42', $response);

        $this->assertFalse($result->isValid());
        $issues = $result->issues();
        $this->assertSame(
            $result->errors(),
            array_map(static fn($issue) => $issue->message, $issues),
        );
        $this->assertSame('response.body', $issues[0]->category);
        $this->assertSame('POST', $issues[0]->method);
        $this->assertSame('201', $issues[0]->statusCode);
        $this->assertSame('application/json', $issues[0]->contentType);
        $this->assertStringContainsString('could not be parsed as JSON', $issues[0]->message);
        $this->assertNotContains(
            'unknown',
            array_map(static fn($issue) => $issue->category, $issues),
        );
    }

    #[Test]
    public function request_adapter_errors_preserve_structured_issues(): void
    {
        $request = (new ServerRequest(
            'POST',
            'https://example.test/widgets/42?q=blue',
            ['Content-Type' => 'application/json', 'X-Token' => 'secret'],
            '{invalid',
        ))
            ->withQueryParams(['q' => 'blue'])
            ->withCookieParams(['session' => 'abc']);

        $result = $this->validator->validateRequest($request);

        $this->assertFalse($result->isValid());
        $issues = $result->issues();
        $this->assertSame(
            $result->errors(),
            array_map(static fn($issue) => $issue->message, $issues),
        );
        $this->assertSame('request.body', $issues[0]->category);
        $this->assertSame('POST', $issues[0]->method);
        $this->assertNull($issues[0]->statusCode, 'request issues never carry a statusCode');
        $this->assertSame(
            'application/json',
            $issues[0]->contentType,
            'adapter body issue must share the media-type key its sibling body issues resolved',
        );
        $this->assertStringContainsString('could not be parsed as JSON', $issues[0]->message);
        $this->assertNotContains(
            'unknown',
            array_map(static fn($issue) => $issue->category, $issues),
        );
    }

    #[Test]
    public function request_adapter_issue_context_stays_request_side_after_downgrade(): void
    {
        // Invalid JSON + a documented 422 response: the inner request result
        // is downgraded to Skipped carrying matchedStatusCode '422'. The
        // adapter error rebuilds it as a Failure — the request-side issue
        // must not inherit that response status.
        $validator = new OpenApiPsr7Validator('request-validation-skip');
        $request = new ServerRequest(
            'POST',
            'https://example.test/exact-422',
            ['Content-Type' => 'application/json'],
            '{invalid',
        );

        $result = $validator->validateRequest($request, 422);

        $this->assertFalse($result->isValid());
        $issues = $result->issues();
        $this->assertCount(1, $issues);
        $this->assertSame('request.body', $issues[0]->category);
        $this->assertNull($issues[0]->statusCode, 'request issues never carry a statusCode');
        $this->assertSame(
            'application/json',
            $issues[0]->contentType,
            'the media-type key resolved before the downgrade must survive into the adapter issue',
        );
    }

    #[Test]
    public function request_adapter_issue_keeps_content_type_when_inner_result_succeeds(): void
    {
        // Optional request body + a non-seekable stream: the adapter refuses
        // to read the body, the inner validator sees an absent optional body
        // and succeeds — so there is no sibling body issue to borrow the
        // media-type key from. The Success result must carry the key the
        // body validator resolved.
        $stream = new NoSeekStream(Utils::streamFor('{"text":"hi"}'));
        $request = new ServerRequest(
            'POST',
            'https://example.test/notes',
            ['Content-Type' => 'application/json'],
            $stream,
        );

        $result = $this->validator->validateRequest($request);

        $this->assertFalse($result->isValid());
        $issues = $result->issues();
        $this->assertCount(1, $issues);
        $this->assertSame('request.body', $issues[0]->category);
        $this->assertStringContainsString('not seekable', $issues[0]->message);
        $this->assertNull($issues[0]->statusCode, 'request issues never carry a statusCode');
        $this->assertSame('application/json', $issues[0]->contentType);
    }

    #[Test]
    public function request_adapter_issue_keeps_content_type_alongside_non_body_sibling_errors(): void
    {
        // Optional body + unreadable stream + a path-parameter error: the
        // inner result is a Failure whose issues contain no request.body
        // entry, so the media-type key must come from the Failure's
        // result-level matchedContentType.
        $stream = new NoSeekStream(Utils::streamFor('{"text":"hi"}'));
        $request = new ServerRequest(
            'POST',
            'https://example.test/notes/abc',
            ['Content-Type' => 'application/json'],
            $stream,
        );

        $result = $this->validator->validateRequest($request);

        $this->assertFalse($result->isValid());
        $issues = $result->issues();
        $categories = array_map(static fn($issue) => $issue->category, $issues);
        $this->assertContains('request.parameter.path', $categories, 'the sibling path error must surface');
        $this->assertSame('request.body', $issues[0]->category);
        $this->assertStringContainsString('not seekable', $issues[0]->message);
        $this->assertSame('application/json', $issues[0]->contentType);
    }
}
