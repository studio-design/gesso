<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Arazzo;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Arazzo\ArazzoSimpleCondition;
use Studio\Gesso\Internal\ObservedResponse;
use Studio\Gesso\Internal\RuntimeExpressionContext;

use function array_map;

final class ArazzoSimpleConditionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideConditions_evaluate_with_arazzo_semanticsCases(): iterable
    {
        yield 'int equality' => ['$statusCode == 201', true];
        yield 'int inequality' => ['$statusCode != 201', false];
        yield 'less than' => ['$statusCode < 300', true];
        yield 'less or equal' => ['$statusCode <= 201', true];
        yield 'greater than' => ['$statusCode > 201', false];
        yield 'greater or equal' => ['$statusCode >= 201', true];
        yield 'case-insensitive strings' => ["\$response.body#/name == 'REX'", true];
        yield 'escaped quote' => ["\$response.body#/quote == 'it''s'", true];
        yield 'numeric string coerces' => ['$response.body#/count == 3', true];
        yield 'numeric string ordering' => ['$response.body#/count > 2.5', true];
        yield 'float literal' => ['$response.body#/price == 1.5', true];
        yield 'null equals null' => ['$response.body#/missingValue == null', true];
        yield 'null is not false' => ['$response.body#/missingValue == false', false];
        yield 'zero is not null' => ['$response.body#/zero == null', false];
        yield 'bool equality' => ['$response.body#/ok == true', true];
        yield 'bool is not a string' => ["\$response.body#/ok == 'true'", false];
        yield 'bare true' => ['$response.body#/ok', true];
        yield 'bare null fails' => ['$response.body#/missingValue', false];
        yield 'and' => ["\$statusCode == 201 && \$response.body#/name == 'rex'", true];
        yield 'and short-circuits on false' => ['$statusCode == 500 && $response.body#/nope == 1', false];
        yield 'header' => ["\$response.header.content-type == 'application/json'", true];
        yield 'literal only' => ['1 == 1', true];
        yield 'large integers stay exact' => ['9007199254740992 == 9007199254740993', false];
        yield 'large numeric string stays exact' => ['$response.body#/bigId == 9007199254740993', false];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEvaluation_errors_are_thrown_not_swallowedCases(): iterable
    {
        yield 'non-numeric ordering' => ['$response.body#/name < 3'];
        yield 'bare non-boolean' => ['$response.body#/name'];
        yield 'missing member' => ['$response.body#/nope == 1'];
        yield 'integer string beyond int range' => ['$response.body#/hugeId == 1'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnsupported_constructs_name_themselvesCases(): iterable
    {
        yield 'or' => ['$statusCode == 200 || $statusCode == 201', '||'];
        yield 'not' => ['!$response.body#/ok', "'!'"];
        yield 'grouping' => ['($statusCode == 200)', '()'];
        yield 'indexing' => ["\$response.body#/tags[0] == 'a'", '[]'];
        yield 'property access' => ["\$response.body.name == 'Rex'", "'.' property access"];
        yield 'double quotes' => ['$response.body#/name == "Rex"', 'double-quoted'];
        yield 'bare word' => ['$response.body#/name == Rex', 'bare word'];
        yield 'unterminated string' => ["\$response.body#/name == 'Rex", 'Unterminated'];
        yield 'single equals' => ['$statusCode = 200', "Unexpected '='"];
        yield 'missing operand' => ['$statusCode ==', 'missing an operand'];
        yield 'empty' => ['  ', 'empty'];
        yield 'integer literal beyond int range' => ['9223372036854775808 == 9223372036854775809', 'outside the 64-bit range'];
    }

    #[Test]
    #[DataProvider('provideConditions_evaluate_with_arazzo_semanticsCases')]
    public function conditions_evaluate_with_arazzo_semantics(string $condition, bool $expected): void
    {
        $this->assertSame($expected, ArazzoSimpleCondition::parse($condition)->evaluate($this->context()));
    }

    #[Test]
    #[DataProvider('provideEvaluation_errors_are_thrown_not_swallowedCases')]
    public function evaluation_errors_are_thrown_not_swallowed(string $condition): void
    {
        $this->expectException(RuntimeException::class);

        ArazzoSimpleCondition::parse($condition)->evaluate($this->context());
    }

    #[Test]
    #[DataProvider('provideUnsupported_constructs_name_themselvesCases')]
    public function unsupported_constructs_name_themselves(string $condition, string $construct): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($construct);

        ArazzoSimpleCondition::parse($condition);
    }

    #[Test]
    public function expressions_are_exposed_for_preflight(): void
    {
        $condition = ArazzoSimpleCondition::parse("\$statusCode == 201 && \$steps.a.outputs.b != 'x'");

        $this->assertSame(['$statusCode', '$steps.a.outputs.b'], array_map(static fn($e): string => $e->raw, $condition->expressions()));
    }

    private function context(): RuntimeExpressionContext
    {
        return new RuntimeExpressionContext(response: ObservedResponse::from([
            'status' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Rex","quote":"it\'s","count":"3","price":1.5,"missingValue":null,"zero":0,"bigId":"9007199254740992","hugeId":"9223372036854775808","ok":true,"tags":["a"]}',
        ]));
    }
}
