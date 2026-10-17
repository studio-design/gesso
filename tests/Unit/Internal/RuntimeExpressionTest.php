<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\RuntimeExpression;
use Studio\Gesso\Internal\RuntimeExpressionException;
use Studio\Gesso\Internal\RuntimeExpressionFailure;
use Studio\Gesso\Internal\RuntimeExpressionFamily;

final class RuntimeExpressionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, RuntimeExpressionFamily, ?string, ?string, ?string, ?string}>
     */
    public static function provideParse_accepts_every_familyCases(): iterable
    {
        yield 'url' => ['$url', RuntimeExpressionFamily::Url, null, null, null, null];
        yield 'method' => ['$method', RuntimeExpressionFamily::Method, null, null, null, null];
        yield 'status code' => ['$statusCode', RuntimeExpressionFamily::StatusCode, null, null, null, null];
        yield 'self' => ['$self', RuntimeExpressionFamily::Self, null, null, null, null];
        yield 'request header' => ['$request.header.X-Request-Id', RuntimeExpressionFamily::Request, 'header', 'X-Request-Id', null, null];
        yield 'request query' => ['$request.query.page size', RuntimeExpressionFamily::Request, 'query', 'page size', null, null];
        yield 'request path' => ['$request.path.PetId', RuntimeExpressionFamily::Request, 'path', 'PetId', null, null];
        yield 'request body' => ['$request.body', RuntimeExpressionFamily::Request, 'body', null, null, null];
        yield 'response body pointer' => ['$response.body#/data/0/id', RuntimeExpressionFamily::Response, 'body', null, null, '/data/0/id'];
        yield 'response body empty pointer' => ['$response.body#', RuntimeExpressionFamily::Response, 'body', null, null, ''];
        yield 'response query (grammar allows it)' => ['$response.query.x', RuntimeExpressionFamily::Response, 'query', 'x', null, null];
        yield 'message payload' => ['$message.payload#/a', RuntimeExpressionFamily::Message, 'payload', null, null, '/a'];
        yield 'inputs with dots' => ['$inputs.user.name#/first', RuntimeExpressionFamily::Inputs, null, 'user.name', null, '/first'];
        yield 'outputs' => ['$outputs.token', RuntimeExpressionFamily::Outputs, null, 'token', null, null];
        yield 'steps' => ['$steps.create-pet.outputs.pet.id#/x', RuntimeExpressionFamily::Steps, null, 'create-pet', 'pet.id', '/x'];
        yield 'workflows' => ['$workflows.buy_pet.outputs.name', RuntimeExpressionFamily::Workflows, 'outputs', 'buy_pet', 'name', null];
        yield 'source descriptions' => ['$sourceDescriptions.petstore.getPet', RuntimeExpressionFamily::SourceDescriptions, null, 'petstore', 'getPet', null];
        yield 'components' => ['$components.failureActions.abort', RuntimeExpressionFamily::Components, null, 'failureActions', 'abort', null];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideParse_rejects_malformed_expressionsCases(): iterable
    {
        foreach ([
            '$', '$foo', '$request', '$request.', '$request.cookie.x', '$response.header.',
            '$request.query.', '$request.body#a', '$request.body#/a~2', '$steps.x', '$steps.x.inputs.y',
            '$components.inputs.x', '$statusCode1', '$request.payload', '$sourceDescriptions.a',
            "\$request.header.x-id\n", "\$inputs.name\n", "\$url\n",
        ] as $raw) {
            yield $raw => [$raw];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideParse_embedded_rejects_stray_bracesCases(): iterable
    {
        foreach (['{', '}', '{$url', '{}', 'a}{$url}', '{{$url}}', '{$nope}'] as $template) {
            yield $template => [$template];
        }
    }

    #[Test]
    #[DataProvider('provideParse_accepts_every_familyCases')]
    public function parse_accepts_every_family(
        string $raw,
        RuntimeExpressionFamily $family,
        ?string $source,
        ?string $name,
        ?string $member,
        ?string $pointer,
    ): void {
        $expression = RuntimeExpression::parse($raw);

        $this->assertSame($family, $expression->family);
        $this->assertSame($source, $expression->source);
        $this->assertSame($name, $expression->name);
        $this->assertSame($member, $expression->member);
        $this->assertSame($pointer, $expression->pointer);
        $this->assertSame($raw, $expression->raw);
    }

    #[Test]
    public function pointer_segments_are_rfc6901_decoded_without_percent_decoding(): void
    {
        $expression = RuntimeExpression::parse('$response.body#/a~1b/c~0d/e%20f');

        $this->assertSame(['a/b', 'c~d', 'e%20f'], $expression->pointerSegments());
    }

    #[Test]
    #[DataProvider('provideParse_rejects_malformed_expressionsCases')]
    public function parse_rejects_malformed_expressions(string $raw): void
    {
        try {
            RuntimeExpression::parse($raw);
            $this->fail('Expected a malformed expression.');
        } catch (RuntimeExpressionException $e) {
            $this->assertSame(RuntimeExpressionFailure::Malformed, $e->reason);
            $this->assertSame($raw, $e->expression);
        }
    }

    #[Test]
    public function parse_embedded_splits_literals_and_expressions(): void
    {
        $chunks = RuntimeExpression::parseEmbedded('a{$url}b{$statusCode}');

        $this->assertCount(4, $chunks);
        $this->assertSame('a', $chunks[0]);
        $this->assertInstanceOf(RuntimeExpression::class, $chunks[1]);
        $this->assertSame(RuntimeExpressionFamily::Url, $chunks[1]->family);
        $this->assertSame('b', $chunks[2]);
        $this->assertInstanceOf(RuntimeExpression::class, $chunks[3]);
        $this->assertSame(RuntimeExpressionFamily::StatusCode, $chunks[3]->family);
        $this->assertSame(['plain'], RuntimeExpression::parseEmbedded('plain'));
    }

    #[Test]
    #[DataProvider('provideParse_embedded_rejects_stray_bracesCases')]
    public function parse_embedded_rejects_stray_braces(string $template): void
    {
        $this->expectException(RuntimeExpressionException::class);

        RuntimeExpression::parseEmbedded($template);
    }

    #[Test]
    public function is_expression_only_matches_dollar_strings(): void
    {
        $this->assertTrue(RuntimeExpression::isExpression('$url'));
        $this->assertFalse(RuntimeExpression::isExpression('url'));
        $this->assertFalse(RuntimeExpression::isExpression(42));
    }
}
