<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Symfony;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Symfony\HttpFoundationBody;
use Studio\Gesso\Validation\Request\RequestBodyValidator;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class HttpFoundationBodyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function provideJson_types_are_preserved_for_every_json_content_typeCases(): iterable
    {
        yield 'JSON' => ['application/json'];
        yield 'suffix and parameters' => ['Application/Problem+Json; charset=utf-8'];
        yield 'absent header' => [''];
        yield 'parameter-only header' => ['; charset=utf-8'];
        yield 'whitespace header' => ['  '];
    }

    #[Test]
    #[DataProvider('provideJson_types_are_preserved_for_every_json_content_typeCases')]
    public function json_types_are_preserved_for_every_json_content_type(string $contentType): void
    {
        $body = HttpFoundationBody::json('{"value":{}}', $contentType);
        $this->assertTrue($body->preservesJsonTypes);
        $this->assertInstanceOf(stdClass::class, $body->value);
        $this->assertInstanceOf(stdClass::class, $body->value->value);
        $this->assertSame([], HttpFoundationBody::json('[]', $contentType)->value);
        $this->assertTrue(HttpFoundationBody::json('null', $contentType)->present);
        $this->assertFalse(HttpFoundationBody::json('', $contentType)->present);
    }

    #[Test]
    public function invalid_json_escapes_as_json_exception_for_the_adapter_to_handle(): void
    {
        $this->expectException(JsonException::class);
        HttpFoundationBody::request(Request::create('/', 'POST', content: '{'), 'application/json');
    }

    #[Test]
    public function parameter_only_request_content_type_keeps_the_laravel_json_fallback(): void
    {
        $request = Request::create('/', 'POST', content: '{}');
        foreach (['; charset=utf-8', '  ; charset=utf-8'] as $contentType) {
            $body = HttpFoundationBody::request($request, $contentType);
            $this->assertTrue($body->present);
            $this->assertInstanceOf(stdClass::class, $body->value);
            $this->assertTrue($body->preservesJsonTypes);
            $result = (new RequestBodyValidator(new SchemaValidatorRunner(20)))->validate(
                'test',
                'POST',
                '/',
                ['requestBody' => ['required' => true, 'content' => ['*/*' => ['schema' => ['type' => 'object']]]]],
                $body,
                $contentType,
                OpenApiVersion::V3_1,
            );
            $this->assertSame([], $result->errors);
            $this->assertNotNull($result->skipReason, 'The parameter-only header must retain its pre-PR skipped outcome.');
        }
        $this->assertTrue(HttpFoundationBody::json('{}', '; charset=utf-8')->present);
    }

    #[Test]
    public function parameter_only_header_uses_the_same_parse_policy_on_both_sides(): void
    {
        foreach (['request', 'response'] as $side) {
            try {
                if ($side === 'request') {
                    HttpFoundationBody::request(Request::create('/', 'POST', content: '<a/>'), '; charset=utf-8');
                } else {
                    HttpFoundationBody::json('<a/>', '; charset=utf-8');
                }
                $this->fail($side . ' must report a JSON parse failure.');
            } catch (JsonException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function opaque_request_presence_includes_parameters_and_uploaded_files(): void
    {
        $requests = [
            Request::create('/', 'POST', ['value' => 'parsed']),
            Request::create('/', 'POST', files: ['file' => new UploadedFile(__FILE__, 'body.xml', 'application/xml', test: true)]),
        ];
        foreach ($requests as $request) {
            $body = HttpFoundationBody::request($request, 'application/xml');
            $this->assertTrue($body->present);
            $this->assertNull($body->value);
            $this->assertFalse($body->preservesJsonTypes);
        }
        $this->assertFalse(HttpFoundationBody::request(Request::create('/', 'POST'), 'application/xml')->present);
    }

    #[Test]
    public function opaque_response_extraction_keeps_its_existing_negotiation_policy(): void
    {
        $this->assertFalse(HttpFoundationBody::json('<a/>', 'application/xml')->present);
    }

    #[Test]
    public function json_passes_a_raw_urlencoded_response_through(): void
    {
        // Issue #488: the response validator parses the raw form body.
        $body = HttpFoundationBody::json('token=abc', 'application/x-www-form-urlencoded; charset=utf-8');
        $this->assertTrue($body->present);
        $this->assertSame('token=abc', $body->value);
        // An empty form is a form with no fields; the schema judges it.
        $this->assertSame('', HttpFoundationBody::json('', 'application/x-www-form-urlencoded')->value);
        $this->assertFalse(HttpFoundationBody::json('plain', 'text/plain')->present);
    }
}
