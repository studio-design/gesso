<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use const E_USER_WARNING;
use const PHP_FLOAT_EPSILON;

use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use stdClass;
use Studio\Gesso\Spec\OpenApiSchemaConverter;

use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function ceil;
use function class_exists;
use function count;
use function floor;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function round;
use function sprintf;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trigger_error;

/**
 * Generate happy-path values that conform to a converted JSON Schema.
 *
 * Inputs are expected to be already-converted via {@see OpenApiSchemaConverter}
 * — i.e. OAS-only keys have been stripped or lowered (including
 * `discriminator`), and OAS 3.1 type-arrays normalised.
 *
 * Supported keywords: `type` (string|integer|number|boolean|object|array|null),
 * `const`, `enum`, `format` (email|uuid|date|date-time|uri|url),
 * length/numeric/collection boundaries, object properties, `items` /
 * `prefixItems`, common patterns, and composition (`oneOf`, `anyOf`, `allOf`,
 * `not`, and conditionals). The public strategy matrix in docs/fuzzing.md is
 * authoritative for exact support and limitations.
 *
 * Determinism: when a `$seed` is supplied AND `fakerphp/faker` is installed,
 * faker is seeded so the same `(schema, count, seed)` triple produces the same
 * output across runs. Without faker, the generator falls back to deterministic
 * values keyed off the per-case iteration index — repeated calls still produce
 * identical output for the same `count`.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SchemaDataGenerator
{
    private const MAX_SYNTHESIZED_PATTERN_LENGTH = 10_000;

    /**
     * How many nearby iterations planned `not` generation probes before
     * falling back to scalar alternatives. Finite domains do not rely on
     * this: enums under a `not` are filtered exhaustively. The retries cover
     * the remaining scalar rotations (boolean parity, string/number
     * boundary cycles), and are deliberately larger than the 2/3-cycle
     * rotation periods so every such rotation state is revisited.
     */
    private const NOT_GENERATION_RETRIES = 8;

    /**
     * Per-process record of formats already announced as "faker missing".
     * Keyed by format name; we only warn once per format to avoid spamming
     * a long fuzz run that touches many `email` properties.
     *
     * @var array<string, true>
     */
    private static array $warnedFakerFormats = [];

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<mixed>
     */
    public static function generate(array $schema, int $count, ?int $seed = null): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException(
                'SchemaDataGenerator::generate() requires count >= 1, got ' . $count . '.',
            );
        }

        $faker = self::createFaker($seed);
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $value = self::generateOne($schema, $faker, $i);
            SchemaValueValidator::assertValid($value, $schema, $i);
            $results[] = $value;
        }

        return $results;
    }

    /**
     * Single-value generation entry — exposed so callers like
     * {@see OpenApiEndpointExplorer} can share a faker instance across
     * multiple schemas (body + parameters) within a single case.
     *
     * When a {@see CaseSelectionPlan} is supplied, choice points whose
     * pointer (relative to `$pointer`) carries a pinned selection take that
     * branch instead of rotating with the iteration index; everything else
     * keeps the documented rotation strategy, so a null plan reproduces the
     * historical output bit-for-bit.
     *
     * @param array<string, mixed> $schema
     */
    public static function generateOne(
        array $schema,
        ?Generator $faker,
        int $iteration,
        ?CaseSelectionPlan $plan = null,
        string $pointer = '',
        bool $forced = true,
    ): mixed {
        $value = self::generateNode($schema, $faker, $iteration, $plan, $pointer, $forced);
        // The site that applied the plan's target registered its
        // branch-level check while this node resolved; the value exists
        // only now. Nested regenerations at the same pointer evaluated
        // their own candidates first — this call ran last, so the final
        // observation describes the returned value.
        $plan?->observation->evaluate($pointer, $value);

        return $value;
    }

    /**
     * Build a faker generator when the package is installed; null otherwise.
     * The null branch is documented and exercised by tests — see the class
     * docblock for the determinism contract in either case.
     */
    public static function createFaker(?int $seed): ?Generator
    {
        if (!class_exists(Factory::class)) {
            return null;
        }

        $faker = Factory::create();
        if ($seed !== null) {
            $faker->seed($seed);
        }

        return $faker;
    }

    /**
     * Reset the per-process "already warned" record. Tests use this to
     * exercise the warning path multiple times without leaking state across
     * cases; production callers never need to.
     *
     * @internal
     */
    public static function resetWarningStateForTesting(): void
    {
        self::$warnedFakerFormats = [];
    }

    /**
     * Resolve the effective type of a schema. Type may be a string, a
     * Draft-2020 array (`["string", "null"]` for nullable), or absent — in
     * which case we infer from `properties`/`items` and finally default to
     * `string` so a permissive untyped schema still produces a value.
     *
     * Public within the internal fuzz family so
     * {@see SchemaChoicePointEnumerator} descends exactly the nodes this
     * generator would materialise.
     *
     * @param array<string, mixed> $schema
     */
    public static function resolveType(array $schema): string
    {
        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            return $type;
        }

        if (is_array($type) && $type !== []) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    return $candidate;
                }
            }

            return 'null';
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            return 'object';
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            return 'array';
        }

        if (isset($schema['prefixItems']) && is_array($schema['prefixItems'])) {
            return 'array';
        }

        return 'string';
    }

    /**
     * Materialise the starting view of one branch of the conditional-`allOf`
     * choice space: branches `0..n-1` satisfy that conditional's `if`+`then`,
     * branch `n` is the none-match state where no `if` holds and every
     * `else` applies. Generation may grow the satisfied set beyond this
     * starting point when suppressed conditionals fire anyway — see
     * {@see self::generateConditionalNode()}. Public within the internal
     * fuzz family so {@see SchemaChoicePointEnumerator} descends exactly
     * these views.
     *
     * @param array<string, mixed> $schema node with `allOf` removed and its non-conditional branches merged
     * @param list<array<string, mixed>> $conditionals
     *
     * @return array<string, mixed>
     */
    public static function conditionalBranchView(array $schema, array $conditionals, int $branch): array
    {
        return self::conditionalSetView($schema, $conditionals, $branch < count($conditionals) ? [$branch] : []);
    }

    /**
     * Materialise the conditional-`allOf` state where exactly the
     * conditionals in `$satisfied` hold: their `if`+`then` merged, every
     * other conditional suppressed through a single synthesized `not`/`anyOf`
     * with its `else` applied. {@see self::generateConditionalNode()} starts
     * from a singleton (or empty) set and grows it when a suppressed `if`
     * turns out to fire anyway, so overlapping conditionals stay satisfiable.
     *
     * @param array<string, mixed> $schema node with `allOf` removed and its non-conditional branches merged
     * @param list<array<string, mixed>> $conditionals
     * @param list<int> $satisfied
     *
     * @return array<string, mixed>
     */
    public static function conditionalSetView(array $schema, array $conditionals, array $satisfied): array
    {
        foreach ($satisfied as $index) {
            $selected = $conditionals[$index];
            $schema = self::mergeSchemas($schema, $selected['if']);
            if (isset($selected['then']) && is_array($selected['then'])) {
                $schema = self::mergeSchemas($schema, $selected['then']);
            }
        }

        $suppressedIfs = [];
        $negatedByProperty = [];
        foreach ($conditionals as $index => $conditional) {
            if (in_array($index, $satisfied, true)) {
                continue;
            }
            // A single-property `if` — the discriminator-lowering shape —
            // is negated equivalently at the property itself: `¬(p present
            // ∧ s(p))` is exactly "when present, `p` fails `s`". That puts
            // the exclusion where value generation can honour it (the enum
            // domain filter); other shapes stay in a node-level not/anyOf.
            $condition = self::singlePropertyCondition($conditional['if']);
            if ($condition !== null) {
                $negatedByProperty[$condition[0]][] = $condition[1];
            } else {
                $suppressedIfs[] = $conditional['if'];
            }
            if (isset($conditional['else']) && is_array($conditional['else'])) {
                $schema = self::mergeSchemas($schema, $conditional['else']);
            }
        }
        // mergeSchemas() combines `not` assertions conjunctively, so any
        // exclusion the target already carries — from the base schema or an
        // earlier else merge — survives these merges.
        foreach ($negatedByProperty as $property => $negated) {
            $schema = self::mergeSchemas($schema, ['properties' => [
                $property => ['not' => count($negated) === 1 ? $negated[0] : ['anyOf' => $negated]],
            ]]);
        }
        if ($suppressedIfs !== []) {
            $schema = self::mergeSchemas($schema, [
                'not' => count($suppressedIfs) === 1 ? $suppressedIfs[0] : ['anyOf' => $suppressedIfs],
            ]);
        }

        return $schema;
    }

    /**
     * Merge the assertion keywords needed for deterministic allOf generation.
     *
     * Public within the internal fuzz family so
     * {@see SchemaChoicePointEnumerator} merges branch views with exactly the
     * semantics generation applies.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    public static function mergeSchemas(array $left, array $right): array
    {
        $merged = array_merge($left, $right);
        if (is_array($left['properties'] ?? null) || is_array($right['properties'] ?? null)) {
            $merged['properties'] = self::mergePropertySchemas(
                is_array($left['properties'] ?? null) ? $left['properties'] : [],
                is_array($right['properties'] ?? null) ? $right['properties'] : [],
            );
        }
        if (is_array($left['required'] ?? null) || is_array($right['required'] ?? null)) {
            $merged['required'] = array_values(array_unique(array_merge(
                is_array($left['required'] ?? null) ? $left['required'] : [],
                is_array($right['required'] ?? null) ? $right['required'] : [],
            )));
        }
        // `enum` and `type` are assertions too: a value must satisfy both
        // sides, so their domains intersect. Right-side order (and, for an
        // unconflicted `type`, the right side verbatim) is preserved: when
        // the right domain is already a subset of the left, the merge is
        // byte-identical to the historical array_merge result, keeping
        // plan-less rotation output bit-for-bit. Conflicting domains
        // previously generated values that failed the loud self-check.
        if (is_array($left['enum'] ?? null) && is_array($right['enum'] ?? null)) {
            if ($left['enum'] === [] || $right['enum'] === []) {
                // An empty side — user-authored or a conflict marker from an
                // earlier merge — states the conjunction is already empty;
                // array_merge displacement must not resurrect the domain.
                $merged['enum'] = [];
            } else {
                // Membership uses JSON Schema equality (2020-12 §4.2.2) via
                // the validator — 1 and 1.0 are the same mathematical value,
                // and object equality ignores key order — not PHP identity.
                $leftEnum = ['enum' => $left['enum']];
                $merged['enum'] = array_values(array_filter(
                    $right['enum'],
                    static fn(mixed $value): bool => SchemaValueValidator::isValid($value, $leftEnum),
                ));
            }
        }
        // A `const` conflicting with the other side's `const` or `enum` is a
        // static proof the conjunction admits no value; the empty-enum
        // marker lets planned generation treat the dead end as proven. The
        // marker is inert on the plan-less path: `const` is generated first
        // and an empty `enum` is never consulted.
        $leftConst = array_key_exists('const', $left);
        $rightConst = array_key_exists('const', $right);
        if ($leftConst && $rightConst &&
            !SchemaValueValidator::isValid($left['const'], ['const' => $right['const']])) {
            $merged['enum'] = [];
        } elseif ($leftConst && is_array($right['enum'] ?? null) && $right['enum'] !== [] &&
            !SchemaValueValidator::isValid($left['const'], ['enum' => $right['enum']])) {
            $merged['enum'] = [];
        } elseif ($rightConst && is_array($left['enum'] ?? null) && $left['enum'] !== [] &&
            !SchemaValueValidator::isValid($right['const'], ['enum' => $left['enum']])) {
            $merged['enum'] = [];
        }
        $leftType = $left['type'] ?? null;
        $rightType = $right['type'] ?? null;
        if ((is_string($leftType) || is_array($leftType)) && (is_string($rightType) || is_array($rightType))) {
            $leftTypes = is_string($leftType) ? [$leftType] : $leftType;
            $rightTypes = is_string($rightType) ? [$rightType] : $rightType;
            $intersection = [];
            foreach ($rightTypes as $type) {
                if (in_array($type, $leftTypes, true)) {
                    $intersection[] = $type;
                } elseif (($type === 'number' || $type === 'integer') &&
                    (in_array('number', $leftTypes, true) || in_array('integer', $leftTypes, true))) {
                    // integer is the zero-fraction subtype of number
                    // (2020-12 §6.1.1): number ∧ integer = integer.
                    $intersection[] = 'integer';
                }
            }
            $intersection = array_values(array_unique($intersection));
            if ($intersection !== $rightTypes) {
                $merged['type'] = $intersection;
            }
        }
        // `not` is an assertion like the bound keywords below: both sides
        // must keep holding after a merge — ¬A ∧ ¬B ⟺ ¬(anyOf [A, B]).
        // Plain array_merge would let the right side displace the left and
        // silently widen the admissible domain. Operands may be JSON Schema
        // 2020-12 booleans, which conjoin by identity: `not: false` matches
        // everything (neutral), `not: true` matches nothing (absorbing).
        $leftNot = $left['not'] ?? null;
        $rightNot = $right['not'] ?? null;
        if ((is_array($leftNot) || is_bool($leftNot)) && (is_array($rightNot) || is_bool($rightNot))) {
            $merged['not'] = match (true) {
                $leftNot === false => $rightNot,
                $rightNot === false => $leftNot,
                $leftNot === true || $rightNot === true => true,
                $leftNot === $rightNot => $leftNot,
                default => ['anyOf' => [$leftNot, $rightNot]],
            };
        }
        if (isset($left['minimum'], $right['minimum'])) {
            $merged['minimum'] = max($left['minimum'], $right['minimum']);
        }
        if (isset($left['maximum'], $right['maximum'])) {
            $merged['maximum'] = min($left['maximum'], $right['maximum']);
        }
        if (isset($left['minLength'], $right['minLength'])) {
            $merged['minLength'] = max($left['minLength'], $right['minLength']);
        }
        if (isset($left['maxLength'], $right['maxLength'])) {
            $merged['maxLength'] = min($left['maxLength'], $right['maxLength']);
        }
        foreach (['minItems', 'minProperties'] as $minimumKeyword) {
            if (isset($left[$minimumKeyword], $right[$minimumKeyword])) {
                $merged[$minimumKeyword] = max($left[$minimumKeyword], $right[$minimumKeyword]);
            }
        }
        foreach (['maxItems', 'maxProperties'] as $maximumKeyword) {
            if (isset($left[$maximumKeyword], $right[$maximumKeyword])) {
                $merged[$maximumKeyword] = min($left[$maximumKeyword], $right[$maximumKeyword]);
            }
        }
        if ((is_int($left['multipleOf'] ?? null) || is_float($left['multipleOf'] ?? null)) &&
            (is_int($right['multipleOf'] ?? null) || is_float($right['multipleOf'] ?? null))) {
            $multipleOf = DecimalMultiple::leastCommonMultiple($left['multipleOf'], $right['multipleOf']);
            if ($multipleOf === null) {
                throw new InvalidArgumentException(
                    'Cannot compose allOf multipleOf constraints within the platform numeric range.',
                );
            }
            $merged['multipleOf'] = $multipleOf;
        }

        return $merged;
    }

    /**
     * The generatable branch space of a `oneOf`/`anyOf` under a plan. Array
     * branches and boolean schemas both participate (booleans are valid
     * Schema Objects in OpenAPI 3.1 / JSON Schema 2020-12); branches that
     * are statically unreachable do not: `false` never matches a value, and
     * inside `oneOf` a `true` sibling means no other branch can ever be the
     * sole match. Excluded branches still take part in exclusivity
     * suppression via {@see self::applyCompositionBranch()}.
     *
     * @param list<mixed> $raw
     *
     * @return array{list<mixed>, list<int>} kept branches, enumerable indexes into them
     */
    public static function compositionBranchSpace(array $raw, string $keyword): array
    {
        $branches = array_values(array_filter(
            $raw,
            static fn(mixed $branch): bool => is_array($branch) || is_bool($branch),
        ));

        $hasTrue = in_array(true, $branches, true);
        $enumerable = [];
        foreach ($branches as $index => $branch) {
            if ($branch === false) {
                continue;
            }
            if ($keyword === 'oneOf' && $hasTrue && $branch !== true) {
                continue;
            }
            $enumerable[] = $index;
        }

        return [$branches, $enumerable];
    }

    /**
     * Materialise one branch of a planned `oneOf`/`anyOf` choice: the
     * selected branch's assertions merged in and, for `oneOf`, every sibling
     * suppressed through a single `not`/`anyOf` so the value matches exactly
     * one branch by construction. Shared with the enumerator so descent and
     * generation see identical views.
     *
     * @param array<string, mixed> $schema node with the composition keyword removed
     * @param list<mixed> $branches
     *
     * @return array<string, mixed>
     */
    public static function applyCompositionBranch(array $schema, array $branches, int $selected, string $keyword): array
    {
        $branch = $branches[$selected];
        if (is_array($branch)) {
            $schema = self::mergeSchemas($schema, $branch);
        }

        if ($keyword !== 'oneOf') {
            return $schema;
        }

        $siblings = [];
        foreach ($branches as $index => $sibling) {
            if ($index !== $selected && $sibling !== false) {
                $siblings[] = $sibling;
            }
        }
        if ($siblings !== []) {
            $schema = self::mergeSchemas($schema, ['not' => ['anyOf' => $siblings]]);
        }

        return $schema;
    }

    /**
     * The generatable sides of an array-schema `if` under a plan: side 0
     * satisfies the `if` (plus `then`), side 1 violates it (plus `else`). A
     * boolean `false` consequent makes its side unsatisfiable — nothing
     * matches `false` — so it is excluded; `true` and absent consequents
     * assert nothing and stay generatable.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<int>
     */
    public static function ifBranchSides(array $schema): array
    {
        $sides = [];
        if (($schema['then'] ?? null) !== false) {
            $sides[] = 0;
        }
        if (($schema['else'] ?? null) !== false) {
            $sides[] = 1;
        }

        return $sides;
    }

    /**
     * Materialise one side of an array-schema `if`: the condition (or its
     * negation) merged with the matching consequent. Shared with the
     * enumerator so descent and pinned generation see identical views.
     *
     * @param array<string, mixed> $schema node still carrying if/then/else
     *
     * @return array<string, mixed>
     */
    public static function applyIfSide(array $schema, int $side): array
    {
        $conditional = $side === 0
            ? self::mergeSchemas(
                is_array($schema['if']) ? $schema['if'] : [],
                is_array($schema['then'] ?? null) ? $schema['then'] : [],
            )
            : self::mergeSchemas(
                ['not' => $schema['if']],
                is_array($schema['else'] ?? null) ? $schema['else'] : [],
            );
        unset($schema['if'], $schema['then'], $schema['else']);

        return self::mergeSchemas($schema, $conditional);
    }

    /**
     * Partition conditional `allOf` branches by their boolean consequents
     * (valid Schema Objects in OpenAPI 3.1 / JSON Schema 2020-12):
     * `then: false` means the if side is unsatisfiable, so the conditional
     * is permanently suppressed (¬if and its else folded into the base);
     * `else: false` means the if must always hold (if+then folded in). Both
     * false makes the node unsatisfiable. Only the remaining conditionals
     * are genuine choices. Shared with the enumerator so both sides fold
     * identically.
     *
     * @param array<string, mixed> $base
     * @param list<array<string, mixed>> $conditionals
     *
     * @return array{array<string, mixed>, list<array<string, mixed>>, bool} folded base, choice conditionals, unsatisfiable
     */
    public static function partitionConditionals(array $base, array $conditionals): array
    {
        $choices = [];
        foreach ($conditionals as $conditional) {
            $then = $conditional['then'] ?? null;
            $else = $conditional['else'] ?? null;
            if ($then === false && $else === false) {
                return [$base, $choices, true];
            }
            if ($else === false) {
                $base = self::mergeSchemas($base, $conditional['if']);
                if (is_array($then)) {
                    $base = self::mergeSchemas($base, $then);
                }

                continue;
            }
            if ($then === false) {
                $base = self::suppressConditional($base, $conditional);

                continue;
            }
            $choices[] = $conditional;
        }

        return [$base, $choices, false];
    }

    /**
     * `$forced` tracks whether every choice on the path from the root to
     * this node was pinned by the plan or admitted no alternative — i.e.
     * whether this node's constraints are unavoidable for any value of the
     * whole case. A statically empty value domain found at a forced node is
     * a schema-derived proof the pinned view admits no value at all
     * (recorded via {@see PinnedBranchObservation::proveDeadEnd()}); the
     * same emptiness under an unpinned rotation proves nothing, because a
     * different rotation pick may avoid the node entirely.
     *
     * @param array<string, mixed> $schema
     */
    private static function generateNode(
        array $schema,
        ?Generator $faker,
        int $iteration,
        ?CaseSelectionPlan $plan,
        string $pointer,
        bool $forced,
    ): mixed {
        if ($plan !== null && ($pinnedConditional = $plan->branchFor($pointer . '/allOf')) !== null) {
            $resolved = self::resolveBranchChoice($schema, $iteration, $plan, $pointer, $forced);
            [$base, $conditionals] = self::splitConditionals($resolved);
            if ($conditionals !== []) {
                [$base, $choices, $unsatisfiable] = self::partitionConditionals($base, $conditionals);
                if (!$unsatisfiable && $choices !== []) {
                    return self::generateConditionalNode(
                        $resolved,
                        $base,
                        $choices,
                        $pinnedConditional,
                        $faker,
                        $iteration,
                        $plan,
                        $pointer,
                        $forced,
                    );
                }
                // No genuine choice remains (all folded, or the node is
                // unsatisfiable): fall through to the normal pipeline, which
                // re-partitions identically.
            }
        }

        $schema = self::resolveComposition($schema, $faker, $iteration, $plan, $pointer, $forced);

        if ($plan !== null &&
            (($schema['enum'] ?? null) === [] || ($schema['type'] ?? null) === [] || ($schema['not'] ?? null) === true)) {
            // A statically empty domain — an enum/type intersection or a
            // const conflict emptied by mergeSchemas(), or an absorbing
            // `not` — admits no value. This precedes the const shortcut:
            // the conflict marker outranks whichever const survived the
            // merge. At a forced node this is a schema-derived proof the
            // pinned view is unsatisfiable; the deterministic sentinel
            // keeps untargeted cases failing the loud self-check.
            if ($forced) {
                $plan->observation->proveDeadEnd();
            }

            return null;
        }

        $nullablePointer = $pointer . '/type';
        $pinnedNullable = $plan?->branchFor($nullablePointer);

        if (array_key_exists('const', $schema)) {
            if ($plan !== null && $forced && !SchemaValueValidator::isValid($schema['const'], $schema)) {
                // The node's domain is the single const value; if even that
                // value violates the node's own view (a suppression `not`,
                // an exclusivity constraint), the finite domain is
                // exhausted — a static proof, not a failed sample.
                $plan->observation->proveDeadEnd();
            }

            return $schema['const'];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $values = array_values($schema['enum']);
            if ($plan !== null) {
                [$handled, $plannedValue] = self::plannedEnumValue(
                    $schema,
                    $values,
                    $iteration,
                    $plan,
                    $pinnedNullable,
                    $nullablePointer,
                    $pointer,
                    $forced,
                );
                if ($handled) {
                    return $plannedValue;
                }
            }

            return $values[$iteration % count($values)];
        }

        if (is_array($schema['type'] ?? null) && in_array('null', $schema['type'], true)) {
            if ($plan !== null && $pinnedNullable !== null && $plan->targetPointer === $nullablePointer) {
                $wantNull = $pinnedNullable === SchemaChoicePoint::NULL_VALUE;
                $plan->observation->expect($pointer, static fn(mixed $value): bool
                    => ($value === null) === $wantNull);
            }
            $emitNull = $pinnedNullable !== null
                ? $pinnedNullable === SchemaChoicePoint::NULL_VALUE
                : ($iteration % 3) === 2;
            if ($emitNull) {
                return null;
            }
            if ($plan !== null && $pinnedNullable === null) {
                // Rotation chose the non-null side; null remains a way to
                // satisfy this node, so nothing below can prove a dead end.
                $forced = false;
            }
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            $withoutNot = $schema;
            unset($withoutNot['not']);
            $candidate = self::generateOne($withoutNot, $faker, $iteration, $plan, $pointer, $forced);
            if (!SchemaValueValidator::isValid($candidate, $schema)) {
                if ($plan !== null) {
                    // The `not` often excludes exactly the rotation pick — a
                    // suppressed discriminator value landing on this case's
                    // iteration. Nearby iterations re-rotate enums and
                    // scalars before giving up, so one unlucky index is not
                    // read as "unsatisfiable". Plan-less rotation keeps the
                    // historical single-shot behaviour and output.
                    for ($offset = 1; $offset <= self::NOT_GENERATION_RETRIES; $offset++) {
                        $retried = self::generateOne($withoutNot, $faker, $iteration + $offset, $plan, $pointer, $forced);
                        if (SchemaValueValidator::isValid($retried, $schema)) {
                            return $retried;
                        }
                    }
                }
                foreach ([null, false, 0, 1, '', 'value', [], ['value' => true]] as $alternative) {
                    if (SchemaValueValidator::isValid($alternative, $schema)) {
                        return $alternative;
                    }
                }
            }

            return $candidate;
        }

        $type = self::resolveType($schema);

        return match ($type) {
            'object' => self::generateObject($schema, $faker, $iteration, $plan, $pointer, $forced),
            'array' => self::generateArray($schema, $faker, $iteration, $plan, $pointer, $forced),
            'string' => self::generateString($schema, $faker, $iteration),
            'integer' => self::generateInteger($schema, $faker, $iteration),
            'number' => self::generateNumber($schema, $faker, $iteration),
            'boolean' => self::generateBoolean($iteration),
            'null' => null,
            default => null,
        };
    }

    /**
     * Restrict a finite enum domain for planned generation while leaving
     * plan-less rotation untouched.
     *
     * @param array<string, mixed> $schema
     * @param list<mixed> $values
     *
     * @return array{bool, mixed} Whether the caller should return the value.
     */
    private static function plannedEnumValue(
        array $schema,
        array $values,
        int $iteration,
        CaseSelectionPlan $plan,
        ?int $pinnedNullable,
        string $nullablePointer,
        string $pointer,
        bool $forced,
    ): array {
        // The enum domain is finite, so admissibility against the node view
        // (including any `not` pushed down by suppression) is decidable
        // outright — complete and deterministic where iteration retries
        // could miss the admissible tail of a long enum.
        $admissible = array_values(array_filter(
            $values,
            static fn(mixed $value): bool => SchemaValueValidator::isValid($value, $schema),
        ));
        if ($admissible === []) {
            // The finite domain is provably exhausted; the deterministic pick
            // keeps untargeted cases loud.
            if ($forced) {
                $plan->observation->proveDeadEnd();
            }

            return [true, $values[0]];
        }

        if ($pinnedNullable !== null) {
            $wantNull = $pinnedNullable === SchemaChoicePoint::NULL_VALUE;
            if ($plan->targetPointer === $nullablePointer) {
                $plan->observation->expect(
                    $pointer,
                    static fn(mixed $value): bool => ($value === null) === $wantNull,
                );
            }
            $partition = [];
            foreach ($admissible as $value) {
                if (($value === null) === $wantNull) {
                    $partition[] = $value;
                }
            }
            if ($partition === []) {
                if ($forced) {
                    $plan->observation->proveDeadEnd();
                }

                return [true, $values[0]];
            }

            return [true, $partition[$iteration % count($partition)]];
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            return [true, $admissible[$iteration % count($admissible)]];
        }

        return [false, null];
    }

    /**
     * Fold the suppressed side of one conditional into a schema: its else
     * applies and its if must fail — negated at the property itself for the
     * single-property shape, at the node otherwise. mergeSchemas() combines
     * `not` assertions conjunctively, so stacked suppressions accumulate.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $conditional
     *
     * @return array<string, mixed>
     */
    private static function suppressConditional(array $schema, array $conditional): array
    {
        if (isset($conditional['else']) && is_array($conditional['else'])) {
            $schema = self::mergeSchemas($schema, $conditional['else']);
        }
        $condition = self::singlePropertyCondition($conditional['if']);
        if ($condition !== null) {
            return self::mergeSchemas($schema, ['properties' => [$condition[0] => ['not' => $condition[1]]]]);
        }

        return self::mergeSchemas($schema, ['not' => $conditional['if']]);
    }

    /**
     * Recognise an `if` of the shape `{properties: {p: s}, required: [p]}`
     * with no other assertions, and return `[p, s]`; null otherwise.
     *
     * @param array<string, mixed> $if
     *
     * @return null|array{string, array<string, mixed>}
     */
    private static function singlePropertyCondition(array $if): ?array
    {
        if (array_keys($if) !== ['properties', 'required'] && array_keys($if) !== ['required', 'properties']) {
            return null;
        }
        if (!is_array($if['properties']) || count($if['properties']) !== 1) {
            return null;
        }
        $property = array_key_first($if['properties']);
        $subschema = $if['properties'][$property];
        if (!is_string($property) || !is_array($subschema)) {
            return null;
        }
        if ($if['required'] !== [$property]) {
            return null;
        }

        return [$property, $subschema];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|stdClass
     */
    private static function generateObject(
        array $schema,
        ?Generator $faker,
        int $iteration,
        ?CaseSelectionPlan $plan = null,
        string $pointer = '',
        bool $forced = true,
    ): array|stdClass {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return [];
        }

        $required = [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $name) {
                if (is_string($name)) {
                    $required[] = $name;
                }
            }
        }

        $result = [];
        $presenceTarget = null;
        foreach ($properties as $name => $propSchema) {
            if (!is_string($name)) {
                continue;
            }
            // Boolean property schemas (OpenAPI 3.1 / JSON Schema 2020-12,
            // and the empty Schema Object `{}` the converter normalises to
            // `true` — see #478): `true` admits any value and is generated
            // like any other schema, while `false` admits none, so its
            // presence is unreachable and the property is always omitted.
            // Skipping `true` here would drop a *required* property and
            // produce a body that fails its own schema.
            if ($propSchema !== true && !is_array($propSchema)) {
                continue;
            }

            $childPointer = $pointer . '/properties/' . SchemaChoicePointEnumerator::escapePointerSegment($name);
            if ($plan !== null && $plan->targetPointer === $childPointer) {
                // A presence target is decided by this object's final shape
                // — the maxProperties trim may still remove the property —
                // so it is reported once composition below has settled.
                $presenceTarget = $name;
            }
            $isRequired = in_array($name, $required, true);
            $pinnedPresence = null;
            // Optional properties alternate inclusion across cases so the
            // suite exercises both "required-only" and "required+optional"
            // shapes — mirrors Schemathesis' explore-omit toggle on a small
            // budget. Required keys are always emitted; a pinned plan entry
            // overrides the parity in either direction.
            if (!$isRequired) {
                $pinnedPresence = $plan?->branchFor($childPointer);
                $omit = $pinnedPresence !== null
                    ? $pinnedPresence === SchemaChoicePoint::OMITTED
                    : ($iteration % 2) === 0;
                if ($omit) {
                    continue;
                }
            }

            $result[$name] = self::generateOne(
                $propSchema === true ? [] : $propSchema,
                $faker,
                $iteration,
                $plan,
                $childPointer,
                // A rotation-included optional property is avoidable by
                // omission, so its subtree can prove nothing.
                $forced && ($isRequired || $pinnedPresence === SchemaChoicePoint::PRESENT),
            );
        }

        $minProperties = isset($schema['minProperties']) && is_int($schema['minProperties'])
            ? $schema['minProperties']
            : 0;
        if (count($result) < $minProperties && ($schema['additionalProperties'] ?? true) !== false) {
            while (count($result) < $minProperties) {
                $name = 'property' . count($result);
                $additionalSchema = is_array($schema['additionalProperties'] ?? null)
                    ? $schema['additionalProperties']
                    : ['type' => 'string'];
                $result[$name] = self::generateOne(
                    $additionalSchema,
                    $faker,
                    $iteration + count($result),
                    $plan,
                    $pointer . '/additionalProperties',
                    false,
                );
            }
        }
        if ($plan !== null && count($result) < $minProperties && ($schema['additionalProperties'] ?? true) === false) {
            // `additionalProperties: false` only forbids names that neither
            // `properties` nor `patternProperties` evaluates (JSON Schema
            // 2020-12 §10.3.2.3), so a minProperties shortfall on a closed
            // object can still be met with pattern-matched names. Plan-only:
            // plan-less rotation output is frozen.
            $patternProperties = $schema['patternProperties'] ?? null;
            if (is_array($patternProperties)) {
                foreach ($patternProperties as $patternKey => $patternSchema) {
                    if (count($result) >= $minProperties) {
                        break;
                    }
                    if (!is_string($patternKey) || (!is_array($patternSchema) && $patternSchema !== true)) {
                        continue;
                    }
                    $name = self::synthesizePropertyName($patternKey, $faker, $iteration);
                    // A name that collides with a declared property would
                    // override that property's own presence rotation or pin,
                    // so leave the shortfall to fail loudly instead.
                    if ($name === null || array_key_exists($name, $result) || isset($properties[$name])) {
                        continue;
                    }
                    $result[$name] = self::generateOne(
                        $patternSchema === true ? [] : $patternSchema,
                        $faker,
                        $iteration + count($result),
                        $plan,
                        $pointer . '/patternProperties/' . SchemaChoicePointEnumerator::escapePointerSegment($patternKey),
                        false,
                    );
                }
            }
        }
        $maxProperties = isset($schema['maxProperties']) && is_int($schema['maxProperties'])
            ? $schema['maxProperties']
            : null;
        if ($maxProperties !== null && ($iteration % 3) === 1 && ($schema['additionalProperties'] ?? true) !== false) {
            while (count($result) < $maxProperties) {
                $name = 'property' . count($result);
                $additionalSchema = is_array($schema['additionalProperties'] ?? null)
                    ? $schema['additionalProperties']
                    : ['type' => 'string'];
                $result[$name] = self::generateOne(
                    $additionalSchema,
                    $faker,
                    $iteration + count($result),
                    $plan,
                    $pointer . '/additionalProperties',
                    false,
                );
            }
        }
        if ($maxProperties !== null && count($result) > $maxProperties) {
            // Trim unpinned optional properties first so a plan that forces
            // a property present keeps it through the maxProperties budget;
            // pinned ones go only when nothing else is left to drop.
            foreach ([true, false] as $sparePinned) {
                foreach (array_keys($result) as $name) {
                    if (count($result) <= $maxProperties) {
                        break 2;
                    }
                    if (in_array($name, $required, true)) {
                        continue;
                    }
                    if ($sparePinned && $plan?->branchFor(
                        $pointer . '/properties/' . SchemaChoicePointEnumerator::escapePointerSegment($name),
                    ) === SchemaChoicePoint::PRESENT) {
                        continue;
                    }
                    unset($result[$name]);
                }
            }
        }
        if ($presenceTarget !== null && $plan !== null) {
            $present = array_key_exists($presenceTarget, $result);
            $plan->observation->report(
                $plan->targetBranch === SchemaChoicePoint::OMITTED ? !$present : $present,
            );
            if ($forced) {
                // Static presence arithmetic — schema-derived proofs that
                // the pinned state is impossible, independent of what was
                // generated. The merged view unions `properties` and
                // `required` and maximises `minProperties`, so both counts
                // only over-approximate what the real conjunction admits.
                $includableOthers = 0;
                foreach ($properties as $name => $propSchema) {
                    if (is_string($name) && $name !== $presenceTarget && $propSchema !== false) {
                        $includableOthers++;
                    }
                }
                if ($plan->targetBranch === SchemaChoicePoint::OMITTED) {
                    // Without the target property, at most the other named
                    // properties can exist when additionalProperties is
                    // false; fewer than minProperties means omission can
                    // never satisfy the object. The count argument breaks
                    // down when any patternProperties entry admits values —
                    // additionalProperties only governs names that neither
                    // keyword evaluates — so a pattern-open object proves
                    // nothing.
                    $patternsAdmitNames = false;
                    $patternProperties = $schema['patternProperties'] ?? null;
                    if (is_array($patternProperties) && $patternProperties !== []) {
                        foreach ($patternProperties as $patternSchema) {
                            if ($patternSchema !== false) {
                                $patternsAdmitNames = true;

                                break;
                            }
                        }
                    } elseif ($patternProperties !== null) {
                        // Malformed node: stay conservative, no proof.
                        $patternsAdmitNames = true;
                    }
                    if (($schema['additionalProperties'] ?? true) === false &&
                        !$patternsAdmitNames &&
                        $includableOthers < $minProperties) {
                        $plan->observation->proveDeadEnd();
                    }
                } elseif ($maxProperties !== null &&
                    !in_array($presenceTarget, $required, true) &&
                    count($required) + 1 > $maxProperties) {
                    // Every valid object carries all required properties;
                    // adding the target on top provably exceeds the budget.
                    $plan->observation->proveDeadEnd();
                }
            }
        }
        if ($result === []) {
            if ($maxProperties === 0 || ($schema['additionalProperties'] ?? true) === false) {
                return new stdClass();
            }
            $result['property0'] = 'value';
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<mixed>
     */
    private static function generateArray(
        array $schema,
        ?Generator $faker,
        int $iteration,
        ?CaseSelectionPlan $plan = null,
        string $pointer = '',
        bool $forced = true,
    ): array {
        // Item subtrees never prove dead ends ($forced is deliberately not
        // forwarded below): element count and content interact with
        // minItems/maxItems/uniqueItems in ways the proof sites do not
        // model, so stay conservative and loud.
        unset($forced);
        // A pinned plan entry at `<pointer>/items` is a forced minimum size:
        // it makes items reachable in the pinned case (mirroring how optional
        // ancestors are forced present) without becoming a rotation strategy.
        $forcedMinimum = $plan?->branchFor($pointer . '/items');

        $prefixItems = $schema['prefixItems'] ?? null;
        if (is_array($prefixItems)) {
            $prefixCount = count($prefixItems);
            $minimum = isset($schema['minItems']) && is_int($schema['minItems']) ? max(0, $schema['minItems']) : 0;
            $maximum = isset($schema['maxItems']) && is_int($schema['maxItems']) ? max(0, $schema['maxItems']) : null;
            $size = match ($iteration % 3) {
                0 => $minimum,
                1 => $maximum ?? $prefixCount,
                default => $prefixCount,
            };
            if ($forcedMinimum !== null) {
                $size = max($size, $forcedMinimum);
            }
            if ($maximum !== null) {
                $size = min($size, $maximum);
            }
            if (($schema['items'] ?? true) === false) {
                $size = min($size, $prefixCount);
            }
            if ($plan !== null) {
                // A `false` prefix item admits no value, so every array long
                // enough to contain it is invalid: the first one is an
                // effective maxItems, overriding even a forced size.
                foreach (array_values($prefixItems) as $prefixIndex => $prefixItem) {
                    if ($prefixItem === false) {
                        $size = min($size, $prefixIndex);
                        break;
                    }
                }
            }
            $result = [];
            for ($index = 0; $index < $size; $index++) {
                $item = $prefixItems[$index] ?? ($schema['items'] ?? []);
                $itemPointer = isset($prefixItems[$index])
                    ? $pointer . '/prefixItems/' . $index
                    : $pointer . '/items';
                if (is_array($item)) {
                    $result[] = self::generateOne($item, $faker, $iteration + $index, $plan, $itemPointer, false);
                } else {
                    $result[] = 'item-' . $index;
                }
            }

            return $result;
        }

        $items = $schema['items'] ?? null;
        if ($plan !== null && ($items === true || $items === null)) {
            // A boolean-true items schema admits any value, and an omitted
            // items keyword is the empty schema (2020-12 §10.3.1.2) — same
            // assertion behaviour. Generate from the empty schema instead of
            // degrading to an empty array that would violate minItems.
            $items = [];
        }
        if (!is_array($items)) {
            return [];
        }

        $minimum = isset($schema['minItems']) && is_int($schema['minItems']) ? max(0, $schema['minItems']) : 1;
        $maximum = isset($schema['maxItems']) && is_int($schema['maxItems']) ? max(0, $schema['maxItems']) : null;
        $size = match ($iteration % 3) {
            0 => $minimum,
            1 => $maximum ?? max(1, $minimum),
            default => max(1, $minimum),
        };
        if ($forcedMinimum !== null) {
            $size = max($size, $forcedMinimum);
        }
        if ($maximum !== null) {
            $size = min($size, $maximum);
        }
        $itemPointer = $pointer . '/items';
        $result = [];
        for ($i = 0; $i < $size; $i++) {
            $item = self::generateOne($items, $faker, $iteration + $i, $plan, $itemPointer, false);
            if (($schema['uniqueItems'] ?? false) === true) {
                $attempt = 0;
                while (in_array($item, $result, true) && $attempt < 100) {
                    $attempt++;
                    $item = self::generateOne($items, $faker, $iteration + $i + $attempt, $plan, $itemPointer, false);
                }
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateString(array $schema, ?Generator $faker, int $iteration): string
    {
        $format = isset($schema['format']) && is_string($schema['format']) ? $schema['format'] : null;
        if ($faker !== null && $format !== null) {
            $formatted = self::generateStringByFormat($faker, $format);
            if ($formatted !== null) {
                return self::clampLength($formatted, $schema);
            }
        }

        if ($faker === null && $format !== null && self::isSupportedFormat($format)) {
            // Without faker, format-constrained strings (`email`, `uuid`, …)
            // degrade to the deterministic primitive fallback below — which
            // will not satisfy the format constraint and every fuzzed request
            // will fail at validation. Surface this once per process so the
            // user can install fakerphp/faker, instead of letting the test
            // appear "unstable" with no diagnostic.
            self::warnFakerMissing($format);
        }

        $minLength = isset($schema['minLength']) && is_int($schema['minLength']) && $schema['minLength'] >= 0
            ? $schema['minLength']
            : 1;
        $maxLength = isset($schema['maxLength']) && is_int($schema['maxLength']) && $schema['maxLength'] >= 0
            ? $schema['maxLength']
            : 16;

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $patternValue = self::generateCommonPattern($schema['pattern'], $schema, $faker, $iteration);
            if ($patternValue !== null) {
                return $patternValue;
            }

            throw new InvalidArgumentException(sprintf(
                "String pattern '%s' is outside the fuzz generator's supported synthesis subset.",
                $schema['pattern'],
            ));
        }

        if ($faker !== null) {
            // bothify('?') yields random alpha sized to the chosen target.
            // Target is always >= 1 because $maxLength is constrained > 0
            // above and min($maxLength, 8) >= 1. clampLength still runs to
            // honor `minLength > 8` (where the target was capped at 8) and
            // to defensively pad when bothify ever returns a short result.
            $target = match ($iteration % 3) {
                0 => $minLength,
                1 => $maxLength,
                default => max($minLength, min($maxLength, 8)),
            };
            $generated = $faker->bothify(str_repeat('?', $target));

            return self::clampLength($generated, $schema);
        }

        $base = match ($iteration % 3) {
            0 => str_repeat('x', $minLength),
            1 => str_repeat('x', $maxLength),
            default => 'string-' . $iteration,
        };

        return self::clampLength($base, $schema);
    }

    private static function generateStringByFormat(Generator $faker, string $format): ?string
    {
        return match ($format) {
            'email', 'idn-email' => $faker->safeEmail(),
            'uuid' => $faker->uuid(),
            'date' => $faker->date(),
            'date-time' => $faker->iso8601(),
            'time' => $faker->time(),
            'uri', 'url', 'iri' => $faker->url(),
            'hostname' => $faker->domainName(),
            'ipv4' => $faker->ipv4(),
            'ipv6' => $faker->ipv6(),
            default => null,
        };
    }

    /**
     * Mirrors the format keys that {@see self::generateStringByFormat()}
     * actually handles. Listing them here lets the faker-missing warning
     * fire only for cases where faker would have helped — exotic formats
     * (e.g. `byte`, `binary`, `password`) stay silent because the
     * deterministic fallback wasn't going to satisfy them either way.
     */
    private static function isSupportedFormat(string $format): bool
    {
        return in_array(
            $format,
            ['email', 'idn-email', 'uuid', 'date', 'date-time', 'time', 'uri', 'url', 'iri', 'hostname', 'ipv4', 'ipv6'],
            true,
        );
    }

    /**
     * Emit a one-shot warning per missing format. We dedupe by `$format` so
     * a long fuzz run with many email fields still only nags once per format
     * — matching how the rest of the library uses E_USER_WARNING for
     * spec-author advisories (see OpenApiCoverageTracker::warnMalformed()).
     */
    private static function warnFakerMissing(string $format): void
    {
        if (isset(self::$warnedFakerFormats[$format])) {
            return;
        }
        self::$warnedFakerFormats[$format] = true;

        trigger_error(
            sprintf(
                '[Gesso] fakerphp/faker is not installed; '
                . "string format '%s' will be generated as a deterministic primitive "
                . 'and is unlikely to satisfy the spec constraint. '
                . 'Install via: composer require --dev fakerphp/faker',
                $format,
            ),
            E_USER_WARNING,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function clampLength(string $value, array $schema): string
    {
        $minLength = isset($schema['minLength']) && is_int($schema['minLength']) && $schema['minLength'] >= 0
            ? $schema['minLength']
            : 0;
        $maxLength = isset($schema['maxLength']) && is_int($schema['maxLength']) && $schema['maxLength'] >= 0
            ? $schema['maxLength']
            : null;

        if ($maxLength !== null && self::unicodeLength($value) > $maxLength) {
            $characters = self::unicodeCharacters($value);
            $value = implode('', array_slice($characters, 0, $maxLength));
        }
        if (self::unicodeLength($value) < $minLength) {
            $value .= str_repeat('x', $minLength - self::unicodeLength($value));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateInteger(array $schema, ?Generator $faker, int $iteration): int
    {
        // Resolve bounds in three modes so a one-sided constraint never produces
        // out-of-range values: when only `maximum: 0` is set, anchoring `min`
        // to a static 1 would silently emit 1 every time. Anchor relative to
        // the supplied bound instead and only fall back to flat defaults when
        // both ends are unspecified.
        $minSet = isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']));
        $maxSet = isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']));

        $exclusiveMin = isset($schema['exclusiveMinimum']) && (is_int($schema['exclusiveMinimum']) || is_float($schema['exclusiveMinimum']))
            ? (int) floor($schema['exclusiveMinimum']) + 1
            : null;
        $exclusiveMax = isset($schema['exclusiveMaximum']) && (is_int($schema['exclusiveMaximum']) || is_float($schema['exclusiveMaximum']))
            ? (int) ceil($schema['exclusiveMaximum']) - 1
            : null;

        if ($minSet && $maxSet) {
            $min = (int) ceil($schema['minimum']);
            $max = (int) floor($schema['maximum']);
        } elseif ($minSet) {
            $min = (int) ceil($schema['minimum']);
            $max = $min + 1000;
        } elseif ($maxSet) {
            $max = (int) floor($schema['maximum']);
            $min = $max - 1000;
        } else {
            $min = 1;
            $max = 1000;
        }

        $min = $exclusiveMin !== null ? max($min, $exclusiveMin) : $min;
        $max = $exclusiveMax !== null ? min($max, $exclusiveMax) : $max;

        $multipleOf = isset($schema['multipleOf']) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))
            ? DecimalMultiple::integerStep($schema['multipleOf'])
            : 0;
        if ($multipleOf !== null && $multipleOf > 0) {
            $min = (int) (ceil($min / $multipleOf) * $multipleOf);
            $max = (int) (floor($max / $multipleOf) * $multipleOf);
        }

        if ($max < $min) {
            // Spec inversion (e.g. min=10, max=5). Honor `min` since it is the
            // tighter constraint for "this value must exist at all"; the tests
            // that pass a contradictory schema are expected to fail validation
            // downstream — we just refuse to amplify the contradiction.
            $max = $min;
        }

        if (($iteration % 3) === 0) {
            return $min;
        }
        if (($iteration % 3) === 1) {
            return $max;
        }
        if ($faker !== null) {
            if ($multipleOf !== null && $multipleOf > 0) {
                return $faker->numberBetween(intdiv($min, $multipleOf), intdiv($max, $multipleOf)) * $multipleOf;
            }

            return $faker->numberBetween($min, $max);
        }

        if ($multipleOf !== null && $multipleOf > 0) {
            $span = intdiv($max - $min, $multipleOf) + 1;

            return $min + ($iteration % max(1, $span)) * $multipleOf;
        }

        $span = $max - $min + 1;

        return $min + ($iteration % max(1, $span));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateNumber(array $schema, ?Generator $faker, int $iteration): float
    {
        $minSet = isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']));
        $maxSet = isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']));

        $exclusiveMin = isset($schema['exclusiveMinimum']) && (is_int($schema['exclusiveMinimum']) || is_float($schema['exclusiveMinimum']))
            ? (float) $schema['exclusiveMinimum']
            : null;
        $exclusiveMax = isset($schema['exclusiveMaximum']) && (is_int($schema['exclusiveMaximum']) || is_float($schema['exclusiveMaximum']))
            ? (float) $schema['exclusiveMaximum']
            : null;

        if ($minSet && $maxSet) {
            $min = (float) $schema['minimum'];
            $max = (float) $schema['maximum'];
        } elseif ($minSet) {
            $min = (float) $schema['minimum'];
            $max = $min + 1000.0;
        } elseif ($maxSet) {
            $max = (float) $schema['maximum'];
            $min = $max - 1000.0;
        } else {
            $min = 0.0;
            $max = 1000.0;
        }

        $epsilon = isset($schema['multipleOf']) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))
            ? (float) $schema['multipleOf']
            : max(PHP_FLOAT_EPSILON, ($max - $min) / 1000000.0);
        $min = $exclusiveMin !== null ? max($min, $exclusiveMin + $epsilon) : $min;
        $max = $exclusiveMax !== null ? min($max, $exclusiveMax - $epsilon) : $max;

        $multipleOf = isset($schema['multipleOf']) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))
            ? (float) $schema['multipleOf']
            : 0.0;
        if ($multipleOf > 0.0) {
            $min = round(ceil($min / $multipleOf) * $multipleOf, 12);
            $max = round(floor($max / $multipleOf) * $multipleOf, 12);
        }

        if ($max < $min) {
            $max = $min;
        }

        if (($iteration % 3) === 0) {
            return $min;
        }
        if (($iteration % 3) === 1) {
            return $max;
        }
        if ($faker !== null) {
            if ($multipleOf > 0.0) {
                $minimumMultiplier = (int) ceil($min / $multipleOf);
                $maximumMultiplier = (int) floor($max / $multipleOf);

                return round($faker->numberBetween($minimumMultiplier, $maximumMultiplier) * $multipleOf, 12);
            }

            // randomFloat(null, …) lets faker pick precision dynamically so
            // tight ranges (e.g. minimum=0.001 maximum=0.002) don't collapse
            // to 0.00 from a fixed two-decimal rounding.
            return $faker->randomFloat(null, $min, $max);
        }

        if ($multipleOf > 0.0) {
            $minimumMultiplier = (int) ceil($min / $multipleOf);
            $maximumMultiplier = (int) floor($max / $multipleOf);
            $span = $maximumMultiplier - $minimumMultiplier + 1;

            return round(($minimumMultiplier + $iteration % max(1, $span)) * $multipleOf, 12);
        }

        // Scale the iteration-driven offset to the actual span so the value
        // never escapes [min, max] — using a fixed `iter / 10` step would
        // exit the range whenever the span < 10.
        $span = $max - $min;
        if ($span <= 0.0) {
            return $min;
        }

        return $min + ($iteration % 100) / 100.0 * $span;
    }

    private static function generateBoolean(int $iteration): bool
    {
        return ($iteration % 2) === 1;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function resolveComposition(
        array $schema,
        ?Generator $faker,
        int $iteration,
        ?CaseSelectionPlan $plan = null,
        string $pointer = '',
        bool &$forced = true,
    ): array {
        $schema = self::resolveBranchChoice($schema, $iteration, $plan, $pointer, $forced);

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            [$schema, $conditionals] = self::splitConditionals($schema);
            if ($conditionals !== [] && $plan !== null) {
                // Boolean consequents leave no choice; fold them into the
                // node first. An unsatisfiable node generates from the base
                // and fails the self-check loudly.
                [$schema, $conditionals, $unsatisfiable] = self::partitionConditionals($schema, $conditionals);
                if ($unsatisfiable) {
                    $conditionals = [];
                }
            }
            if ($conditionals !== []) {
                // Rotation only ever satisfies a conditional's `if`+`then`;
                // pinned conditional branches never reach this point — they
                // are resolved by generateConditionalNode() before the
                // composition phases run.
                $selected = $conditionals[$iteration % count($conditionals)];
                $schema = self::mergeSchemas($schema, $selected['if']);
                if (isset($selected['then']) && is_array($selected['then'])) {
                    $schema = self::mergeSchemas($schema, $selected['then']);
                }
                // An unpinned conditional state is a choice: another
                // rotation may satisfy a different conditional (or none),
                // so nothing merged here can prove a dead end.
                $forced = false;
            }
        }

        if ($plan !== null && is_bool($schema['if'] ?? null)) {
            // Boolean Schema Objects (OpenAPI 3.1 / JSON Schema 2020-12):
            // `if: true` makes the then unconditional, `if: false` the else
            // — there is no branch to choose. Plan-less rotation keeps its
            // historical output and ignores boolean ifs.
            $branchSchema = $schema['if'] === true ? ($schema['then'] ?? null) : ($schema['else'] ?? null);
            unset($schema['if'], $schema['then'], $schema['else']);
            if (is_array($branchSchema)) {
                $schema = self::mergeSchemas($schema, $branchSchema);
            }
        } elseif (isset($schema['if']) && is_array($schema['if'])) {
            if ($plan !== null) {
                $sides = self::ifBranchSides($schema);
                // No generatable side means both consequents are `false`;
                // leave the keyword unresolved for the loud self-check.
                if ($sides !== []) {
                    $pinned = $plan->branchFor($pointer . '/if');
                    $side = $sides[($pinned ?? $iteration) % count($sides)];
                    if ($pinned === null && count($sides) > 1) {
                        // An unpinned side is a choice; the other side may
                        // avoid whatever this merge makes unsatisfiable.
                        $forced = false;
                    }
                    if ($plan->targetPointer === $pointer . '/if' && $pinned !== null) {
                        // An else-targeted value that fires the `if` anyway
                        // passes the whole schema through the then side; only
                        // the condition itself tells the sides apart.
                        $condition = $schema['if'];
                        $plan->observation->expect($pointer, static fn(mixed $value): bool
                            => SchemaValueValidator::isValid($value, $condition) === ($side === 0));
                    }
                    $schema = self::applyIfSide($schema, $side);
                }
            } else {
                $useThen = ($iteration % 2) === 0;
                $conditional = $useThen
                    ? self::mergeSchemas($schema['if'], is_array($schema['then'] ?? null) ? $schema['then'] : [])
                    : self::mergeSchemas(
                        ['not' => $schema['if']],
                        is_array($schema['else'] ?? null) ? $schema['else'] : [],
                    );
                unset($schema['if'], $schema['then'], $schema['else']);
                $schema = self::mergeSchemas($schema, $conditional);
            }
        }

        if ($plan !== null && $plan->refineTarget) {
            $refinement = $plan->observation->takeRefinement();
            if ($refinement !== null) {
                // Refinement attempt of the target search: non-conjunctive
                // keywords (pattern, format) displaced by a later merge are
                // restored by re-merging the target branch content last.
                // Conjunctive keywords are idempotent under the re-merge,
                // and the whole-schema check plus the branch observation
                // still gate whatever this view generates.
                $schema = self::mergeSchemas($schema, $refinement);
            }
        }

        return $schema;
    }

    /**
     * Resolve the first `oneOf`/`anyOf` on the node — composition phase one.
     * Idempotent: the resolved keyword is consumed, so a second call is a
     * no-op, which lets the pinned-conditional path pre-resolve it before
     * splitting conditionals out of `allOf`.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function resolveBranchChoice(
        array $schema,
        int $iteration,
        ?CaseSelectionPlan $plan,
        string $pointer,
        bool &$forced = true,
    ): array {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword]) || $schema[$keyword] === []) {
                continue;
            }

            if ($plan === null) {
                // Documented rotation: array branches only, no exclusivity
                // suppression, historical output bit-for-bit.
                $branches = array_values(array_filter($schema[$keyword], is_array(...)));
                if ($branches === []) {
                    continue;
                }
                unset($schema[$keyword]);
                $schema = self::mergeSchemas($schema, $branches[$iteration % count($branches)]);
                break;
            }

            [$branches, $enumerable] = self::compositionBranchSpace(array_values($schema[$keyword]), $keyword);
            if ($enumerable === []) {
                continue;
            }
            unset($schema[$keyword]);
            $pinned = $plan->branchFor($pointer . '/' . $keyword);
            $selected = $enumerable[($pinned ?? $iteration) % count($enumerable)];
            if ($pinned === null && count($enumerable) > 1) {
                // An unpinned branch pick is a choice; emptiness under this
                // branch says nothing about its siblings.
                $forced = false;
            }
            if ($plan->targetPointer === $pointer . '/' . $keyword && $pinned !== null) {
                // Whole-schema validity cannot tell this branch from a
                // sibling that also matches; the case counts as covering
                // the target only if the value satisfies the branch itself.
                $branch = $branches[$selected];
                $plan->observation->expect($pointer, static fn(mixed $value): bool
                    => $branch === true || (is_array($branch) && SchemaValueValidator::isValid($value, $branch)));
                if ($plan->refineTarget && is_array($branch)) {
                    $plan->observation->stageRefinement($branch);
                }
            }
            $schema = self::applyCompositionBranch($schema, $branches, $selected, $keyword);
            break;
        }

        return $schema;
    }

    /**
     * Split a node's `allOf` into the node with every non-conditional branch
     * merged in, and the list of conditional (`if`-carrying) branches —
     * mirrored by the enumerator's traversal.
     *
     * @param array<string, mixed> $schema
     *
     * @return array{array<string, mixed>, list<array<string, mixed>>}
     */
    private static function splitConditionals(array $schema): array
    {
        if (!isset($schema['allOf']) || !is_array($schema['allOf'])) {
            return [$schema, []];
        }

        $base = $schema;
        unset($base['allOf']);
        $conditionals = [];
        foreach ($schema['allOf'] as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            if (isset($branch['if']) && is_array($branch['if'])) {
                $conditionals[] = $branch;
            } else {
                $base = self::mergeSchemas($base, $branch);
            }
        }

        return [$base, $conditionals];
    }

    /**
     * Generate a node whose conditional-`allOf` choice is pinned by the plan.
     *
     * Starts from the pinned branch's view — the selected conditional (or
     * none) satisfied, all others suppressed — and validates the produced
     * value against the complete node schema. Suppression is a starting
     * point, not an assumption of exclusivity: when a suppressed `if` fires
     * on the value anyway (overlapping conditionals), that conditional joins
     * the satisfied set and the node regenerates, up to once per
     * conditional. The none-match branch never grows the set — reaching a
     * state where no `if` fires is exactly its target.
     *
     * @param array<string, mixed> $node phase-one-resolved node, conditionals still in `allOf`
     * @param array<string, mixed> $base node with `allOf` removed and non-conditional branches merged
     * @param list<array<string, mixed>> $conditionals
     */
    private static function generateConditionalNode(
        array $node,
        array $base,
        array $conditionals,
        int $pinned,
        ?Generator $faker,
        int $iteration,
        CaseSelectionPlan $plan,
        string $pointer,
        bool $forced,
    ): mixed {
        $count = count($conditionals);
        $branch = $pinned % ($count + 1);
        $satisfied = $branch < $count ? [$branch] : [];

        if ($plan->targetPointer === $pointer . '/allOf') {
            // Branch c is covered only if its `if` actually fires on the
            // value; the none-match state only if no choice `if` does. A
            // value can pass the whole node either way (e.g. a node const
            // that satisfies a suppressed condition), so the state must be
            // checked against the conditions themselves.
            $plan->observation->expect($pointer, static function (mixed $value) use ($conditionals, $branch, $count): bool {
                if ($branch < $count) {
                    return SchemaValueValidator::isValid($value, $conditionals[$branch]['if']);
                }
                foreach ($conditionals as $conditional) {
                    if (SchemaValueValidator::isValid($value, $conditional['if'])) {
                        return false;
                    }
                }

                return true;
            });
        }

        $value = null;
        for ($attempt = 0; $attempt <= $count; $attempt++) {
            $view = self::conditionalSetView($base, $conditionals, $satisfied);
            // Only the pinned starting view is forced: closure expansion is
            // driven by the generated value, so an expanded satisfied set is
            // one of several reachable states and proves nothing.
            $value = self::generateOne($view, $faker, $iteration, $plan, $pointer, $forced && $attempt === 0);
            if ($branch === $count || SchemaValueValidator::isValid($value, $node)) {
                return $value;
            }

            $fired = false;
            foreach ($conditionals as $index => $conditional) {
                if (!in_array($index, $satisfied, true) &&
                    SchemaValueValidator::isValid($value, $conditional['if'])) {
                    $satisfied[] = $index;
                    $fired = true;
                }
            }
            if (!$fired) {
                break;
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private static function mergePropertySchemas(array $left, array $right): array
    {
        $merged = $left;
        foreach ($right as $name => $rightSchema) {
            $leftSchema = $merged[$name] ?? null;
            $merged[$name] = is_array($leftSchema) && is_array($rightSchema)
                ? self::mergeSchemas($leftSchema, $rightSchema)
                : $rightSchema;
        }

        return $merged;
    }

    /**
     * Synthesize a property name matching a `patternProperties` pattern.
     *
     * Anchored literal patterns (`^name$`, no metacharacters) are read off
     * directly; anything else goes through the common pattern synthesis
     * subset with a final match check, since those synthesizers target
     * value patterns. Null means the pattern is outside both — the caller
     * leaves the shortfall unmet and generation fails loudly downstream.
     */
    private static function synthesizePropertyName(string $pattern, ?Generator $faker, int $iteration): ?string
    {
        if (preg_match('/^\^(.+)\$$/D', $pattern, $matches) === 1 && preg_quote($matches[1], '~') === $matches[1]) {
            return $matches[1];
        }

        $name = self::generateCommonPattern($pattern, [], $faker, $iteration);
        if ($name === null) {
            return null;
        }
        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);

        return @preg_match($delimiter . $escaped . $delimiter . 'u', $name) === 1 ? $name : null;
    }

    /** @param array<string, mixed> $schema */
    private static function generateCommonPattern(
        string $pattern,
        array $schema,
        ?Generator $faker,
        int $iteration,
    ): ?string {
        $fixedQuantifierValue = self::generateCharacterClassPattern($pattern, $schema, $faker, $iteration);
        if ($fixedQuantifierValue !== null) {
            return $fixedQuantifierValue;
        }

        $phoneNumberValue = self::generatePhoneNumberPattern($pattern, $schema);
        if ($phoneNumberValue !== null) {
            return $phoneNumberValue;
        }

        $hostnameValue = self::generateHostnamePattern($pattern, $schema);
        if ($hostnameValue !== null) {
            return $hostnameValue;
        }

        $candidates = ['a', 'A', '0', 'abc', 'ABC', '123', 'test-' . $iteration, 'é', '日本語'];
        $minimum = isset($schema['minLength']) && is_int($schema['minLength']) ? max(0, $schema['minLength']) : null;
        $maximum = isset($schema['maxLength']) && is_int($schema['maxLength']) ? max(0, $schema['maxLength']) : null;
        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);
        foreach ($candidates as $candidate) {
            $candidateLength = self::unicodeLength($candidate);
            $targets = array_values(array_unique(array_filter(
                [$minimum, $maximum, $candidateLength],
                static fn(?int $length): bool => $length !== null,
            )));
            foreach ($targets as $target) {
                if ($maximum !== null && $target > $maximum) {
                    continue;
                }
                $value = self::repeatToLength($candidate, $target);
                if (($minimum === null || self::unicodeLength($value) >= $minimum) &&
                    @preg_match($delimiter . $escaped . $delimiter . 'u', $value) === 1) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $schema */
    private static function generatePhoneNumberPattern(string $pattern, array $schema): ?string
    {
        $prefix = '^(\\d{';
        $suffix = '}|(?=[\\d-]{12,13}$)\\d{2,4}-\\d{2,4}-\\d{3,4})$';
        if (!str_starts_with($pattern, $prefix) || !str_ends_with($pattern, $suffix)) {
            return null;
        }

        $quantifier = substr($pattern, strlen($prefix), -strlen($suffix));
        if (preg_match('/^([0-9]+),([0-9]+)$/D', $quantifier, $matches) !== 1) {
            return null;
        }

        $digitMinimum = (int) $matches[1];
        $digitMaximum = (int) $matches[2];
        if ($digitMinimum < 1 || $digitMaximum < $digitMinimum ||
            $digitMinimum > self::MAX_SYNTHESIZED_PATTERN_LENGTH) {
            return null;
        }

        $minimum = isset($schema['minLength']) && is_int($schema['minLength']) ? max(0, $schema['minLength']) : null;
        $maximum = isset($schema['maxLength']) && is_int($schema['maxLength']) ? max(0, $schema['maxLength']) : null;
        $candidates = [];
        $digitLength = max($digitMinimum, $minimum ?? 0);
        if ($digitLength <= $digitMaximum &&
            $digitLength <= self::MAX_SYNTHESIZED_PATTERN_LENGTH &&
            ($maximum === null || $digitLength <= $maximum)) {
            $candidates[] = str_repeat('0', $digitLength);
        }
        $candidates[] = '000-000-0000';
        $candidates[] = '0000-000-0000';

        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);
        foreach ($candidates as $candidate) {
            $length = self::unicodeLength($candidate);
            if (($minimum !== null && $length < $minimum) ||
                ($maximum !== null && $length > $maximum)) {
                continue;
            }
            if (@preg_match($delimiter . $escaped . $delimiter . 'u', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $schema */
    private static function generateHostnamePattern(string $pattern, array $schema): ?string
    {
        $labelPrefix = '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+';
        if (!str_starts_with($pattern, $labelPrefix) || !str_ends_with($pattern, '$')) {
            return null;
        }

        $escapedSuffix = substr($pattern, strlen($labelPrefix), -1);
        $suffix = str_replace('\\.', '.', $escapedSuffix);
        if (preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/Di', $suffix) !== 1) {
            return null;
        }

        $minimum = isset($schema['minLength']) && is_int($schema['minLength']) ? max(0, $schema['minLength']) : null;
        $maximum = isset($schema['maxLength']) && is_int($schema['maxLength']) ? max(0, $schema['maxLength']) : null;
        $suffixLength = self::unicodeLength($suffix);
        $prefixLength = max(2, ($minimum ?? 0) - $suffixLength);
        if ($prefixLength + $suffixLength > self::MAX_SYNTHESIZED_PATTERN_LENGTH ||
            ($maximum !== null && $prefixLength + $suffixLength > $maximum)) {
            return null;
        }

        $labelCount = (int) ceil($prefixLength / 64);
        $remainingCharacters = $prefixLength - $labelCount;
        $labels = [];
        for ($index = 0; $index < $labelCount; $index++) {
            $remainingLabels = $labelCount - $index - 1;
            $labelLength = min(63, $remainingCharacters - $remainingLabels);
            $labels[] = str_repeat('a', $labelLength);
            $remainingCharacters -= $labelLength;
        }

        $value = implode('.', $labels) . '.' . $suffix;
        $length = self::unicodeLength($value);
        if ($length > self::MAX_SYNTHESIZED_PATTERN_LENGTH || ($maximum !== null && $length > $maximum)) {
            return null;
        }

        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);

        return @preg_match($delimiter . $escaped . $delimiter . 'u', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $schema */
    private static function generateCharacterClassPattern(
        string $pattern,
        array $schema,
        ?Generator $faker,
        int $iteration,
    ): ?string {
        if (preg_match('/^\^(\[[^]]+]|\\\\d)(?:\{([0-9]+)\}|(\+))\$$/D', $pattern, $matches) !== 1) {
            return null;
        }

        $minimum = isset($schema['minLength']) && is_int($schema['minLength']) ? max(0, $schema['minLength']) : null;
        $maximum = isset($schema['maxLength']) && is_int($schema['maxLength']) ? max(0, $schema['maxLength']) : null;
        $length = $matches[2] !== ''
            ? (int) $matches[2]
            : match ($iteration % 3) {
                0 => max(1, $minimum ?? 1),
                1 => max(1, $maximum ?? 16),
                default => max(1, $minimum ?? 1, min($maximum ?? 16, 8)),
            };
        if ($length > self::MAX_SYNTHESIZED_PATTERN_LENGTH ||
            ($minimum !== null && $length < $minimum) ||
            ($maximum !== null && $length > $maximum)) {
            return null;
        }

        $atom = $matches[1];
        $characters = $atom === '\\d'
            ? self::unicodeCharacters('0123456789')
            : self::charactersMatchingClass($atom);
        if ($characters === []) {
            return null;
        }

        $value = self::samplePatternCharacters($characters, $length, $faker, $iteration);
        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);

        return @preg_match($delimiter . $escaped . $delimiter . 'u', $value) === 1 ? $value : null;
    }

    /** @return list<string> */
    private static function charactersMatchingClass(string $characterClass): array
    {
        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $characterClass);
        $expression = $delimiter . '^' . $escaped . '$' . $delimiter . 'u';
        $candidates = array_values(array_unique(self::unicodeCharacters(
            'aA0abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_- ',
        )));
        $matches = [];

        foreach ($candidates as $candidate) {
            if (@preg_match($expression, $candidate) === 1) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }

    /** @param non-empty-list<string> $characters */
    private static function samplePatternCharacters(
        array $characters,
        int $length,
        ?Generator $faker,
        int $iteration,
    ): string {
        if ($iteration === 0 || count($characters) === 1) {
            return str_repeat($characters[0], $length);
        }

        $sampled = [];
        for ($position = 0; $position < $length; $position++) {
            $index = $faker !== null
                ? $faker->numberBetween(0, count($characters) - 1)
                : ($iteration + $position) % count($characters);
            // Reserve the first candidate for iteration zero so even a
            // one-character value differs after the boundary case.
            if ($position === 0 && $index === 0) {
                $index = 1 + (($iteration - 1) % (count($characters) - 1));
            }
            $sampled[] = $characters[$index];
        }

        if ($length > 1 && count(array_unique($sampled)) === 1) {
            $sampled[$length - 1] = $sampled[0] === $characters[0] ? $characters[1] : $characters[0];
        }

        return implode('', $sampled);
    }

    private static function repeatToLength(string $value, int $length): string
    {
        if ($length === 0 || $value === '') {
            return '';
        }

        $repetitions = (int) ceil($length / self::unicodeLength($value));

        return implode('', array_slice(self::unicodeCharacters(str_repeat($value, $repetitions)), 0, $length));
    }

    private static function unicodeLength(string $value): int
    {
        return count(self::unicodeCharacters($value));
    }

    /** @return list<string> */
    private static function unicodeCharacters(string $value): array
    {
        preg_match_all('/./us', $value, $matches);

        return $matches[0];
    }
}
