<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Validation\Request\CookieParameterValidator;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

use function array_map;

class CookieParameterValidatorTest extends TestCase
{
    /**
     * Each case lists the cookie parameters, the cookies sent, and the
     * `[name, keyword]` pairs of the expected errors.
     *
     * @return iterable<string, array{list<array<string, mixed>>, array<string, mixed>, list<array{string, null|string}>}>
     */
    public static function provideValidates_cookie_parametersCases(): iterable
    {
        $int = ['name' => 'page', 'in' => 'cookie', 'schema' => ['type' => 'integer']];
        $tags = ['name' => 'tags', 'in' => 'cookie', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['enum' => ['a', 'b']]]];
        $color = ['type' => 'object', 'properties' => ['R' => ['type' => 'integer'], 'G' => ['type' => 'integer']], 'additionalProperties' => false];

        yield 'missing required cookie' => [[['required' => true] + $int], [], [['page', 'required']]];
        yield 'missing optional cookie' => [[$int], [], []];
        yield 'primitive coerced from the cookie string' => [[$int], ['page' => '42'], []];
        yield 'primitive violating its schema' => [[$int], ['page' => 'abc'], [['page', 'type']]];
        yield 'required parameter without a schema' => [[['name' => 'page', 'in' => 'cookie', 'required' => true]], ['page' => '1'], [['page', null]]];
        yield 'other locations are not read' => [[['in' => 'header'] + $int], ['page' => 'abc'], []];

        yield 'form explode false array' => [[$tags], ['tags' => 'a,b'], []];
        yield 'form explode false array violating items' => [[$tags], ['tags' => 'a,c'], [['tags', 'enum']]];
        // OAS 3.2 Style Examples: `cookie` + explode false object `color=R,100,G,200`.
        yield 'cookie style explode false object' => [[['name' => 'color', 'in' => 'cookie', 'style' => 'cookie', 'explode' => false, 'schema' => $color]], ['color' => 'R,100,G,200'], []];
        yield 'cookie style explode false object violating a property' => [[['name' => 'color', 'in' => 'cookie', 'style' => 'cookie', 'explode' => false, 'schema' => $color]], ['color' => 'R,x,G,200'], [['color', 'type']]];
        // `cookie` + explode true (the default) object: `R=100; G=200`. The
        // declared `page` cookie belongs to its own parameter.
        yield 'cookie style exploded object' => [[['name' => 'color', 'in' => 'cookie', 'style' => 'cookie', 'required' => true, 'schema' => $color], $int], ['R' => '100', 'G' => '200', 'page' => '1'], []];
        yield 'exploded object violating a property' => [[['name' => 'color', 'in' => 'cookie', 'required' => true, 'schema' => $color]], ['R' => 'x'], [['color', 'type']]];
        yield 'exploded object with no property sent' => [[['name' => 'color', 'in' => 'cookie', 'required' => true, 'schema' => $color]], ['unrelated' => '1'], [['color', 'required']]];
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @param array<string, mixed> $cookies
     * @param list<array{string, null|string}> $expected
     */
    #[Test]
    #[DataProvider('provideValidates_cookie_parametersCases')]
    public function validates_cookie_parameters(array $parameters, array $cookies, array $expected): void
    {
        $errors = (new CookieParameterValidator(new SchemaValidatorRunner(20)))->validate('GET', '/pets', $parameters, $cookies, OpenApiVersion::V3_1);

        $this->assertSame($expected, array_map(static fn(NamedError $error): array => [(string) $error->name, $error->keyword], $errors));
    }
}
