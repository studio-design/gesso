<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const FILTER_VALIDATE_INT;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function filter_var;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class TypeCoercer
{
    /**
     * Pick the first primitive type from an OAS 3.1 multi-type declaration,
     * skipping `null`. Returns `null` if no usable string type is found.
     *
     * @param array<int|string, mixed> $types
     */
    public static function firstPrimitiveType(array $types): ?string
    {
        foreach ($types as $candidate) {
            if (is_string($candidate) && $candidate !== 'null') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Scalar-only variant used for path / header parameters. The input arrives
     * as a single string (OpenAPI default `style: simple`) so array handling
     * is never appropriate — a spec declaring `type: array` for such a param
     * would be rejected by opis because the request value is still scalar.
     *
     * @param array<string, mixed> $schema
     */
    public static function coercePrimitive(mixed $value, array $schema): mixed
    {
        return self::coercePrimitiveFromType($value, self::effectiveType($schema));
    }

    /**
     * Shared scalar coercion: string → int/float/bool when the target type is
     * clean, otherwise the original value passes through so opis can report a
     * meaningful type mismatch.
     */
    public static function coercePrimitiveFromType(mixed $value, mixed $type): mixed
    {
        if (!is_string($value) || !is_string($type)) {
            return $value;
        }

        return match ($type) {
            'integer' => self::coerceToInt($value),
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => match (strtolower($value)) {
                'true' => true,
                'false' => false,
                default => $value,
            },
            default => $value,
        };
    }

    /**
     * Conservatively coerce a query string value to the type declared by the
     * schema. When the string is not a clean representation of the target
     * type, the original value is returned unchanged so opis can surface a
     * meaningful type error rather than silently passing.
     *
     * For multi-type schemas (OAS 3.1 `type: ["integer", "null"]`) the first
     * non-`null` primitive type is used as the coercion target. For
     * `type: array`, each item is coerced against the declared `items` schema.
     *
     * @param array<string, mixed> $schema
     */
    public static function coerceQuery(mixed $value, array $schema): mixed
    {
        $type = self::effectiveType($schema);

        if ($type === 'array') {
            $value = is_array($value) ? array_values($value) : [$value];

            $itemSchemas = self::itemSchemas($schema);
            if ($itemSchemas === []) {
                return $value;
            }

            // Every one of them constrains the same elements, so hand the
            // conjunction down and let the recursion intersect it.
            $itemSchema = count($itemSchemas) === 1 ? $itemSchemas[0] : ['allOf' => $itemSchemas];

            return array_map(static fn(mixed $item): mixed => self::coerceQuery($item, $itemSchema), $value);
        }

        return self::coercePrimitiveFromType($value, $type);
    }

    /**
     * Coerce a scalar string (from a path / header / query parameter) to int.
     *
     * `filter_var(FILTER_VALIDATE_INT)` is too permissive for contract testing:
     * it accepts leading/trailing whitespace (e.g. "5 " → 5) and a leading
     * sign prefix ("+5" → 5). Accepting those laundering behaviours would
     * silently pass non-canonical values that real servers typically reject,
     * creating silent drift between the test harness and production. Pre-filter
     * with a strict canonical-integer regex: optional leading `-`, then either
     * `0` or a digit string without a leading zero. Anything else falls through
     * unchanged so opis can report a meaningful type error.
     *
     * Overflow is handled the same way: values exceeding PHP_INT_MAX/MIN
     * cause `filter_var` to return `false`, and the original string is
     * returned so opis surfaces the type mismatch.
     */
    public static function coerceToInt(string $value): int|string
    {
        if (preg_match('/^-?(0|[1-9]\d*)$/', $value) !== 1) {
            return $value;
        }

        $result = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($result) ? $result : $value;
    }

    /**
     * The single type the value must end up as, once every `allOf` branch has
     * had its say. `allOf` is an in-place applicator: every branch constrains
     * this same value, so the effective type is the *intersection* of the
     * declared type sets, not whichever one happens to sit at the top level.
     *
     * Hand-written `{allOf: [{$ref: …}], maximum: 3}` parameter schemas have
     * always had that shape, and reference resolution also produces it for a
     * `$ref` target that declares its own `$schema` and therefore cannot be
     * merged flat. A referrer widening to `type: ["string", "integer"]` over a
     * branch pinned to `integer` is only satisfiable as an integer — reading
     * the top level alone leaves the raw string uncoerced, and a valid request
     * then fails against the branch with a bogus type error.
     *
     * @param array<string, mixed> $schema
     */
    private static function effectiveType(array $schema): ?string
    {
        $candidates = self::typeCandidates($schema);

        return $candidates === null ? null : self::firstPrimitiveType($candidates);
    }

    /**
     * The type names the schema and its `allOf` branches jointly allow, or
     * `null` when none of them declares `type` at all. An empty list means the
     * declarations contradict each other: nothing can satisfy the schema, so
     * no coercion is appropriate and the validator reports it.
     *
     * @param array<string, mixed> $schema
     *
     * @return null|list<string>
     */
    private static function typeCandidates(array $schema): ?array
    {
        $candidates = array_key_exists('type', $schema) ? self::typeNames($schema['type']) : null;

        $branches = $schema['allOf'] ?? null;
        if (!is_array($branches)) {
            return $candidates;
        }

        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            $branchCandidates = self::typeCandidates($branch);
            if ($branchCandidates === null) {
                continue;
            }

            $candidates = $candidates === null
                ? $branchCandidates
                : self::intersectTypes($candidates, $branchCandidates);
        }

        return $candidates;
    }

    /**
     * Intersect two type sets. `integer` is a subset of `number`, not a
     * sibling of it, so a schema that is `integer` on one side and `number`
     * on the other narrows to `integer` — a plain name comparison would call
     * that unsatisfiable and leave a perfectly valid request uncoerced.
     *
     * @see https://json-schema.org/draft/2020-12/json-schema-validation#name-type
     *
     * @param list<string> $first
     * @param list<string> $second
     *
     * @return list<string>
     */
    private static function intersectTypes(array $first, array $second): array
    {
        $intersection = [];

        foreach ($first as $type) {
            if (in_array($type, $second, true)) {
                $intersection[] = $type;
                continue;
            }

            if (
                ($type === 'number' && in_array('integer', $second, true)) ||
                ($type === 'integer' && in_array('number', $second, true))
            ) {
                $intersection[] = 'integer';
            }
        }

        return self::normalizeTypes($intersection);
    }

    /**
     * Drop the types another member of the set already covers. A union
     * offering both `integer` and `number` is just `number`, and leaving the
     * redundant `integer` in front of it would coerce `3.14` towards an
     * integer, fail, and reject a value the schema accepts.
     *
     * @param list<string> $types
     *
     * @return list<string>
     */
    private static function normalizeTypes(array $types): array
    {
        $types = array_values(array_unique($types));

        if (!in_array('number', $types, true)) {
            return $types;
        }

        return array_values(array_filter($types, static fn(string $type): bool => $type !== 'integer'));
    }

    /**
     * @return null|list<string>
     */
    private static function typeNames(mixed $type): ?array
    {
        if (is_string($type)) {
            return [$type];
        }

        // A malformed `type` constrains nothing we can read; leave the value
        // alone and let the validator report the schema.
        return is_array($type)
            ? self::normalizeTypes(array_values(array_filter($type, is_string(...))))
            : null;
    }

    /**
     * Every `items` schema that applies to this array — the schema's own and
     * each `allOf` branch's. They all constrain the same elements, so the
     * effective item schema is their conjunction; taking whichever one is
     * found first leaves the elements coerced to the wrong type, or not
     * coerced at all.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<array<string, mixed>>
     */
    private static function itemSchemas(array $schema): array
    {
        $schemas = is_array($schema['items'] ?? null) ? [$schema['items']] : [];

        $branches = $schema['allOf'] ?? null;
        if (!is_array($branches)) {
            return $schemas;
        }

        foreach ($branches as $branch) {
            if (is_array($branch)) {
                $schemas = [...$schemas, ...self::itemSchemas($branch)];
            }
        }

        return $schemas;
    }
}
