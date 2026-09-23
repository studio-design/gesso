<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Psr7;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiPsr7Validator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\BodyBoundaryCases;
use TypeError;

use function array_filter;
use function array_map;
use function array_values;
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

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideA_failed_upload_does_not_satisfy_a_required_file_partCases(): iterable
    {
        yield 'no file sent' => [UPLOAD_ERR_NO_FILE];
        yield 'size limit' => [UPLOAD_ERR_INI_SIZE];
    }

    /** @return iterable<string, array{string}> */
    public static function provideResponse_read_failures_report_only_parse_and_keep_header_violationsCases(): iterable
    {
        foreach (['isSeekable', 'getSize', 'tell', 'rewind', 'getContents', 'seek'] as $failure) {
            yield $failure => [$failure];
        }
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
    #[DataProvider('provideA_failed_upload_does_not_satisfy_a_required_file_partCases')]
    public function a_failed_upload_does_not_satisfy_a_required_file_part(int $uploadError): void
    {
        // PSR-7 defines UPLOAD_ERR_OK as the only successful upload. A part
        // that never arrived (or was truncated by a size limit) must not be
        // mapped onto a file the schema then counts as present.
        $validator = new OpenApiPsr7Validator('non-json-content-schema');

        $request = (new ServerRequest(
            'POST',
            'https://example.test/multipart-encoded',
            ['Content-Type' => 'multipart/form-data; boundary=----x'],
        ))->withUploadedFiles([
            'avatar' => new UploadedFile(Utils::streamFor(''), 0, $uploadError, 'avatar.png', 'image/png'),
        ]);

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('avatar', implode(' | ', $result->errors()));
    }

    #[Test]
    public function dropping_a_failed_upload_keeps_the_remaining_files_a_list(): void
    {
        // Unsetting `files[0]` would otherwise leave `{1: ...}`, which reaches
        // the schema as an object and fails `type: array` even though a valid
        // file is still there.
        $validator = new OpenApiPsr7Validator('non-json-content-schema');

        $request = (new ServerRequest(
            'POST',
            'https://example.test/multipart-file-list',
            ['Content-Type' => 'multipart/form-data; boundary=----x'],
        ))->withUploadedFiles([
            'files' => [
                new UploadedFile(Utils::streamFor(''), 0, UPLOAD_ERR_INI_SIZE, 'too-big.png', 'image/png'),
                new UploadedFile(Utils::streamFor('png'), 3, UPLOAD_ERR_OK, 'ok.png', 'image/png'),
            ],
        ]);

        $result = $validator->validateRequest($request);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors()));
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
    public function response_validation_records_coverage_exactly_once(): void
    {
        // Issue #535: recording moved into OpenApiResponseValidator; the
        // adapter must not record a second observation on top of it.
        $response = new Response(200, ['Content-Type' => 'application/json'], '7');

        $this->validator->validateResponseForOperation('GET', '/body/scalar', $response);

        $state = OpenApiCoverageTracker::exportState();
        $this->assertSame(
            ['state' => 'validated', 'hits' => 1, 'skipReason' => null],
            $state['specs']['psr7']['GET /body/scalar']['responses']['200:application/json'] ?? null,
        );
    }

    #[Test]
    public function coverage_state_follows_the_final_result_when_adapter_errors_promote_a_skip(): void
    {
        // 500 matches the default skip pattern, so the inner validator
        // returns Skipped — but the unparseable body adds adapter errors and
        // the public result is a Failure. Coverage must describe the result
        // the caller saw (a checked, failing exchange), not the raw inner
        // outcome, and the exchange still counts once.
        $response = new Response(500, ['Content-Type' => 'application/json'], '{oops');

        $result = $this->validator->validateResponseForOperation('GET', '/body/scalar', $response);

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $state = OpenApiCoverageTracker::exportState();
        $this->assertSame(
            ['state' => 'validated', 'hits' => 1, 'skipReason' => null],
            $state['specs']['psr7']['GET /body/scalar']['responses']['500:*'] ?? null,
        );
    }

    #[Test]
    public function request_skip_reason_follows_the_final_result_when_adapter_errors_promote_a_downgrade(): void
    {
        // The documented-422 downgrade turns the missing-body failure into
        // Skipped inside the validator, but the unparseable body promotes the
        // public result back to Failure. The recorded requestSkipReason must
        // match the public (non-skipped) result: null.
        $validator = new OpenApiPsr7Validator('psr7-request-downgrade');
        $request = new Request(
            'POST',
            '/notes',
            ['Content-Type' => 'application/json'],
            '{oops',
        );

        $result = $validator->validateRequest($request, 422);

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $state = OpenApiCoverageTracker::exportState();
        $endpoint = $state['specs']['psr7-request-downgrade']['POST /notes'] ?? null;
        $this->assertNotNull($endpoint);
        $this->assertTrue($endpoint['requestReached']);
        $this->assertNull($endpoint['requestSkipReason']);
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

        // Guzzle's Request upper-cases the method, so stub one that keeps
        // the casing the way a case-preserving PSR-7 implementation would.
        $requestWithMethod = function (string $method): RequestInterface {
            $request = $this->createStub(RequestInterface::class);
            $request->method('getMethod')->willReturn($method);
            $request->method('getUri')->willReturn(new Uri('https://example.test/v1/pets/1'));

            return $request;
        };

        $matching = $validator->validateResponse($requestWithMethod('COPY'), $response);
        $wrongCase = $validator->validateResponse($requestWithMethod('copy'), $response);

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
    public function nested_empty_object_round_trips_through_request_and_response(): void
    {
        // Issue #559: json_decode(..., false) at decodeBody() decodes a
        // nested `{}` as an empty object rather than `[]` flattened by assoc
        // decoding, so it validates against a `type: object` property.
        $validator = new OpenApiPsr7Validator('nested-empty-object');

        $request = new Request(
            'POST',
            'https://example.test/echo',
            ['Content-Type' => 'application/json'],
            '{"reasoning":{}}',
        );
        $response = new Response(200, ['Content-Type' => 'application/json'], '{"reasoning":{}}');

        $result = $validator->validateExchange($request, $response);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    #[DataProviderExternal(BodyBoundaryCases::class, 'json')]
    public function request_and_response_preserve_wire_body_boundaries(string $path, string $wire, bool $valid): void
    {
        $validator = new OpenApiPsr7Validator('body-boundaries');
        $request = new Request('POST', 'https://example.test' . $path, ['Content-Type' => 'application/json'], $wire);
        $response = new Response(200, ['Content-Type' => 'application/json'], $wire);

        $requestResult = $validator->validateRequest($request);
        $responseResult = $validator->validateResponse($request, $response);

        $this->assertSame($valid, $requestResult->isValid(), $requestResult->errorMessage());
        $this->assertSame($valid, $responseResult->isValid(), $responseResult->errorMessage());
    }

    #[Test]
    #[DataProviderExternal(BodyBoundaryCases::class, 'opaque')]
    public function opaque_request_preserves_body_presence(string $wire, bool $valid): void
    {
        $request = new Request('POST', 'https://example.test/opaque', ['Content-Type' => 'application/xml'], $wire);
        $result = (new OpenApiPsr7Validator('body-boundaries'))->validateRequest($request);

        $this->assertSame($valid, $result->isValid(), $result->errorMessage());
        $this->assertSame(0, $request->getBody()->tell());
    }

    #[Test]
    public function required_json_and_form_stream_failures_never_claim_an_empty_body(): void
    {
        foreach (['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'] as $type) {
            foreach (['not seekable', 'getContents', 'getSize'] as $failure) {
                $stream = $this->createStub(StreamInterface::class);
                $stream->method('isReadable')->willReturn(true);
                $stream->method('isSeekable')->willReturn($failure !== 'not seekable');
                if ($failure === 'getSize') {
                    $stream->method('getSize')->willThrowException(new TypeError('size unavailable'));
                } else {
                    $stream->method('getSize')->willReturn(null);
                }
                $stream->method('getContents')->willThrowException(new RuntimeException('read exploded'));
                $request = new Request('POST', '/required', ['Content-Type' => $type], $stream);
                foreach ([null, 422] as $statusCode) {
                    $result = (new OpenApiPsr7Validator('unreadable-body'))->validateRequest($request, $statusCode);
                    $this->assertFalse($result->isValid());
                    $this->assertCount(2, $result->issues(), $result->errorMessage());
                    $this->assertSame(['request.body', 'request.parameter.header'], array_map(static fn($issue): string => $issue->category, $result->issues()));
                    $this->assertSame('parse', $result->issues()[0]->keyword);
                    $this->assertSame($type, $result->issues()[0]->contentType);
                    $this->assertStringNotContainsString('empty', $result->errorMessage());
                }
            }
        }
    }

    #[Test]
    public function malformed_json_never_adds_a_schema_error_for_the_undecoded_value(): void
    {
        $validator = new OpenApiPsr7Validator('unreadable-body');
        $request = new Request('POST', '/required', ['Content-Type' => 'application/json'], '{');
        foreach ([$validator->validateRequest($request, 422), $validator->validateResponse($request, new Response(200, ['Content-Type' => 'application/json'], '{'))] as $result) {
            $this->assertCount(2, $result->issues(), $result->errorMessage());
            $this->assertSame('parse', $result->issues()[0]->keyword);
            $this->assertNull($result->issues()[0]->instancePath);
            $this->assertStringContainsString('parsed as JSON', $result->issues()[0]->message);
        }
    }

    #[Test]
    #[DataProvider('provideResponse_read_failures_report_only_parse_and_keep_header_violationsCases')]
    public function response_read_failures_report_only_parse_and_keep_header_violations(string $failure): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isSeekable')->willReturn($failure !== 'isSeekable');
        if ($failure !== 'getSize') {
            $stream->method('getSize')->willReturn(null);
        }
        // PSR-7 1.x has no return types here: PHPUnit defaults to null,
        // whereas 2.x defaults to 0 / ''. Explicit successful values ensure
        // the cursor-restoration case actually reaches seek() in both versions.
        if ($failure !== 'tell') {
            $stream->method('tell')->willReturn(7);
        }
        if ($failure !== 'getContents' && $failure !== 'isSeekable') {
            $stream->method('getContents')->willReturn('{}');
        }
        if ($failure !== 'isSeekable') {
            $expectedFailure = $stream->expects($this->once())->method($failure);
            if ($failure === 'seek') {
                $expectedFailure->with(7);
            }
            $expectedFailure->willThrowException(new RuntimeException('stream exploded'));
        } else {
            $stream->expects($this->never())->method('getContents');
        }
        $result = (new OpenApiPsr7Validator('unreadable-body'))->validateResponseForOperation(
            'POST',
            '/required',
            new Response(200, ['Content-Type' => 'application/json'], $stream),
        );
        $this->assertSame(['response.body', 'response.header'], array_map(static fn($issue): string => $issue->category, $result->issues()));
        $this->assertSame('parse', $result->issues()[0]->keyword);
        $this->assertStringNotContainsString('empty', $result->errorMessage());
    }

    #[Test]
    public function opaque_probe_catches_third_party_throwables_and_only_reads_one_byte(): void
    {
        foreach ([null, new RuntimeException('read exploded'), new LogicException('read exploded'), new TypeError('read exploded')] as $failure) {
            $stream = $this->createMock(StreamInterface::class);
            $stream->method('getSize')->willReturn(null);
            $stream->method('isReadable')->willReturn(true);
            $stream->method('isSeekable')->willReturn(true);
            $stream->method('tell')->willReturn(7);
            $stream->expects($this->once())->method('seek')->with(7);
            $stream->expects($this->never())->method('getContents');
            $read = $stream->expects($this->once())->method('read')->with(1);
            if ($failure === null) {
                $read->willReturn('<');
            } else {
                $read->willThrowException($failure);
            }
            $result = (new OpenApiPsr7Validator('unreadable-body'))->validateRequest(new Request('POST', '/required', ['Content-Type' => 'application/xml'], $stream), 422);
            $this->assertCount($failure === null ? 0 : 2, $result->issues(), $result->errorMessage());
            $this->assertStringNotContainsString('empty', $result->errorMessage());
        }
    }

    #[Test]
    public function opaque_known_size_non_seekable_request_does_not_consume_the_stream(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('<a/>'));
        $stream->read(1);
        $request = new Request('POST', '/opaque', ['Content-Type' => 'application/xml'], $stream);
        $result = (new OpenApiPsr7Validator('body-boundaries'))->validateRequest($request);

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame(1, $stream->tell());
        $this->assertSame('a/>', $stream->getContents());
    }

    #[Test]
    public function opaque_unknown_size_request_restores_the_cursor_or_fails_without_consuming(): void
    {
        $stream = new class (Utils::streamFor('<a/>')->detach()) extends Stream {
            public function getSize(): ?int
            {
                return null;
            }
        };
        $stream->seek(2);
        $validator = new OpenApiPsr7Validator('body-boundaries');
        $request = new Request('POST', '/opaque', ['Content-Type' => 'application/xml'], $stream);
        $result = $validator->validateRequest($request);
        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame(2, $stream->tell());

        $result = $validator->validateRequest($request->withBody(new NoSeekStream($stream)));
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('not seekable', $result->errorMessage());
        $this->assertSame(2, $stream->tell());
        $this->assertCount(1, $result->errors(), 'Unknown presence must not also be reported as empty.');
        $this->assertSame('parse', $result->issues()[0]->keyword);
        $this->assertNull($result->issues()[0]->instancePath);
    }

    #[Test]
    public function opaque_stream_is_not_inspected_when_presence_is_not_required(): void
    {
        foreach (['/optional-opaque', '/undeclared-opaque', '/missing'] as $path) {
            $stream = $this->createMock(StreamInterface::class);
            $stream->expects($this->never())->method('getSize');
            $stream->expects($this->never())->method('getContents');
            $stream->expects($this->never())->method('read');
            $request = new Request('POST', $path, ['Content-Type' => 'application/xml'], $stream);
            $result = (new OpenApiPsr7Validator('body-boundaries'))->validateRequest($request);

            $this->assertSame($path !== '/missing', $result->isValid(), $result->errorMessage());
            $this->assertSame($path === '/optional-opaque', $result->isSkipped());
        }
    }

    #[Test]
    public function unreadable_opaque_body_reports_parse_only_and_keeps_sibling_violations(): void
    {
        foreach (['/opaque', '/opaque-with-header', '/opaque-without-content'] as $path) {
            $stream = $this->createMock(StreamInterface::class);
            $stream->method('getSize')->willReturn(null);
            $stream->method('isReadable')->willReturn(false);
            $stream->expects($this->never())->method('getContents');
            $request = new Request('POST', $path, ['Content-Type' => 'application/xml'], $stream);
            $result = (new OpenApiPsr7Validator('body-boundaries'))->validateRequest($request, responseStatusCode: 422);

            $this->assertFalse($result->isValid(), 'A documented 422 cannot excuse unreadable input.');
            $this->assertCount($path === '/opaque-with-header' ? 2 : 1, $result->issues());
            $bodyIssues = array_values(array_filter($result->issues(), static fn($issue): bool => $issue->category === 'request.body'));
            $this->assertCount(1, $bodyIssues);
            $this->assertSame('parse', $bodyIssues[0]->keyword);
            $this->assertStringContainsString('not readable', $bodyIssues[0]->message);
            $this->assertNull($bodyIssues[0]->instancePath);
        }
    }

    #[Test]
    public function opaque_server_request_preserves_parsed_body_presence(): void
    {
        $request = (new ServerRequest('POST', '/opaque', ['Content-Type' => 'application/xml']))
            ->withParsedBody(['value' => 'parsed']);
        $result = (new OpenApiPsr7Validator('body-boundaries'))->validateRequest($request);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function nested_empty_object_against_an_array_property_still_fails(): void
    {
        // Inverted pin: before #559 this silently passed because assoc
        // decoding flattened `{}` to `[]`, matching `type: array`. It must
        // now fail `type: array` since the object shape is preserved.
        $validator = new OpenApiPsr7Validator('nested-empty-object');

        $result = $validator->validateRequest(new Request(
            'POST',
            'https://example.test/list',
            ['Content-Type' => 'application/json'],
            '{"items":{}}',
        ));

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('items', $result->errorMessage());
    }

    #[Test]
    public function numeric_string_keyed_object_validates_against_type_object(): void
    {
        // A numeric-string-keyed object against `type: object` must pass — it
        // was previously misread as a list by assoc decoding.
        $validator = new OpenApiPsr7Validator('nested-empty-object');

        $result = $validator->validateRequest(new Request(
            'POST',
            'https://example.test/echo',
            ['Content-Type' => 'application/json'],
            '{"reasoning":{"0":"a"}}',
        ));

        $this->assertTrue($result->isValid(), $result->errorMessage());
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
