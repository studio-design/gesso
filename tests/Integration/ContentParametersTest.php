<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiPsr7Validator;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function implode;
use function rawurlencode;

/**
 * Parameters declared with `content` instead of `schema`, sent on the wire
 * through the PSR-7 client adapter. The JSON value is percent-encoded in the
 * path, query, and cookie (OAS 3.2 §4.12.8 shows
 * `coordinates=%7B%22lat%22%3A10%2C%22long%22%3A60%7D`) and sent as is in a
 * header.
 */
final class ContentParametersTest extends TestCase
{
    private const VALID = '{"lat":10,"long":60}';
    private OpenApiPsr7Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiPsr7Validator('content-parameters');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, null|string, string, string}> path, query, header, cookie, expected error label
     */
    public static function provideA_json_parameter_its_schema_rejects_failsCases(): iterable
    {
        $invalid = '{"lat":10}';
        $notJson = '{lat:10}';

        yield 'path schema' => [$invalid, '', null, self::VALID, '[path.id'];
        yield 'path not json' => [$notJson, '', null, self::VALID, '[path.id] value is not valid application/json'];
        yield 'query schema' => [self::VALID, 'point=' . rawurlencode($invalid), null, self::VALID, '[query.point'];
        yield 'query not json' => [self::VALID, 'point=' . rawurlencode($notJson), null, self::VALID, '[query.point] value is not valid application/json'];
        yield 'header schema' => [self::VALID, '', $invalid, self::VALID, '[header.X-Point'];
        yield 'header not json' => [self::VALID, '', $notJson, self::VALID, '[header.X-Point] value is not valid application/json'];
        yield 'cookie schema' => [self::VALID, '', null, $invalid, '[cookie.point'];
        yield 'cookie not json' => [self::VALID, '', null, $notJson, '[cookie.point] value is not valid application/vnd.point+json'];
        yield 'query wrong type' => [self::VALID, 'point=' . rawurlencode('{"lat":"x","long":60}'), null, self::VALID, '[query.point/lat]'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideA_malformed_declaration_fails_every_requestCases(): iterable
    {
        yield 'schema and content' => ['both', "declares both 'schema' and 'content'"];
        yield 'neither' => ['neither', "declares neither 'schema' nor 'content'"];
        yield 'two media types' => ['two-media-types', "'content' must contain exactly one media type"];
        yield 'empty content' => ['empty-content', "'content' must contain exactly one media type"];
        yield 'media type not an object' => ['media-type-not-an-object', "'content' entry must be a Media Type Object"];
    }

    #[Test]
    public function json_parameters_in_every_location_pass(): void
    {
        $result = $this->validator->validateRequest(self::request(query: 'point=' . rawurlencode(self::VALID), header: self::VALID));

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertFalse($result->isSkipped());
    }

    #[Test]
    #[DataProvider('provideA_json_parameter_its_schema_rejects_failsCases')]
    public function a_json_parameter_its_schema_rejects_fails(string $path, string $query, ?string $header, string $cookie, string $expected): void
    {
        $result = $this->validator->validateRequest(self::request($path, $query, $header, $cookie));

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString($expected, implode("\n", $result->errors()));
    }

    #[Test]
    public function a_missing_required_content_parameter_fails(): void
    {
        $result = $this->validator->validateRequest(new Request('GET', '/items/' . rawurlencode(self::VALID)));

        $this->assertFalse($result->isValid());
        $this->assertSame(['[cookie.point] required cookie is missing.'], $result->errors());
    }

    #[Test]
    public function a_non_json_content_parameter_is_skipped_only_when_sent(): void
    {
        $result = $this->validator->validateRequest(self::request(query: 'note=longer-than-three'));

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString("parameter 'query.note'", (string) $result->skipReason());
        $this->assertStringContainsString("'text/plain'", (string) $result->skipReason());
    }

    #[Test]
    public function a_json_media_type_without_a_schema_accepts_any_value(): void
    {
        $result = $this->validator->validateRequest(self::request(query: 'raw=not-json'));

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertFalse($result->isSkipped());
    }

    #[Test]
    #[DataProvider('provideA_malformed_declaration_fails_every_requestCases')]
    public function a_malformed_declaration_fails_every_request(string $path, string $expected): void
    {
        $result = $this->validator->validateRequest(new Request('GET', "/malformed/{$path}"));

        $this->assertFalse($result->isValid());
        $this->assertSame(["Malformed parameter 'query.point' for GET /malformed/{$path}: {$expected}."], $result->errors());
    }

    private static function request(string $path = self::VALID, string $query = '', ?string $header = null, string $cookie = self::VALID): Request
    {
        $headers = ['Cookie' => 'point=' . rawurlencode($cookie)];
        if ($header !== null) {
            $headers['X-Point'] = $header;
        }

        return new Request('GET', '/items/' . rawurlencode($path) . ($query === '' ? '' : "?{$query}"), $headers);
    }
}
