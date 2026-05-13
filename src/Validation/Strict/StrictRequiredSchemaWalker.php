<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * Pure helpers for resolving observed `(method, path, status, content-type,
 * pointer)` tuples against an OpenAPI schema's `required` arrays. Shared by
 * {@see StrictRequiredAsserter} (run-level intersection mode) and
 * {@see StrictRequiredPerCallChecker} (per-call warn mode) so both code
 * paths walk the spec the same way.
 *
 * `allOf` is unioned at every level when collecting `required`. `anyOf` /
 * `oneOf` are intentionally NOT walked — they are disjunctions and there is
 * no safe AND-semantic for "required" across them. The collected `required`
 * at such a node is therefore `[]`, and the descent stop is recorded as a
 * disjunction entry so callers can emit a NOTE rather than misleading drift
 * advice. See `docs/strict-required.md` "Known limitations" before relying on
 * those constructs.
 *
 * @internal Consumed by the strict-required asserter / per-call checker only.
 */
final class StrictRequiredSchemaWalker
{
    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Split a tracker endpoint key (`"METHOD path"`) back into its parts.
     *
     * Tolerates hand-built keys without a space by treating the whole
     * string as the method and defaulting the path to `/`. Production
     * tracker output always contains a space (see
     * {@see StrictRequiredTracker::record()}) so this fallback is reached
     * only from direct test inputs — surfacing a sensible default keeps
     * unit tests terse without obscuring real malformed keys via a hidden
     * throw.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitEndpointKey(string $endpointKey): array
    {
        $spacePos = strpos($endpointKey, ' ');
        if ($spacePos === false) {
            return [strtoupper($endpointKey), '/'];
        }

        return [
            strtoupper(substr($endpointKey, 0, $spacePos)),
            substr($endpointKey, $spacePos + 1),
        ];
    }

    /**
     * Split a tracker response key (`"statusKey:contentTypeKey"`) back into
     * its parts.
     *
     * As with {@see self::splitEndpointKey()}, a hand-built key without a
     * colon falls back to `[$key, ANY_CONTENT_TYPE]` — the production
     * tracker always emits the colon form, so this branch is test-only.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitResponseKey(string $responseKey): array
    {
        $colonPos = strpos($responseKey, ':');
        if ($colonPos === false) {
            return [$responseKey, StrictRequiredTracker::ANY_CONTENT_TYPE];
        }

        return [
            substr($responseKey, 0, $colonPos),
            substr($responseKey, $colonPos + 1),
        ];
    }

    /**
     * Locate the response schema dict for `(method, path, statusKey,
     * contentTypeKey)`. Returns `null` when any segment of the descent does
     * not resolve.
     *
     * @param array<string, mixed> $spec
     *
     * @return null|array<string, mixed>
     */
    public static function resolveResponseSchema(
        array $spec,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
    ): ?array {
        $lowerMethod = strtolower($method);
        $operation = $spec['paths'][$path][$lowerMethod] ?? null;
        if (!is_array($operation)) {
            return null;
        }
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses)) {
            return null;
        }
        $response = $responses[$statusKey] ?? null;
        if (!is_array($response)) {
            return null;
        }
        $content = $response['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }
        $entry = $content[$contentTypeKey] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $schema = $entry['schema'] ?? null;
        if (!is_array($schema)) {
            return null;
        }

        return $schema;
    }

    /**
     * Descend the response schema and return a {@see StrictRequiredSchemaAnalysis}
     * value object that pairs the walked-required map with the disjunction
     * list. Preferred over {@see self::collectRequiredByPointer()} when
     * downstream code wants the structural "check disjunction first, then
     * look up required" guarantee — call `$analysis->lookup($pointer)` and
     * branch on the union return type.
     *
     * @param array<string, mixed> $schema
     */
    public static function analyse(array $schema): StrictRequiredSchemaAnalysis
    {
        $raw = self::collectRequiredByPointer($schema);

        return new StrictRequiredSchemaAnalysis($raw['walked'], $raw['disjunctions']);
    }

    /**
     * Descend the response schema producing two parallel maps:
     *  - `walked`: `pointer => required-keys` for each object node reached.
     *    `allOf` branches are unioned at every level.
     *  - `disjunctions`: pointers where descent stopped because the node is
     *    `anyOf` / `oneOf` (no safe AND-semantic for `required` across
     *    disjunctions). Callers use this to surface observations under
     *    these pointers as NOTE rather than misleading drift advice.
     *
     * If the root schema itself is unwalkable, the disjunction list carries
     * an empty-pointer entry meaning "every observation is unwalkable."
     *
     * Production callers should prefer {@see self::analyse()}, which wraps
     * the same data in a {@see StrictRequiredSchemaAnalysis} value object
     * that prevents callers forgetting to consult the disjunction list. This
     * raw-array entry is retained for unit tests that need to inspect the
     * descent output directly without the wrapping abstraction.
     *
     * @param array<string, mixed> $schema
     *
     * @return array{walked: array<string, list<string>>, disjunctions: list<array{pointer: string, reason: string}>}
     */
    public static function collectRequiredByPointer(array $schema): array
    {
        $walked = [];
        $disjunctions = [];
        $rootShape = self::inferShape($schema);
        if ($rootShape === null) {
            // Root schema is itself unwalkable (anyOf/oneOf/scalar/empty).
            // Use an empty-pointer disjunction so any observed pointer
            // matches; the body could be either object- or array-shaped.
            // For scalar / empty roots `disjunctionReason()` returns null —
            // surface as the literal "unwalkable" so the unwalkable NOTE
            // line stays grep-friendly. In practice this fallback is
            // unreachable from production paths: a scalar / empty *schema*
            // combined with any body that produces pointers is a conformance
            // failure, so the validator's Success-only branch never reaches
            // the per-call checker / tracker for such observations. The
            // fallback exists to preserve the strict `reason: string`
            // contract for any future caller that loads a pre-walked
            // schema directly.
            $disjunctions[] = [
                'pointer' => '',
                'reason' => self::disjunctionReason($schema) ?? 'unwalkable',
            ];

            return ['walked' => $walked, 'disjunctions' => $disjunctions];
        }
        // Root-array schemas use bare `[*]` for their element pointer
        // (matching the walker's root-list convention); object roots start
        // at `/`.
        $rootPointer = $rootShape === 'array' ? '' : '/';
        self::descendSchema($schema, $rootPointer, $walked, $disjunctions);

        return ['walked' => $walked, 'disjunctions' => $disjunctions];
    }

    /**
     * Return the disjunction descriptor covering an observed pointer, or
     * `null` if none. An ancestor matches when the observed pointer equals
     * the disjunction's pointer OR descends from it via a `/` or `[`
     * separator boundary. A disjunction with an empty pointer (`""`) marks
     * "root schema is unwalkable" and covers every observation.
     *
     * @param list<array{pointer: string, reason: string}> $disjunctions
     *
     * @return null|array{pointer: string, reason: string}
     */
    public static function findCoveringDisjunction(string $pointer, array $disjunctions): ?array
    {
        foreach ($disjunctions as $d) {
            $dp = $d['pointer'];
            if ($dp === '') {
                return $d;
            }
            if ($pointer === $dp) {
                return $d;
            }
            if (str_starts_with($pointer, $dp . '/') || str_starts_with($pointer, $dp . '[')) {
                return $d;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, list<string>> $walked
     * @param list<array{pointer: string, reason: string}> $disjunctions
     */
    private static function descendSchema(array $schema, string $pointer, array &$walked, array &$disjunctions): void
    {
        $type = self::inferShape($schema);
        if ($type === 'object') {
            $walked[$pointer] = self::collectRequiredFromSchema($schema);
            foreach (self::collectPropertyBranches($schema) as $propName => $propSchema) {
                $childPointer = self::appendProperty($pointer, $propName);
                self::descendSchema($propSchema, $childPointer, $walked, $disjunctions);
            }

            return;
        }
        if ($type === 'array') {
            $items = self::collectItemsSchema($schema);
            if ($items !== null) {
                self::descendSchema($items, $pointer . '[*]', $walked, $disjunctions);
            }

            return;
        }
        // type is null — scalar leaf, anyOf / oneOf, or malformed node.
        // For disjunctions specifically, surface the descent stop as a
        // disjunction entry so the caller can emit a NOTE rather than
        // misleading drift advice. Scalar / empty nodes are silently
        // skipped — an observed pointer landing there really is "spec
        // doesn't model this" and surfacing as drift is appropriate.
        $reason = self::disjunctionReason($schema);
        if ($reason !== null) {
            $disjunctions[] = ['pointer' => $pointer, 'reason' => $reason];
        }
    }

    /**
     * Classify a schema node that {@see self::inferShape()} reported as
     * non-descendable. Returns the disjunction kind if applicable (so the
     * caller can emit a NOTE), `null` for scalar / empty leaves (which
     * legitimately do not contribute to `required` semantics).
     *
     * @param array<string, mixed> $schema
     */
    private static function disjunctionReason(array $schema): ?string
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            return 'anyOf';
        }
        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            return 'oneOf';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return null|'array'|'object'
     */
    private static function inferShape(array $schema): ?string
    {
        $type = $schema['type'] ?? null;
        if ($type === 'object' || isset($schema['properties'])) {
            return 'object';
        }
        if ($type === 'array' || isset($schema['items'])) {
            return 'array';
        }
        // allOf-only schema with no explicit type: treat as object if any
        // branch declares object-shape. Pragmatic inference matching how
        // most OpenAPI / JSON-Schema validators interpret untyped allOf
        // branches that declare `properties` / `required` / `items`. The
        // spec is technically ambiguous here, but treating dominant intent
        // as the descent rule avoids dropping coverage on otherwise-
        // walkable allOf compositions (e.g. `/orders/{id}` and `/deep` in
        // the test fixture).
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (is_array($branch) && (
                    ($branch['type'] ?? null) === 'object' ||
                    isset($branch['properties']) ||
                    isset($branch['required'])
                )) {
                    return 'object';
                }
                if (is_array($branch) && (
                    ($branch['type'] ?? null) === 'array' || isset($branch['items'])
                )) {
                    return 'array';
                }
            }
        }

        return null;
    }

    /**
     * Union of `required` arrays at this schema node, walking `allOf`
     * branches so nested descent inherits AND-semantic `required`
     * composition.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function collectRequiredFromSchema(array $schema): array
    {
        $collected = [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $entry) {
                if (is_string($entry)) {
                    $collected[] = $entry;
                }
            }
        }
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (is_array($branch)) {
                    foreach (self::collectRequiredFromSchema($branch) as $key) {
                        $collected[] = $key;
                    }
                }
            }
        }

        return array_values(array_unique($collected));
    }

    /**
     * Yield `propertyName => propertySchema` from this schema node plus any
     * `allOf` branches' properties. Later branches override earlier ones —
     * matches the OpenAPI composition convention.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, array<string, mixed>>
     */
    private static function collectPropertyBranches(array $schema): array
    {
        $out = [];
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $name => $sub) {
                if (is_string($name) && is_array($sub)) {
                    $out[$name] = $sub;
                }
            }
        }
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                foreach (self::collectPropertyBranches($branch) as $name => $sub) {
                    $out[$name] = $sub;
                }
            }
        }

        return $out;
    }

    /**
     * Resolve the `items` schema for an array node, looking through `allOf`
     * branches if the direct `items` field is absent.
     *
     * @param array<string, mixed> $schema
     *
     * @return null|array<string, mixed>
     */
    private static function collectItemsSchema(array $schema): ?array
    {
        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            return $items;
        }
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $branchItems = self::collectItemsSchema($branch);
                if ($branchItems !== null) {
                    return $branchItems;
                }
            }
        }

        return null;
    }

    /**
     * Append a property name to a JSON-Pointer-like path with RFC 6901
     * escaping (`~` → `~0`, `/` → `~1`) plus the `[*]` → `[~*]` extension
     * shared with {@see StrictRequiredBodyWalker::appendProperty()}.
     *
     * Inlined rather than shared because the two walkers stay drop-in
     * independent — the body walker walks observed JSON, this walker walks
     * the spec, and the escape rules are intentionally pinned to the body
     * walker's pointer notation. If a new escape is ever needed (e.g.
     * for a future container syntax), both implementations must move
     * together — add a regression test in both walkers' test files.
     */
    private static function appendProperty(string $pointer, string $propertyName): string
    {
        $escaped = str_replace('~', '~0', $propertyName);
        $escaped = str_replace('/', '~1', $escaped);
        $escaped = str_replace('[*]', '[~*]', $escaped);

        if ($pointer === '/') {
            return '/' . $escaped;
        }

        return $pointer . '/' . $escaped;
    }
}
