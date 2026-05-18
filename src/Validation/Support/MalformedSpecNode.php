<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use function array_is_list;
use function get_debug_type;
use function is_array;

/**
 * Shared shape check for OpenAPI structural nodes that MUST decode to a JSON
 * object — `paths`, a path item, an operation, the `responses` map, a
 * `responses[$status]` entry, a `content` map, a media-type entry, a
 * `schema`, and so on.
 *
 * A spec authored in YAML/JSON can decode such a node to the wrong PHP type
 * in two ways, both malformed:
 *
 *  - a scalar or `null` — a stray value, an empty YAML key, or an unresolved
 *    `$ref`; left unguarded it reaches an `array`-typed sink and raises an
 *    uncaught `TypeError`;
 *  - a JSON array (`[...]`) written where an object (`{...}`) was meant;
 *    left unguarded it passes `is_array()` and then mis-resolves silently
 *    (integer keys never match a path / method / status).
 *
 * The validators route every such guard through this class so the two
 * failure modes surface as one consistent, loud spec error.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class MalformedSpecNode
{
    /**
     * True when `$node` is NOT a usable JSON object for a structural spec
     * position.
     *
     * Rejects every non-array value (scalar / `null`) and every non-empty
     * list array (a JSON `[...]` where an object was expected). An empty
     * array is accepted: `{}` and `[]` both decode to `[]` in PHP, so an
     * empty node is treated as an empty object — harmless, and the
     * downstream "not found / not defined" diagnostics handle it.
     *
     * @phpstan-assert-if-false array<array-key, mixed> $node
     */
    public static function isMalformed(mixed $node): bool
    {
        if (!is_array($node)) {
            return true;
        }

        return $node !== [] && array_is_list($node);
    }

    /**
     * Human-readable type of a malformed node for the
     * "expected object, got X" diagnostic. Only meaningful when
     * {@see self::isMalformed()} returned true.
     *
     * A malformed array is always a non-empty list, reported as `list`;
     * anything else is a scalar / `null`, reported via `get_debug_type()`
     * (`string`, `int`, `float`, `bool`, `null`).
     */
    public static function describe(mixed $node): string
    {
        return is_array($node) ? 'list' : get_debug_type($node);
    }
}
