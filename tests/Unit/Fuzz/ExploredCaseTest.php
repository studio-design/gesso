<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\FailureReducer;
use Studio\Gesso\HttpMethod;

use function http_build_query;
use function parse_str;

class ExploredCaseTest extends TestCase
{
    #[Test]
    public function rejects_empty_matched_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty matchedPath');

        new ExploredCase(
            body: null,
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::GET,
            matchedPath: '',
        );
    }

    #[Test]
    public function exposes_provided_fields(): void
    {
        $case = new ExploredCase(
            body: ['name' => 'Snowy'],
            query: ['dryRun' => true],
            headers: ['X-Trace' => 'abc'],
            pathParams: ['petId' => 7],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets',
        );

        $this->assertSame(['name' => 'Snowy'], $case->body);
        $this->assertSame(['dryRun' => true], $case->query);
        $this->assertSame(['X-Trace' => 'abc'], $case->headers);
        $this->assertSame(['petId' => 7], $case->pathParams);
        $this->assertSame(HttpMethod::POST, $case->method);
        $this->assertSame('/v1/pets', $case->matchedPath);
    }

    #[Test]
    public function renders_php_and_curl_reproduction_output(): void
    {
        $case = new ExploredCase(
            body: ['name' => 'Snowy'],
            query: ['dryRun' => true],
            headers: ['X-Trace' => 'abc'],
            pathParams: ['petId' => 7],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets/{petId}',
            seed: 42,
            caseIndex: 2,
        );

        $this->assertStringContainsString('cases: 3, seed: 42', $case->replaySnippet('petstore'));
        $this->assertStringContainsString("curl -X POST 'https://api.example.test/v1/pets/7?", $case->curlSnippet('https://api.example.test'));
        $this->assertStringContainsString("--data '{\"name\":\"Snowy\"}'", $case->curlSnippet());
    }

    #[Test]
    public function carries_cookies_separately_from_headers(): void
    {
        $case = new ExploredCase(
            body: null,
            query: [],
            headers: ['X-Trace' => 'abc'],
            pathParams: [],
            method: HttpMethod::GET,
            matchedPath: '/v1/pets',
        );

        $this->assertSame([], $case->cookies);

        // Cookies are a distinct transport: test clients build their cookie bag
        // from a separate argument, so a dispatcher cannot recover them from a
        // Cookie request header.
        $withCookies = $case->withCookies(['session' => 'abc123']);
        $this->assertSame(['session' => 'abc123'], $withCookies->cookies);
        $this->assertSame(['X-Trace' => 'abc'], $withCookies->headers);
        $this->assertSame([], $case->cookies, 'the original case is unchanged');
        $this->assertSame(['session' => 'abc123'], $withCookies->withHeaders(['X-Other' => '1'])->cookies);

        // The curl snippet still renders the wire form so the command replays.
        $this->assertStringContainsString("-H 'Cookie: <redacted>'", $withCookies->curlSnippet());
        $this->assertStringContainsString(
            "-H 'Cookie: session=abc123'",
            $withCookies->curlSnippet(redactSensitiveHeaders: false),
        );
    }

    #[Test]
    public function redacts_sensitive_headers_in_curl_snippet_by_default(): void
    {
        $case = new ExploredCase(
            body: null,
            query: [],
            headers: ['Authorization' => 'Bearer real-token', 'X-Trace' => 'abc'],
            pathParams: [],
            method: HttpMethod::GET,
            matchedPath: '/v1/pets',
        );

        $redacted = $case->curlSnippet();

        $this->assertStringContainsString("-H 'Authorization: <redacted>'", $redacted);
        $this->assertStringContainsString("-H 'X-Trace: abc'", $redacted);
        $this->assertStringNotContainsString('real-token', $redacted);
        $this->assertStringContainsString(
            "-H 'Authorization: Bearer real-token'",
            $case->curlSnippet(redactSensitiveHeaders: false),
        );
    }

    #[Test]
    public function builds_a_concrete_uri_with_encoded_path_and_query_values(): void
    {
        $case = new ExploredCase(
            body: null,
            query: [
                'search' => 'snowy owl',
                'page' => 2,
                'active' => true,
                'filters' => ['archived' => false],
            ],
            headers: [],
            pathParams: ['petId' => 'pet/7'],
            method: HttpMethod::GET,
            matchedPath: '/v1/pets/{petId}',
        );

        $expectedQuery = 'search=snowy+owl&page=2&active=true&filters%5Barchived%5D=false';

        $this->assertSame('/api/v1/pets/pet%2F7?' . $expectedQuery, $case->uri('/api'));
        $this->assertStringContainsString(
            "'https://api.example.test/v1/pets/pet%2F7?{$expectedQuery}'",
            $case->curlSnippet('https://api.example.test'),
        );
    }

    #[Test]
    public function converts_json_object_and_array_bodies_for_array_typed_http_helpers(): void
    {
        $object = new stdClass();
        $object->name = 'Snowy';
        $object->details = (object) ['age' => 3];

        $objectCase = $this->caseWithBody($object);
        $arrayCase = $this->caseWithBody(['name' => 'Snowy']);

        $this->assertSame(['name' => 'Snowy', 'details' => ['age' => 3]], $objectCase->bodyAsArray());
        $this->assertSame(['name' => 'Snowy'], $arrayCase->bodyAsArray());
        $this->assertNull($this->caseWithBody(null)->bodyAsArray());
        $this->assertSame([], $this->caseWithBody(new stdClass())->bodyAsArray());
    }

    #[Test]
    public function encodes_json_bodies_without_losing_object_array_or_scalar_types(): void
    {
        foreach ([
            [new stdClass(), '{}'],
            [[], '[]'],
            [(object) ['nested' => new stdClass(), 'list' => [new stdClass()]], '{"nested":{},"list":[{}]}'],
            [(object) ['0' => 'zero'], '{"0":"zero"}'],
            ['scalar', '"scalar"'], [false, 'false'], [42, '42'], [null, 'null'],
        ] as [$value, $wire]) {
            $this->assertSame($wire, $this->caseWithBody($value)->bodyAsJson());
        }
        $this->assertSame('{}', $this->caseWithBody(new stdClass())->bodyAsJson());
        $this->assertSame('[]', $this->caseWithBody([])->bodyAsJson());
        $this->assertSame('null', $this->caseWithBody(null)->bodyAsJson());
        $this->assertSame('1.0', $this->caseWithBody(1.0)->bodyAsJson());
    }

    #[Test]
    public function json_accessor_reports_encoding_failures(): void
    {
        $this->expectException(JsonException::class);
        $this->caseWithBody("\xFF")->bodyAsJson();
    }

    #[Test]
    public function rejects_scalar_bodies_for_array_typed_http_helpers(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires a JSON object or array body');

        $this->caseWithBody('scalar')->bodyAsArray();
    }

    #[Test]
    public function reducer_preserves_the_original_failure_classification(): void
    {
        $case = new ExploredCase(
            body: ['trigger' => true, 'noiseA' => 1, 'noiseB' => 2],
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets',
        );

        $reduced = FailureReducer::reduce(
            $case,
            static fn(ExploredCase $candidate): ?string => ($candidate->body['trigger'] ?? false) === true ? 'status:500' : null,
        );

        $this->assertSame(['trigger' => true], $reduced->body);
    }

    #[Test]
    public function defaults_the_body_media_type_to_json(): void
    {
        $case = new ExploredCase(['a' => 1.0], [], [], [], HttpMethod::POST, '/things');

        $this->assertSame('application/json', $case->contentType);
        $this->assertSame($case->bodyAsJson(), $case->bodyAsString());

        $form = new ExploredCase(['a' => 1], [], [], [], HttpMethod::POST, '/things', contentType: 'application/x-www-form-urlencoded');
        $this->assertSame('application/x-www-form-urlencoded', $form->withBody(['b' => 2])->contentType);
    }

    #[Test]
    public function serializes_a_form_body_as_urlencoded_fields(): void
    {
        // Issue #498: values travel as strings, objects JSON-encoded (the
        // Encoding Object default), and null is an absent field.
        $case = new ExploredCase(
            body: ['grant_type' => 'client_credentials', 'ttl' => 30, 'remember' => true, 'scopes' => ['a', 'b'], 'meta' => ['k' => 'v'], 'nothing' => null],
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::POST,
            matchedPath: '/oauth/token',
            contentType: 'application/x-www-form-urlencoded',
        );

        $fields = ['grant_type' => 'client_credentials', 'ttl' => '30', 'remember' => 'true', 'scopes' => ['a', 'b'], 'meta' => '{"k":"v"}'];
        $this->assertSame($fields, $case->bodyAsFormFields());
        $this->assertSame(http_build_query($fields), $case->bodyAsString());
        parse_str($case->bodyAsString(), $parsed);
        $this->assertSame($fields, $parsed);
    }

    #[Test]
    public function renders_the_form_media_type_in_the_curl_snippet(): void
    {
        $case = new ExploredCase(['grant_type' => 'x', 'client_secret' => 's3cr3t'], [], [], [], HttpMethod::POST, '/oauth/token', contentType: 'application/x-www-form-urlencoded');

        $snippet = $case->curlSnippet();
        $this->assertStringContainsString("-H 'Content-Type: application/x-www-form-urlencoded'", $snippet);
        $this->assertStringContainsString("--data 'grant_type=x&client_secret=<redacted>'", $snippet);
        $this->assertStringContainsString('client_secret=s3cr3t', $case->curlSnippet(redactSensitiveHeaders: false));
    }

    #[Test]
    public function rejects_a_scalar_form_body(): void
    {
        $case = new ExploredCase(['a', 'b'], [], [], [], HttpMethod::POST, '/things', contentType: 'application/x-www-form-urlencoded');

        $this->expectException(LogicException::class);
        $case->bodyAsFormFields();
    }

    #[Test]
    public function rejects_an_unserializable_media_type(): void
    {
        $case = new ExploredCase(['a' => 1], [], [], [], HttpMethod::POST, '/things', contentType: 'multipart/form-data');

        $this->expectException(LogicException::class);
        $case->bodyAsString();
    }

    private function caseWithBody(mixed $body): ExploredCase
    {
        return new ExploredCase(
            body: $body,
            query: [],
            headers: [],
            pathParams: [],
            method: HttpMethod::POST,
            matchedPath: '/v1/pets',
        );
    }
}
