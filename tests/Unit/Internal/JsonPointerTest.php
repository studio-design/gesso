<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\JsonPointer;
use Studio\Gesso\Internal\RuntimeExpressionException;
use Studio\Gesso\Internal\RuntimeExpressionFailure;

use function json_decode;

final class JsonPointerTest extends TestCase
{
    #[Test]
    public function list_indices_follow_rfc6901(): void
    {
        $root = ['a', 'b'];

        $this->assertSame([true, 'b'], JsonPointer::walk(['1'], $root));
        $this->assertSame([true, 'a'], JsonPointer::walk(['0'], $root));
        $this->assertSame([false, null], JsonPointer::walk(['01'], $root));
        $this->assertSame([false, null], JsonPointer::walk(['2'], $root));
        $this->assertSame([false, null], JsonPointer::walk(['-'], $root));
    }

    #[Test]
    public function objects_and_assoc_arrays_walk_alike(): void
    {
        $decoded = json_decode('{"a":{"b":[{"c":null}]}}', false);
        $assoc = json_decode('{"a":{"b":[{"c":null}]}}', true);

        $this->assertSame([true, null], JsonPointer::walk(['a', 'b', '0', 'c'], $decoded));
        $this->assertSame([true, null], JsonPointer::walk(['a', 'b', '0', 'c'], $assoc));
        $this->assertSame([false, null], JsonPointer::walk(['a', 'x'], $decoded));
        $this->assertSame([false, null], JsonPointer::walk(['a', 'b', '0', 'c', 'd'], $decoded));
        $this->assertSame([true, 'root'], JsonPointer::walk([], 'root'));
    }

    #[Test]
    public function segments_decode_escapes_in_order(): void
    {
        $this->assertSame([], JsonPointer::segments(''));
        $this->assertSame(['a/b', 'c~d', '~1', ''], JsonPointer::segments('/a~1b/c~0d/~01/'));
    }

    #[Test]
    public function segments_reject_bad_escapes_and_relative_pointers(): void
    {
        foreach (['a', '/a~2', '/a~'] as $pointer) {
            try {
                JsonPointer::segments($pointer);
                $this->fail("Expected '{$pointer}' to be malformed.");
            } catch (RuntimeExpressionException $e) {
                $this->assertSame(RuntimeExpressionFailure::Malformed, $e->reason);
            }
        }
    }
}
