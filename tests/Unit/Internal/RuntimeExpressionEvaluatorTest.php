<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\Internal\ObservedResponse;
use Studio\Gesso\Internal\RuntimeExpressionContext;
use Studio\Gesso\Internal\RuntimeExpressionEvaluator;
use Studio\Gesso\Internal\RuntimeExpressionException;
use Studio\Gesso\Internal\RuntimeExpressionFailure;

final class RuntimeExpressionEvaluatorTest extends TestCase
{
    /**
     * @return iterable<string, array{RuntimeExpressionFailure, string}>
     */
    public static function provideUnsupported_or_missing_values_fail_loudlyCases(): iterable
    {
        yield 'missing body member' => [RuntimeExpressionFailure::NotFound, '$response.body#/missing'];
        yield 'list index with leading zero' => [RuntimeExpressionFailure::NotFound, '$response.body#/tags/01'];
        yield 'missing header' => [RuntimeExpressionFailure::NotFound, '$response.header.x-missing'];
        yield 'response query' => [RuntimeExpressionFailure::ContextUnavailable, '$response.query.limit'];
        yield 'inputs outside a workflow' => [RuntimeExpressionFailure::ContextUnavailable, '$inputs.name'];
        yield 'step that has not run' => [RuntimeExpressionFailure::NotFound, '$steps.a.outputs.b'];
        yield 'outputs' => [RuntimeExpressionFailure::UnsupportedFamily, '$outputs.b'];
        yield 'workflows' => [RuntimeExpressionFailure::UnsupportedFamily, '$workflows.a.outputs.b'];
        yield 'message' => [RuntimeExpressionFailure::UnsupportedFamily, '$message.payload'];
        yield 'self' => [RuntimeExpressionFailure::UnsupportedFamily, '$self'];
        yield 'components' => [RuntimeExpressionFailure::UnsupportedFamily, '$components.parameters.p'];
        yield 'source descriptions' => [RuntimeExpressionFailure::UnsupportedFamily, '$sourceDescriptions.a.url'];
        yield 'malformed' => [RuntimeExpressionFailure::Malformed, '$response.cookie.x'];
    }

    #[Test]
    public function whole_value_expressions_preserve_json_types(): void
    {
        $context = $this->context();

        $this->assertSame(201, RuntimeExpressionEvaluator::evaluate('$statusCode', $context));
        $this->assertSame(3, RuntimeExpressionEvaluator::evaluate('$response.body#/count', $context));
        $this->assertTrue(RuntimeExpressionEvaluator::evaluate('$response.body#/ok', $context));
        $this->assertSame(['a', 'b'], RuntimeExpressionEvaluator::evaluate('$response.body#/tags', $context));
        $this->assertInstanceOf(stdClass::class, RuntimeExpressionEvaluator::evaluate('$response.body#/obj', $context));
        $this->assertNull(RuntimeExpressionEvaluator::evaluate('$response.body#/n', $context));
        $this->assertSame('b', RuntimeExpressionEvaluator::evaluate('$response.body#/tags/1', $context));
        $this->assertSame('/pets?limit=2', RuntimeExpressionEvaluator::evaluate('$url', $context));
        $this->assertSame('POST', RuntimeExpressionEvaluator::evaluate('$method', $context));
        $this->assertSame('Rex', RuntimeExpressionEvaluator::evaluate('$request.body#/name', $context));
    }

    #[Test]
    public function method_keeps_custom_method_case(): void
    {
        $this->assertSame('copy', RuntimeExpressionEvaluator::evaluate('$method', new RuntimeExpressionContext(method: 'copy')));
        $this->assertSame('GET', RuntimeExpressionEvaluator::evaluate('$method', new RuntimeExpressionContext(method: 'get')));
    }

    #[Test]
    public function headers_are_case_insensitive_and_multi_values_join(): void
    {
        $context = $this->context();

        $this->assertSame('abc', RuntimeExpressionEvaluator::evaluate('$request.header.x-request-id', $context));
        $this->assertSame('abc', RuntimeExpressionEvaluator::evaluate('$request.header.X-REQUEST-ID', $context));
        $this->assertSame('a=1, b=2', RuntimeExpressionEvaluator::evaluate('$response.header.X-Multi', $context));
    }

    #[Test]
    public function parameter_names_keep_their_case(): void
    {
        $context = $this->context();

        $this->assertSame('7', RuntimeExpressionEvaluator::evaluate('$request.path.petId', $context));
        $this->assertSame(['x', 'y'], RuntimeExpressionEvaluator::evaluate('$request.query.tags', $context));
        $this->assertFailure(RuntimeExpressionFailure::NotFound, '$request.path.PetId', $context);
    }

    #[Test]
    #[DataProvider('provideUnsupported_or_missing_values_fail_loudlyCases')]
    public function unsupported_or_missing_values_fail_loudly(RuntimeExpressionFailure $reason, string $expression): void
    {
        $this->assertFailure($reason, $expression, $this->context());
    }

    #[Test]
    public function inputs_and_step_outputs_preserve_types_and_walk_pointers(): void
    {
        $context = new RuntimeExpressionContext(
            inputs: ['name' => 'Rex', 'user.profile' => ['age' => 3]],
            stepOutputs: ['create' => ['petId' => 7, 'pet' => (object) ['tags' => ['a']]]],
        );

        $this->assertSame('Rex', RuntimeExpressionEvaluator::evaluate('$inputs.name', $context));
        $this->assertSame(3, RuntimeExpressionEvaluator::evaluate('$inputs.user.profile#/age', $context));
        $this->assertSame(7, RuntimeExpressionEvaluator::evaluate('$steps.create.outputs.petId', $context));
        $this->assertSame('a', RuntimeExpressionEvaluator::evaluate('$steps.create.outputs.pet#/tags/0', $context));
        $this->assertSame('pet-7', RuntimeExpressionEvaluator::evaluateEmbedded('pet-{$steps.create.outputs.petId}', $context));
        $this->assertFailure(RuntimeExpressionFailure::NotFound, '$inputs.missing', $context);
        $this->assertFailure(RuntimeExpressionFailure::NotFound, '$steps.create.outputs.missing', $context);
        $this->assertFailure(RuntimeExpressionFailure::NotFound, '$steps.create.outputs.pet#/nope', $context);
    }

    #[Test]
    public function missing_context_is_context_unavailable(): void
    {
        $empty = new RuntimeExpressionContext();

        foreach (['$url', '$method', '$statusCode', '$response.body', '$response.header.a', '$request.body'] as $expression) {
            $this->assertFailure(RuntimeExpressionFailure::ContextUnavailable, $expression, $empty);
        }
    }

    #[Test]
    public function an_absent_body_is_not_found(): void
    {
        $context = new RuntimeExpressionContext(
            requestBody: DecodedBody::absent(),
            response: ObservedResponse::from(['status' => 204, 'headers' => [], 'body' => '']),
        );

        $this->assertFailure(RuntimeExpressionFailure::NotFound, '$request.body', $context);
        $this->assertFailure(RuntimeExpressionFailure::NotFound, '$response.body#/id', $context);
    }

    #[Test]
    public function embedded_expressions_render_scalars_as_json_literals(): void
    {
        $context = $this->context();

        $this->assertSame('ok=true n=null f=1.0 c=3 s=201', RuntimeExpressionEvaluator::evaluateEmbedded(
            'ok={$response.body#/ok} n={$response.body#/n} f={$response.body#/f} c={$response.body#/count} s={$statusCode}',
            $context,
        ));
        $this->assertSame('req-abc', RuntimeExpressionEvaluator::evaluateEmbedded('req-{$request.header.X-Request-Id}', $context));
    }

    #[Test]
    public function embedded_non_scalar_values_are_rejected(): void
    {
        $this->assertFailure(RuntimeExpressionFailure::NonScalarEmbedded, 'x{$response.body#/obj}', $this->context(), embedded: true);
        $this->assertFailure(RuntimeExpressionFailure::NonScalarEmbedded, 'x{$response.body#/tags}', $this->context(), embedded: true);
    }

    #[Test]
    public function evaluate_value_passes_constants_through(): void
    {
        $context = $this->context();

        $this->assertSame('plain', RuntimeExpressionEvaluator::evaluateValue('plain', $context));
        $this->assertSame(42, RuntimeExpressionEvaluator::evaluateValue(42, $context));
        $this->assertSame(['x'], RuntimeExpressionEvaluator::evaluateValue(['x'], $context));
        $this->assertSame(201, RuntimeExpressionEvaluator::evaluateValue('$statusCode', $context));
        $this->assertSame('id-3', RuntimeExpressionEvaluator::evaluateValue('id-{$response.body#/count}', $context));
        $this->assertSame('$3', RuntimeExpressionEvaluator::evaluateValue('${$response.body#/count}', $context));
    }

    private function context(): RuntimeExpressionContext
    {
        return new RuntimeExpressionContext(
            url: '/pets?limit=2',
            method: 'post',
            requestHeaders: ['x-request-id' => 'abc'],
            requestQuery: ['limit' => '2', 'tags' => ['x', 'y']],
            requestPath: ['petId' => '7'],
            requestBody: DecodedBody::fromJsonValue((object) ['name' => 'Rex']),
            response: ObservedResponse::from([
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json', 'X-Multi' => ['a=1', 'b=2']],
                'body' => '{"count":3,"ok":true,"tags":["a","b"],"obj":{"a":1},"n":null,"f":1.0}',
            ]),
        );
    }

    private function assertFailure(
        RuntimeExpressionFailure $reason,
        string $expression,
        RuntimeExpressionContext $context,
        bool $embedded = false,
    ): void {
        try {
            $embedded
                ? RuntimeExpressionEvaluator::evaluateEmbedded($expression, $context)
                : RuntimeExpressionEvaluator::evaluate($expression, $context);
            $this->fail("Expected '{$expression}' to fail with {$reason->name}.");
        } catch (RuntimeExpressionException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }
}
