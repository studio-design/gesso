<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

use stdClass;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;

use function array_intersect;
use function array_is_list;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function ksort;
use function sort;
use function str_replace;

/**
 * Walk a decoded response body once and produce a `pointer => sorted-unique
 * string keys` map describing every object node observed. Feeds the row data
 * that {@see StrictRequiredTracker} keeps per `(endpoint, response)` group.
 *
 * Pointer notation:
 *  - `/` is the root object pointer
 *  - `/foo` is the `foo` property of the root object
 *  - `/foo/bar` is the `bar` property of the `foo` object
 *  - `/foo[*]` is the element-shape of the `foo` array (one entry per array,
 *    aggregated by intersection across that array's elements)
 *  - `[*]` is the element-shape when the root itself is a JSON array
 *
 * RFC 6901 escapes (`~` → `~0`, `/` → `~1`) are applied to property names,
 * plus a non-standard `[*]` → `[~*]` escape so a property literally named
 * `[*]` does not collide with the array-element marker.
 *
 * Array elements aggregate via intersection within a single response: only
 * keys present in *every* element survive the `[*]` pointer's key list. This
 * mirrors the tracker's cross-response intersection, applied one level down.
 *
 * @internal Used by {@see OpenApiResponseValidator}
 *           and unit tests; the return shape feeds
 *           {@see StrictRequiredTracker::record()} directly.
 */
final class StrictRequiredBodyWalker
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * @return array<string, list<string>>
     */
    public static function collectPointers(mixed $body): array
    {
        if ($body instanceof stdClass) {
            $body = (array) $body;
        }
        if (!is_array($body)) {
            return [];
        }

        $out = [];
        if ($body === [] || !array_is_list($body)) {
            // Object root (including empty {}). Pointer starts at "/".
            self::walkObject($body, '/', $out);
        } else {
            // Non-empty list root: walk under the bare "[*]" pointer so
            // children come out as "[*]/foo" etc.
            self::walkList($body, '', $out);
        }

        ksort($out);

        return $out;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, list<string>> $out
     */
    private static function walkObject(array $node, string $pointer, array &$out): void
    {
        $keys = [];
        foreach (array_keys($node) as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }
        $out[$pointer] = self::sortedUnique($keys);

        foreach ($node as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $childPointer = self::appendProperty($pointer, $key);
            self::walkValue($value, $childPointer, $out);
        }
    }

    /**
     * @param array<int, mixed> $list
     * @param array<string, list<string>> $out
     */
    private static function walkList(array $list, string $pointer, array &$out): void
    {
        if ($list === []) {
            // Empty list: nothing to observe. Skipping the `[*]` pointer
            // means the tracker's "absence drops the pointer" rule applies
            // across observations — a sometimes-empty array does NOT lock
            // its element-shape intersection to `[]`.
            return;
        }

        $starPointer = $pointer . '[*]';
        $sawObjectElement = false;
        $elementKeySets = [];
        $childKeySetsByPointer = [];

        foreach ($list as $element) {
            if ($element instanceof stdClass) {
                $element = (array) $element;
            }

            if (!is_array($element) || ($element !== [] && array_is_list($element))) {
                // Scalar / null / nested non-empty list element.
                // Contributes a zero-key observation to the `[*]` intersection
                // — when mixed with object elements this collapses the
                // intersection to `[]`, mirroring the tracker's
                // "absence drops the pointer" rule one level down.
                $elementKeySets[] = [];

                if (is_array($element) && $element !== [] && array_is_list($element)) {
                    // Recurse into the nested list under the same star
                    // pointer so deeper arrays of objects are still observed.
                    $subOut = [];
                    self::walkList($element, $starPointer, $subOut);
                    foreach ($subOut as $childPointer => $childKeys) {
                        $childKeySetsByPointer[$childPointer][] = $childKeys;
                    }
                }

                continue;
            }

            // Object-shape element (including empty `{}` which we treat
            // as empty object — consistent with the root-`{}` semantic
            // used by the validator pre-#227).
            $sawObjectElement = true;
            $thisElementKeys = [];
            foreach (array_keys($element) as $key) {
                if (is_string($key)) {
                    $thisElementKeys[] = $key;
                }
            }
            $elementKeySets[] = self::sortedUnique($thisElementKeys);

            $subOut = [];
            self::walkObject($element, $starPointer, $subOut);
            foreach ($subOut as $childPointer => $childKeys) {
                if ($childPointer === $starPointer) {
                    // The element's own keys are already in $thisElementKeys.
                    continue;
                }
                $childKeySetsByPointer[$childPointer][] = $childKeys;
            }
        }

        if (!$sawObjectElement) {
            // Pure scalar / nested-list list — no object structure to compare
            // against `required`. Skip recording the `[*]` pointer entirely.
            return;
        }

        $out[$starPointer] = self::intersectAll($elementKeySets);

        $elementCount = count($elementKeySets);
        foreach ($childKeySetsByPointer as $childPointer => $sets) {
            // A child pointer is "always observed" only when every element
            // contributed it. If fewer sets exist than elements, the missing
            // elements implicitly contributed zero keys → intersection is [].
            if (count($sets) < $elementCount) {
                $out[$childPointer] = [];

                continue;
            }
            $out[$childPointer] = self::intersectAll($sets);
        }
    }

    /**
     * @param array<string, list<string>> $out
     */
    private static function walkValue(mixed $value, string $pointer, array &$out): void
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return;
        }
        if ($value === []) {
            // Ambiguous at child position: could be empty object or empty
            // list. Skipping is consistent with the empty-list rule above
            // and avoids polluting object-pointer rows with `pointer => []`
            // entries from a sometimes-empty nested array.
            return;
        }
        if (!array_is_list($value)) {
            self::walkObject($value, $pointer, $out);

            return;
        }
        self::walkList($value, $pointer, $out);
    }

    private static function appendProperty(string $pointer, string $propertyName): string
    {
        $escaped = self::escapeProperty($propertyName);
        if ($pointer === '/') {
            return '/' . $escaped;
        }

        return $pointer . '/' . $escaped;
    }

    private static function escapeProperty(string $name): string
    {
        // RFC 6901 order: '~' must be escaped first so subsequent '/' → '~1'
        // does not get re-escaped to '~01'.
        $escaped = str_replace('~', '~0', $name);
        $escaped = str_replace('/', '~1', $escaped);

        return str_replace('[*]', '[~*]', $escaped);
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function sortedUnique(array $keys): array
    {
        $unique = array_values(array_unique($keys));
        sort($unique);

        return $unique;
    }

    /**
     * @param list<list<string>> $sets
     *
     * @return list<string>
     */
    private static function intersectAll(array $sets): array
    {
        if ($sets === []) {
            return [];
        }
        $accumulator = $sets[0];
        $count = count($sets);
        for ($i = 1; $i < $count; $i++) {
            $accumulator = array_values(array_intersect($accumulator, $sets[$i]));
            if ($accumulator === []) {
                break;
            }
        }
        sort($accumulator);

        return $accumulator;
    }
}
