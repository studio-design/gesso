<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;

class MalformedSpecNodeTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideIs_malformed_rejects_scalars_null_and_listsCases(): iterable
    {
        yield 'string scalar' => ['this should have been an object'];
        yield 'int scalar' => [42];
        yield 'float scalar' => [3.14];
        yield 'bool scalar' => [true];
        yield 'null' => [null];
        yield 'non-empty list' => [['a', 'b']];
        yield 'single-element list' => [['only']];
        yield 'nested list' => [[['a']]];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideIs_malformed_accepts_objects_and_the_empty_nodeCases(): iterable
    {
        yield 'map with string keys' => [['get' => [], 'post' => []]];
        yield 'single-key map' => [['200' => ['description' => 'OK']]];
        // `{}` and `[]` both decode to `[]` in PHP: an empty node is
        // ambiguous and treated as an (empty) object, never malformed.
        yield 'empty array' => [[]];
    }

    #[Test]
    #[DataProvider('provideIs_malformed_rejects_scalars_null_and_listsCases')]
    public function is_malformed_rejects_scalars_null_and_lists(mixed $node): void
    {
        $this->assertTrue(MalformedSpecNode::isMalformed($node));
    }

    #[Test]
    #[DataProvider('provideIs_malformed_accepts_objects_and_the_empty_nodeCases')]
    public function is_malformed_accepts_objects_and_the_empty_node(mixed $node): void
    {
        $this->assertFalse(MalformedSpecNode::isMalformed($node));
    }

    #[Test]
    public function describe_reports_scalar_and_null_types_via_get_debug_type(): void
    {
        $this->assertSame('string', MalformedSpecNode::describe('x'));
        $this->assertSame('int', MalformedSpecNode::describe(42));
        $this->assertSame('float', MalformedSpecNode::describe(3.14));
        $this->assertSame('bool', MalformedSpecNode::describe(true));
        $this->assertSame('null', MalformedSpecNode::describe(null));
    }

    #[Test]
    public function describe_reports_a_non_empty_list_as_list(): void
    {
        $this->assertSame('list', MalformedSpecNode::describe(['a', 'b']));
    }
}
