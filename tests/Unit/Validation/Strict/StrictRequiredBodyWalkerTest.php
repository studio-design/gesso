<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredBodyWalker;

final class StrictRequiredBodyWalkerTest extends TestCase
{
    #[Test]
    public function returns_empty_for_null(): void
    {
        $this->assertSame([], StrictRequiredBodyWalker::collectPointers(null));
    }

    #[Test]
    public function returns_empty_for_scalar_string(): void
    {
        $this->assertSame([], StrictRequiredBodyWalker::collectPointers('hello'));
    }

    #[Test]
    public function returns_empty_for_scalar_int(): void
    {
        $this->assertSame([], StrictRequiredBodyWalker::collectPointers(42));
    }

    #[Test]
    public function returns_empty_for_scalar_bool(): void
    {
        $this->assertSame([], StrictRequiredBodyWalker::collectPointers(true));
    }

    #[Test]
    public function records_root_object_pointer(): void
    {
        $pointers = StrictRequiredBodyWalker::collectPointers(['id' => '1', 'name' => 'x']);

        $this->assertSame(['/' => ['id', 'name']], $pointers);
    }

    #[Test]
    public function records_empty_root_object_as_empty_key_set(): void
    {
        $pointers = StrictRequiredBodyWalker::collectPointers([]);

        $this->assertSame(['/' => []], $pointers);
    }

    #[Test]
    public function coerces_stdclass_to_object_root(): void
    {
        $body = new stdClass();
        $body->a = 1;
        $body->b = 2;

        $pointers = StrictRequiredBodyWalker::collectPointers($body);

        $this->assertSame(['/' => ['a', 'b']], $pointers);
    }

    #[Test]
    public function descends_into_nested_object_property(): void
    {
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'id' => '1',
            'data' => ['name' => 'x', 'created_at' => 'now'],
        ]);

        $this->assertSame(
            [
                '/' => ['data', 'id'],
                '/data' => ['created_at', 'name'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function descends_into_multiple_nested_objects(): void
    {
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'meta' => ['page' => 1, 'total' => 10],
            'data' => ['id' => '1'],
        ]);

        $this->assertSame(
            [
                '/' => ['data', 'meta'],
                '/data' => ['id'],
                '/meta' => ['page', 'total'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function records_array_element_pointer_with_star(): void
    {
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'items' => [
                ['id' => '1', 'name' => 'a'],
                ['id' => '2', 'name' => 'b'],
            ],
        ]);

        $this->assertSame(
            [
                '/' => ['items'],
                '/items[*]' => ['id', 'name'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function intersects_keys_across_array_elements(): void
    {
        // Three elements: only `id` appears in every element. `name` and
        // `created_at` are partial-presence and must NOT contribute to the
        // [*] intersection.
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'items' => [
                ['id' => '1', 'name' => 'a', 'created_at' => 't'],
                ['id' => '2', 'name' => 'b'],
                ['id' => '3', 'created_at' => 't'],
            ],
        ]);

        $this->assertSame(
            [
                '/' => ['items'],
                '/items[*]' => ['id'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function records_per_element_intersection_for_nested_object_under_star(): void
    {
        // Each items[*] has a `meta` object with `created_at` always
        // present and `flag` sometimes present.
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'items' => [
                ['id' => '1', 'meta' => ['created_at' => 't1', 'flag' => true]],
                ['id' => '2', 'meta' => ['created_at' => 't2']],
            ],
        ]);

        $this->assertSame(
            [
                '/' => ['items'],
                '/items[*]' => ['id', 'meta'],
                '/items[*]/meta' => ['created_at'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function skips_pointer_for_empty_array(): void
    {
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'items' => [],
        ]);

        $this->assertSame(['/' => ['items']], $pointers);
    }

    #[Test]
    public function handles_root_array_of_objects(): void
    {
        $pointers = StrictRequiredBodyWalker::collectPointers([
            ['id' => '1', 'name' => 'a'],
            ['id' => '2', 'name' => 'b'],
        ]);

        $this->assertSame(
            ['[*]' => ['id', 'name']],
            $pointers,
        );
    }

    #[Test]
    public function handles_deeply_nested_mixed_array_and_object(): void
    {
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'data' => [
                'rows' => [
                    ['id' => '1', 'tags' => [['t' => 'a'], ['t' => 'b']]],
                    ['id' => '2', 'tags' => [['t' => 'c']]],
                ],
            ],
        ]);

        $this->assertSame(
            [
                '/' => ['data'],
                '/data' => ['rows'],
                '/data/rows[*]' => ['id', 'tags'],
                '/data/rows[*]/tags[*]' => ['t'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function array_element_intersection_drops_pointer_when_all_elements_lack_object_shape(): void
    {
        // Array of scalars — no object keys to record; the `[*]` pointer
        // is not produced.
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'tags' => ['a', 'b', 'c'],
        ]);

        $this->assertSame(['/' => ['tags']], $pointers);
    }

    #[Test]
    public function array_with_mixed_object_and_scalar_elements_intersects_only_object_elements(): void
    {
        // Pragmatic: scalar elements collapse the object-shape intersection
        // because the scalar element carries zero keys. This mirrors the
        // cross-observation rule "absence drops the pointer".
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'items' => [
                ['id' => '1'],
                'scalar-instead',
                ['id' => '2'],
            ],
        ]);

        // [*] intersection across [{id}, scalar (zero-keys), {id}] = []
        $this->assertSame(
            [
                '/' => ['items'],
                '/items[*]' => [],
            ],
            $pointers,
        );
    }

    #[Test]
    public function escapes_slash_in_property_name(): void
    {
        // RFC 6901 JSON Pointer escapes: '/' → '~1'.
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'a/b' => ['inner' => 'x'],
        ]);

        $this->assertSame(
            [
                '/' => ['a/b'],
                '/a~1b' => ['inner'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function escapes_tilde_in_property_name(): void
    {
        // RFC 6901 JSON Pointer escapes: '~' → '~0'. Order matters: '~' must
        // be escaped first so '~1' (a slash) is not re-escaped to '~01'.
        $pointers = StrictRequiredBodyWalker::collectPointers([
            '~weird' => ['inner' => 'x'],
        ]);

        $this->assertSame(
            [
                '/' => ['~weird'],
                '/~0weird' => ['inner'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function escapes_star_marker_in_property_name(): void
    {
        // Extension beyond RFC 6901: a property literally named '[*]' must
        // not collide with the array-element marker. Escape as '[~*]'.
        $pointers = StrictRequiredBodyWalker::collectPointers([
            '[*]' => ['inner' => 'x'],
        ]);

        $this->assertSame(
            [
                '/' => ['[*]'],
                '/[~*]' => ['inner'],
            ],
            $pointers,
        );
    }

    #[Test]
    public function skips_non_string_keys_in_objects(): void
    {
        // PHP allows integer keys via ['1' => v]; coerced to int. We can
        // only meaningfully track string keys because the spec property
        // names are strings.
        $body = [];
        $body['name'] = 'x';
        $body[0] = 'numeric-key-ignored';

        // This is technically a list-shape from PHP's view (numeric 0). But
        // for safety, ensure the walker handles a mixed shape robustly.
        // We assert behavior is consistent with the validator's existing
        // semantics: associative arrays are object-shaped if NOT array_is_list.
        // Mixed string+int key arrays are NOT array_is_list, so this counts
        // as an object root and the int key is ignored.
        $pointers = StrictRequiredBodyWalker::collectPointers($body);

        $this->assertSame(['/' => ['name']], $pointers);
    }

    #[Test]
    public function record_input_pointers_are_sorted_and_unique(): void
    {
        // Sanity check on the walker's normalisation: the returned key
        // lists must be sorted and dedup'd. Duplicates cannot legitimately
        // arise from array_keys() but the contract documents the invariant.
        $pointers = StrictRequiredBodyWalker::collectPointers([
            'z' => 1,
            'a' => 1,
            'm' => 1,
        ]);

        $this->assertSame(['/' => ['a', 'm', 'z']], $pointers);
    }
}
