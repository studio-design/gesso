<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use stdClass;
use Studio\Gesso\Spec\OpenApiRefResolver;

use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_slice;
use function count;
use function ctype_digit;
use function explode;
use function get_object_vars;
use function is_array;
use function preg_match;
use function str_starts_with;

/**
 * The one RFC 6901 JSON Pointer walk shared by `$ref` resolution, OpenAPI
 * Links and Arazzo runtime expressions. Callers decode segments themselves:
 * `$ref` fragments are URI fragments (percent-decoded first), runtime
 * expressions use the plain string form (no percent-decoding).
 *
 * @internal Runtime-expression plumbing shared by Links exploration and the Arazzo runner; not public API.
 */
final class JsonPointer
{
    private function __construct() {}

    /**
     * Split an RFC 6901 string-form pointer into decoded segments.
     *
     * @return list<string>
     *
     * @throws RuntimeExpressionException Malformed, on a non-empty pointer not starting with `/` or a `~` not followed by `0`/`1`
     */
    public static function segments(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        if (!str_starts_with($pointer, '/')) {
            throw new RuntimeExpressionException(RuntimeExpressionFailure::Malformed, $pointer, "a JSON Pointer must be empty or start with '/'.");
        }

        if (preg_match('/~(?![01])/', $pointer) === 1) {
            throw new RuntimeExpressionException(RuntimeExpressionFailure::Malformed, $pointer, "'~' in a JSON Pointer must be escaped as '~0' or '~1'.");
        }

        return array_map(
            OpenApiRefResolver::unescapePointerSegment(...),
            array_slice(explode('/', $pointer), 1),
        );
    }

    /**
     * Walk decoded segments into a decoded JSON value. `found=false`
     * distinguishes a missing member from a literal `null` leaf.
     *
     * @param list<string> $segments
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function walk(array $segments, mixed $root): array
    {
        $node = $root;
        foreach ($segments as $segment) {
            if ($node instanceof stdClass) {
                $node = get_object_vars($node);
            }

            if (!is_array($node)) {
                return [false, null];
            }

            if (array_is_list($node) && $node !== []) {
                // RFC 6901 §4: array indices are decimal without leading zeros.
                if (!ctype_digit($segment) || ($segment !== '0' && $segment[0] === '0') || (int) $segment >= count($node)) {
                    return [false, null];
                }

                $node = $node[(int) $segment];

                continue;
            }

            if (!array_key_exists($segment, $node)) {
                return [false, null];
            }

            $node = $node[$segment];
        }

        return [true, $node];
    }
}
