<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;

use function array_filter;
use function array_key_exists;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_encode;
use function md5;
use function sprintf;
use function str_replace;

/**
 * Pre-pass enumerator that collects every composition choice point of a
 * converted JSON Schema: `oneOf`/`anyOf` branches, conditional branches,
 * nullable presence, and optional-property presence.
 *
 * The traversal mirrors {@see SchemaDataGenerator} resolution exactly — one
 * composition keyword per node, `allOf` branches merged into the node,
 * conditional-`allOf` branches materialised through
 * {@see SchemaDataGenerator::conditionalBranchView()}, `if` resolved once
 * after `allOf` — so the JSON Pointers it reports are the ones planned
 * generation resolves. Shapes the generator cannot resolve (a second
 * unresolved composition on the same node, keywords reintroduced by a branch
 * merge) are rejected loudly instead of silently dropping branches.
 *
 * Statically decidable unreachability is pruned during the walk — `false`
 * schemas in branches, consequents, properties, and prefix items, and
 * `oneOf` siblings shadowed by a `true` branch. Everything else is emitted
 * even though reachability in general is undecidable: a pinned case whose
 * branch turns out to be forbidden by parent constraints is dropped by
 * {@see BranchCompleteCaseGenerator}, not failed.
 *
 * A choice point rediscovered at the same pointer keeps one entry per
 * discovery context (ancestors + branch content); only exact revisits
 * collapse. Contexts are not interchangeable: generation can leave a branch
 * context mid-case when suppressed conditionals fire, but every reachable
 * state retains at least one generation-stable context whose case realizes
 * it — `then` content under its own branch, `else` content under the
 * none-match state.
 *
 * Enumeration bounds, all loud on excess:
 *  - {@see self::MAX_DEPTH} nested property/item levels;
 *  - {@see self::MAX_CHOICE_POINTS} collected choice points;
 *  - {@see self::MAX_NODE_VISITS} visited nodes across all branch contexts.
 *
 * `additionalProperties` filler values and keywords that are not generation
 * strategies (`contains`, `patternProperties`, `dependentSchemas`,
 * `unevaluated*`) contribute no choice points; they remain validator features.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SchemaChoicePointEnumerator
{
    public const MAX_DEPTH = 32;
    public const MAX_CHOICE_POINTS = 256;
    public const MAX_NODE_VISITS = 10_000;

    /** @var list<SchemaChoicePoint> */
    private array $points = [];

    /** @var array<string, true> Dedupe keys of recorded choice points. */
    private array $seen = [];
    private int $visits = 0;

    private function __construct() {}

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<SchemaChoicePoint>
     */
    public static function enumerate(array $schema): array
    {
        $enumerator = new self();
        $enumerator->visitNode($schema, '', [], 0);

        return $enumerator->points;
    }

    /**
     * Escape a property name for use as a JSON Pointer reference token.
     */
    public static function escapePointerSegment(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $keywords
     *
     * @return list<string>
     */
    private static function unresolvedCompositionKeywords(array $schema, array $keywords): array
    {
        $found = [];
        foreach ($keywords as $keyword) {
            $value = $schema[$keyword] ?? null;
            if (!is_array($value) || $value === []) {
                continue;
            }
            if ($keyword === 'if' || $keyword === 'allOf' ||
                array_filter($value, static fn(mixed $branch): bool => is_array($branch) || is_bool($branch)) !== []) {
                $found[] = $keyword;
            }
        }

        return $found;
    }

    /** @param array<string, mixed> $schema */
    private static function isNullableTypeArray(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if (!is_array($type) || !in_array('null', $type, true)) {
            return false;
        }

        foreach ($type as $candidate) {
            if (is_string($candidate) && $candidate !== 'null') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, int> $ancestors
     */
    private function visitNode(array $schema, string $pointer, array $ancestors, int $depth): void
    {
        if (++$this->visits > self::MAX_NODE_VISITS) {
            throw new InvalidArgumentException(sprintf(
                'Choice-point enumeration exceeded the node-visit budget of %d; '
                . 'the schema is outside the branch-complete enumeration subset.',
                self::MAX_NODE_VISITS,
            ));
        }
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf(
                "Choice-point enumeration exceeded the maximum schema depth of %d at '%s'.",
                self::MAX_DEPTH,
                $pointer,
            ));
        }

        $keywords = self::unresolvedCompositionKeywords($schema, ['oneOf', 'anyOf']);
        if (count($keywords) > 1) {
            throw new InvalidArgumentException(sprintf(
                "Schema node '%s' carries multiple composition keywords (%s); generation resolves "
                . 'only the first, so this shape is outside the branch-complete enumeration subset.',
                $pointer,
                implode(', ', $keywords),
            ));
        }

        if ($keywords !== []) {
            $keyword = $keywords[0];
            [$branches, $enumerable] = SchemaDataGenerator::compositionBranchSpace(
                array_values($schema[$keyword]),
                $keyword,
            );
            if ($enumerable === []) {
                // No branch is generatable (e.g. every branch is `false`);
                // the keyword stays unresolved and generation fails its
                // self-check loudly, mirrored here by not descending.
                $this->visitAllOfPhase($schema, $pointer, $ancestors, $depth);

                return;
            }

            $choicePointer = $pointer . '/' . $keyword;
            $this->record(
                $keyword === 'oneOf' ? SchemaChoicePointKind::OneOf : SchemaChoicePointKind::AnyOf,
                $choicePointer,
                count($enumerable),
                $ancestors,
                $branches,
            );

            $base = $schema;
            unset($base[$keyword]);
            foreach ($enumerable as $branch => $selected) {
                $merged = SchemaDataGenerator::applyCompositionBranch($base, $branches, $selected, $keyword);
                $this->rejectReintroduced($merged, ['oneOf', 'anyOf'], $pointer);
                $this->visitAllOfPhase($merged, $pointer, [...$ancestors, $choicePointer => $branch], $depth);
            }

            return;
        }

        $this->visitAllOfPhase($schema, $pointer, $ancestors, $depth);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, int> $ancestors
     */
    private function visitAllOfPhase(array $schema, string $pointer, array $ancestors, int $depth): void
    {
        if (!isset($schema['allOf']) || !is_array($schema['allOf'])) {
            $this->visitIfPhase($schema, $pointer, $ancestors, $depth);

            return;
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
                $base = SchemaDataGenerator::mergeSchemas($base, $branch);
            }
        }

        if ($conditionals !== []) {
            // Boolean consequents leave no choice; fold them exactly like
            // generation does. An unsatisfiable node keeps only the base —
            // its cases fail the self-check loudly.
            [$base, $conditionals, $unsatisfiable] = SchemaDataGenerator::partitionConditionals($base, $conditionals);
            if ($unsatisfiable) {
                $conditionals = [];
            }
        }

        if ($conditionals === []) {
            $this->rejectReintroduced($base, ['oneOf', 'anyOf', 'allOf'], $pointer);
            $this->visitIfPhase($base, $pointer, $ancestors, $depth);

            return;
        }

        // One branch per conditional (if+then with the others suppressed)
        // plus the trailing none-match branch where every else applies.
        $count = count($conditionals);
        $choicePointer = $pointer . '/allOf';
        $this->record(
            SchemaChoicePointKind::AllOfConditional,
            $choicePointer,
            $count + 1,
            $ancestors,
            $conditionals,
        );
        for ($branch = 0; $branch <= $count; $branch++) {
            $view = SchemaDataGenerator::conditionalBranchView($base, $conditionals, $branch);
            $this->rejectReintroduced($view, ['oneOf', 'anyOf', 'allOf'], $pointer);
            $this->visitIfPhase($view, $pointer, [...$ancestors, $choicePointer => $branch], $depth);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, int> $ancestors
     */
    private function visitIfPhase(array $schema, string $pointer, array $ancestors, int $depth): void
    {
        if (is_bool($schema['if'] ?? null)) {
            // A boolean if has no branch to choose: `if: true` makes the
            // then unconditional, `if: false` the else. No choice point.
            $branchSchema = $schema['if'] === true ? ($schema['then'] ?? null) : ($schema['else'] ?? null);
            unset($schema['if'], $schema['then'], $schema['else']);
            if (is_array($branchSchema)) {
                $schema = SchemaDataGenerator::mergeSchemas($schema, $branchSchema);
            }
            $this->rejectReintroduced($schema, ['oneOf', 'anyOf', 'allOf', 'if'], $pointer);
            $this->visitLeaf($schema, $pointer, $ancestors, $depth);

            return;
        }

        if (!isset($schema['if']) || !is_array($schema['if'])) {
            $this->visitLeaf($schema, $pointer, $ancestors, $depth);

            return;
        }

        $sides = SchemaDataGenerator::ifBranchSides($schema);
        if ($sides === []) {
            // Both consequents are `false`: neither side is satisfiable.
            // Generation leaves the keyword unresolved and fails its
            // self-check loudly; mirror by not descending the consequents.
            $this->visitLeaf($schema, $pointer, $ancestors, $depth);

            return;
        }

        $choicePointer = $pointer . '/if';
        $this->record(
            SchemaChoicePointKind::IfThenElse,
            $choicePointer,
            count($sides),
            $ancestors,
            [$schema['if'], $schema['then'] ?? null, $schema['else'] ?? null],
        );

        foreach ($sides as $branch => $side) {
            $view = SchemaDataGenerator::applyIfSide($schema, $side);
            $this->rejectReintroduced($view, ['oneOf', 'anyOf', 'allOf', 'if'], $pointer);
            $this->visitLeaf($view, $pointer, [...$ancestors, $choicePointer => $branch], $depth);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, int> $ancestors
     */
    private function visitLeaf(array $schema, string $pointer, array $ancestors, int $depth): void
    {
        if (array_key_exists('const', $schema)) {
            return;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $admissible = array_values(array_filter(
                $schema['enum'],
                static fn(mixed $value): bool => SchemaValueValidator::isValid($value, $schema),
            ));
            $hasNull = in_array(null, $admissible, true);
            $hasValue = array_filter($admissible, static fn(mixed $value): bool => $value !== null) !== [];
            if (self::isNullableTypeArray($schema) && $hasNull && $hasValue) {
                $this->record(
                    SchemaChoicePointKind::Nullable,
                    $pointer . '/type',
                    2,
                    $ancestors,
                    null,
                );
            }

            return;
        }

        if (self::isNullableTypeArray($schema)) {
            $choicePointer = $pointer . '/type';
            $this->record(SchemaChoicePointKind::Nullable, $choicePointer, 2, $ancestors, null);
            $ancestors = [...$ancestors, $choicePointer => SchemaChoicePoint::VALUE];
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            unset($schema['not']);
        }

        match (SchemaDataGenerator::resolveType($schema)) {
            'object' => $this->visitObject($schema, $pointer, $ancestors, $depth),
            'array' => $this->visitArray($schema, $pointer, $ancestors, $depth),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, int> $ancestors
     */
    private function visitObject(array $schema, string $pointer, array $ancestors, int $depth): void
    {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return;
        }

        $required = [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $name) {
                if (is_string($name)) {
                    $required[] = $name;
                }
            }
        }

        foreach ($properties as $name => $propertySchema) {
            if (!is_string($name)) {
                continue;
            }
            // Boolean property schemas: `true` admits any value, so only its
            // presence is a choice; `false` admits none, so presence is
            // unreachable and there is no choice at all.
            if (!is_array($propertySchema) && $propertySchema !== true) {
                continue;
            }

            $childPointer = $pointer . '/properties/' . self::escapePointerSegment($name);
            $childAncestors = $ancestors;
            if (!in_array($name, $required, true)) {
                $this->record(SchemaChoicePointKind::OptionalProperty, $childPointer, 2, $ancestors, null);
                $childAncestors = [...$childAncestors, $childPointer => SchemaChoicePoint::PRESENT];
            }

            if (is_array($propertySchema)) {
                $this->visitNode($propertySchema, $childPointer, $childAncestors, $depth + 1);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, int> $ancestors
     */
    private function visitArray(array $schema, string $pointer, array $ancestors, int $depth): void
    {
        $maxItems = isset($schema['maxItems']) && is_int($schema['maxItems']) ? $schema['maxItems'] : null;
        $sizePointer = $pointer . '/items';

        $prefixItems = $schema['prefixItems'] ?? null;
        if (is_array($prefixItems)) {
            $prefixCount = count($prefixItems);
            foreach (array_values($prefixItems) as $index => $item) {
                if ($item === false) {
                    // Nothing matches a false prefix item, so any array long
                    // enough to contain it — or anything behind it — is
                    // invalid: it is an effective maxItems.
                    return;
                }
                if (!is_array($item) || ($maxItems !== null && $maxItems <= $index)) {
                    continue;
                }
                $this->visitNode(
                    $item,
                    $pointer . '/prefixItems/' . $index,
                    [...$ancestors, $sizePointer => $index + 1],
                    $depth + 1,
                );
            }

            $items = $schema['items'] ?? null;
            if (is_array($items) && ($maxItems === null || $maxItems > $prefixCount)) {
                $this->visitNode(
                    $items,
                    $sizePointer,
                    [...$ancestors, $sizePointer => $prefixCount + 1],
                    $depth + 1,
                );
            }

            return;
        }

        $items = $schema['items'] ?? null;
        if (is_array($items) && ($maxItems === null || $maxItems >= 1)) {
            $this->visitNode($items, $sizePointer, [...$ancestors, $sizePointer => 1], $depth + 1);
        }
    }

    /**
     * @param array<string, int> $ancestors
     */
    private function record(
        SchemaChoicePointKind $kind,
        string $pointer,
        int $branchCount,
        array $ancestors,
        mixed $content,
    ): void {
        // The same effective pointer can be rediscovered under a different
        // branch context. Each context keeps its own entry: generation may
        // leave a context mid-case (closure expansion when suppressed
        // conditionals fire), so no single context's claim is authoritative
        // — but every reachable state has at least one context that is
        // stable under generation (its own branch for `then` content, the
        // none-match state for `else` content), and that context's case
        // realizes the branch. Only exact revisits — same pointer, same
        // content, same ancestors — collapse.
        $key = $pointer . '#' . md5((string) json_encode([$ancestors, $content]));
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;

        $this->points[] = new SchemaChoicePoint($kind, $pointer, $branchCount, $ancestors);
        if (count($this->points) > self::MAX_CHOICE_POINTS) {
            throw new InvalidArgumentException(sprintf(
                'Choice-point enumeration exceeded the maximum of %d choice points.',
                self::MAX_CHOICE_POINTS,
            ));
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $keywords
     */
    private function rejectReintroduced(array $schema, array $keywords, string $pointer): void
    {
        $found = self::unresolvedCompositionKeywords($schema, $keywords);
        if ($found !== []) {
            throw new InvalidArgumentException(sprintf(
                "Resolving a branch at '%s' reintroduces composition keywords (%s) that generation "
                . 'would not resolve again; this shape is outside the branch-complete enumeration subset.',
                $pointer,
                implode(', ', $found),
            ));
        }
    }
}
