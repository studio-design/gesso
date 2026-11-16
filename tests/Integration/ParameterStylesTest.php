<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiPsr7Validator;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function implode;

/**
 * Every row of the OAS 3.2 Style Examples table (§4.12.6) that applies to
 * path, header, query, and cookie parameters, sent on the wire through the
 * PSR-7 client adapter. The exploded cookie array (`color=blue; color=black`)
 * is the exception: a name → value cookie map cannot carry a repeated name. The fixture's `color` is the table's: the string `blue`,
 * the array `[blue, black, brown]`, the object `{R: 100, G: 200, B: 150}`
 * (integers, so a valid object also proves per-property coercion).
 */
final class ParameterStylesTest extends TestCase
{
    private OpenApiPsr7Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiPsr7Validator('parameter-styles');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, bool}> operation, serialized value, valid
     */
    public static function providePath_parameter_stylesCases(): iterable
    {
        yield from self::cases('matrix/flat', [
            'string' => [';color=blue', 'blue'],
            'array' => [';color=blue,black,brown', ';color=blue,black,pink'],
            'object' => [';color=R,100,G,200,B,150', ';color=R,100,G,x,B,150'],
        ]);
        yield from self::cases('matrix/explode', [
            'string' => [';color=blue', ';colour=blue'],
            'array' => [';color=blue;color=black;color=brown', ';color=blue;colour=black;color=brown'],
            'object' => [';R=100;G=200;B=150', ';R=100;G=200;B=x'],
        ]);
        yield from self::cases('label/flat', [
            'string' => ['.blue', 'blue'],
            'array' => ['.blue,black,brown', 'blue,black,brown'],
            'object' => ['.R,100,G,200,B,150', '.R,100,G,200,B'],
        ]);
        yield from self::cases('label/explode', [
            'string' => ['.blue', '.red'],
            'array' => ['.blue.black.brown', '.blue,black,brown'],
            'object' => ['.R=100.G=200.B=150', '.R=100.G=200.B=x'],
        ]);
        yield from self::cases('simple/flat', [
            'string' => ['blue', '.blue'],
            'array' => ['blue,black,brown', 'blue,black,pink'],
            'object' => ['R,100,G,200,B,150', 'R=100,G=200,B=150'],
        ]);
        yield from self::cases('simple/explode', [
            'string' => ['blue', 'red'],
            'array' => ['blue,black,brown', 'blue,black'],
            'object' => ['R=100,G=200,B=150', 'R=100,G=200,B'],
        ]);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideHeader_parameter_stylesCases(): iterable
    {
        yield from self::cases('simple/flat', [
            'string' => ['blue', 'red'],
            'array' => ['blue,black,brown', 'blue,black,pink'],
            'object' => ['R,100,G,200,B,150', 'R,100,G,200,B,1.5'],
        ]);
        yield from self::cases('simple/explode', [
            'string' => ['blue', 'red'],
            'array' => ['blue,black,brown', 'blue,black'],
            'object' => ['R=100,G=200,B=150', 'R=100,G=200,X=150'],
        ]);
    }

    /**
     * @return iterable<string, array{string, string, bool}> operation, raw query string, valid
     */
    public static function provideQuery_parameter_stylesCases(): iterable
    {
        yield from self::cases('form/flat', [
            'string' => ['color=blue', 'color=red'],
            'array' => ['color=blue,black,brown', 'color=blue,black,pink'],
            'object' => ['color=R,100,G,200,B,150', 'color=R,100,G,200,B,x'],
        ]);
        yield from self::cases('form/explode', [
            'string' => ['color=blue', 'color=red'],
            'array' => ['color=blue&color=black&color=brown', 'color=blue&color=black&color=pink'],
            'object' => ['R=100&G=200&B=150', 'R=100&G=200&B=x'],
        ]);
        yield from self::cases('spaceDelimited/flat', [
            'array' => ['color=blue%20black%20brown', 'color=blue%20black%20pink'],
            'object' => ['color=R%20100%20G%20200%20B%20150', 'color=R%20100%20G%20200%20B'],
        ]);
        yield from self::cases('pipeDelimited/flat', [
            'array' => ['color=blue%7Cblack%7Cbrown', 'color=blue%7Cblack%7Cpink'],
            'object' => ['color=R%7C100%7CG%7C200%7CB%7C150', 'color=R%7C100%7CG%7C200%7CB%7Cx'],
        ]);
        yield from self::cases('deepObject/explode', [
            'object' => ['color%5BR%5D=100&color%5BG%5D=200&color%5BB%5D=150', 'color%5BR%5D=100&color%5BG%5D=200&color%5BB%5D=x'],
        ]);
    }

    /**
     * @return iterable<string, array{string, string, bool}> operation, Cookie header, valid
     */
    public static function provideCookie_parameter_stylesCases(): iterable
    {
        foreach (['form', 'cookie'] as $style) {
            yield from self::cases("{$style}/flat", [
                'string' => ['color=blue', 'color=red'],
                'array' => ['color=blue,black,brown', 'color=blue,black,pink'],
                'object' => ['color=R,100,G,200,B,150', 'color=R,100,G,200,B,x'],
            ]);
            yield from self::cases("{$style}/explode", [
                'string' => ['color=blue', 'color=red'],
                'object' => ['R=100; G=200; B=150', 'R=100; G=200; B=x'],
            ]);
        }
    }

    #[DataProvider('providePath_parameter_stylesCases')]
    #[Test]
    public function path_parameter_styles(string $operation, string $value, bool $valid): void
    {
        $result = $this->validator->validateRequest(new Request('GET', "/path/{$operation}/{$value}"));

        $this->assertSame($valid, $result->isValid(), $result->errorMessage());
    }

    #[DataProvider('provideHeader_parameter_stylesCases')]
    #[Test]
    public function header_parameter_styles(string $operation, string $value, bool $valid): void
    {
        $result = $this->validator->validateRequest(new Request('GET', "/header/{$operation}", ['color' => $value]));

        $this->assertSame($valid, $result->isValid(), $result->errorMessage());
    }

    #[DataProvider('provideQuery_parameter_stylesCases')]
    #[Test]
    public function query_parameter_styles(string $operation, string $query, bool $valid): void
    {
        $result = $this->validator->validateRequest(new Request('GET', "/query/{$operation}?{$query}"));

        $this->assertSame($valid, $result->isValid(), $result->errorMessage());
    }

    #[DataProvider('provideCookie_parameter_stylesCases')]
    #[Test]
    public function cookie_parameter_styles(string $operation, string $cookie, bool $valid): void
    {
        $validator = new OpenApiPsr7Validator('cookie-parameter-styles');
        $result = $validator->validateRequest(new Request('GET', "/cookie/{$operation}", ['Cookie' => $cookie]));

        $this->assertSame($valid, $result->isValid(), $result->errorMessage());
    }

    #[Test]
    public function deep_object_arrives_nested_from_a_server_request(): void
    {
        // PHP's query parser nests `color[R]=100`; the properties are still
        // strings and must be coerced one by one.
        $request = (new ServerRequest('GET', '/query/deepObject/explode/object'))
            ->withQueryParams(['color' => ['R' => '100', 'G' => '200', 'B' => '150']]);
        $this->assertTrue($this->validator->validateRequest($request)->isValid());

        $request = $request->withQueryParams(['color' => ['R' => '100', 'G' => '200', 'B' => 'x']]);
        $result = $this->validator->validateRequest($request);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('query.color/B', implode("\n", $result->errors()));
    }

    #[Test]
    public function a_comma_inside_a_path_value_stays_data(): void
    {
        // Pieces are split before percent-decoding: `%2C` is a value's own
        // comma, so `blue%2Cblack,brown` is two elements, not three.
        $result = $this->validator->validateRequest(new Request('GET', '/path/simple/flat/array/blue%2Cblack,brown,blue'));

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('path.color/0', implode("\n", $result->errors()));
    }

    /**
     * @param array<string, array{string, string}> $values kind → [valid, malformed]
     *
     * @return iterable<string, array{string, string, bool}>
     */
    private static function cases(string $styleAndExplode, array $values): iterable
    {
        foreach ($values as $kind => [$valid, $malformed]) {
            yield "{$styleAndExplode}/{$kind} valid" => ["{$styleAndExplode}/{$kind}", $valid, true];
            yield "{$styleAndExplode}/{$kind} malformed" => ["{$styleAndExplode}/{$kind}", $malformed, false];
        }
    }
}
