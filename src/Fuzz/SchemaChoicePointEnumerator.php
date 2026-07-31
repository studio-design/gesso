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
use function is_int;
use function is_string;
use function sprintf;
use function str_replace;

/**
 * Pre-pass enumerator that collects every composition choice point of a
 * converted JSON Schema: `oneOf`/`anyOf` branches, conditional branches,
 * nullable presence, and optional-property presence.
 *
 * The traversal mirrors {@see SchemaDataGenerator} resolution exactly — one
 * composition keyword per node, `allOf` branches merged into the node, `if`
 * resolved once after `allOf` — so the JSON Pointers it reports are the ones
 * planned generation resolves. Shapes the generator cannot resolve (a second
 * unresolved composition on the same node, keywords reintroduced by a branch
 * merge) are rejected loudly instead of silently dropping branches.
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

    /** @var array<string, int> */
    private array $coveredBranches = [];
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
                array_filter($value, is_array(...)) !== []) {
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
            /** @var list<array<string, mixed>> $branches */
            $branches = array_values(array_filter($schema[$keyword], is_array(...)));
            $choicePointer = $pointer . '/' . $keyword;
            $this->record(new SchemaChoicePoint(
                $keyword === 'oneOf' ? SchemaChoicePointKind::OneOf : SchemaChoicePointKind::AnyOf,
                $choicePointer,
                count($branches),
                $ancestors,
            ));

            $base = $schema;
            unset($base[$keyword]);
            foreach ($branches as $index => $branch) {
                $merged = SchemaDataGenerator::mergeSchemas($base, $branch);
                $this->rejectReintroduced($merged, ['oneOf', 'anyOf'], $pointer);
                $this->visitAllOfPhase($merged, $pointer, [...$ancestors, $choicePointer => $index], $depth);
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

        if ($conditionals === []) {
            $this->rejectReintroduced($base, ['oneOf', 'anyOf', 'allOf'], $pointer);
            $this->visitIfPhase($base, $pointer, $ancestors, $depth);

            return;
        }

        // Two branches per conditional, mirroring pinned generation: even
        // branches satisfy if+then, odd branches take not-if+else.
        $choicePointer = $pointer . '/allOf';
        $this->record(new SchemaChoicePoint(
            SchemaChoicePointKind::AllOfConditional,
            $choicePointer,
            2 * count($conditionals),
            $ancestors,
        ));
        foreach ($conditionals as $index => $conditional) {
            $thenView = SchemaDataGenerator::mergeSchemas($base, $conditional['if']);
            if (isset($conditional['then']) && is_array($conditional['then'])) {
                $thenView = SchemaDataGenerator::mergeSchemas($thenView, $conditional['then']);
            }
            $elseView = SchemaDataGenerator::mergeSchemas($base, SchemaDataGenerator::mergeSchemas(
                ['not' => $conditional['if']],
                is_array($conditional['else'] ?? null) ? $conditional['else'] : [],
            ));
            foreach ([2 * $index => $thenView, 2 * $index + 1 => $elseView] as $branch => $view) {
                $this->rejectReintroduced($view, ['oneOf', 'anyOf', 'allOf'], $pointer);
                $this->visitIfPhase($view, $pointer, [...$ancestors, $choicePointer => $branch], $depth);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, int> $ancestors
     */
    private function visitIfPhase(array $schema, string $pointer, array $ancestors, int $depth): void
    {
        if (!isset($schema['if']) || !is_array($schema['if'])) {
            $this->visitLeaf($schema, $pointer, $ancestors, $depth);

            return;
        }

        $choicePointer = $pointer . '/if';
        $this->record(new SchemaChoicePoint(SchemaChoicePointKind::IfThenElse, $choicePointer, 2, $ancestors));

        $base = $schema;
        unset($base['if'], $base['then'], $base['else']);
        $views = [
            SchemaDataGenerator::mergeSchemas($base, SchemaDataGenerator::mergeSchemas(
                $schema['if'],
                is_array($schema['then'] ?? null) ? $schema['then'] : [],
            )),
            SchemaDataGenerator::mergeSchemas($base, SchemaDataGenerator::mergeSchemas(
                ['not' => $schema['if']],
                is_array($schema['else'] ?? null) ? $schema['else'] : [],
            )),
        ];
        foreach ($views as $branch => $view) {
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
            return;
        }

        if (self::isNullableTypeArray($schema)) {
            $choicePointer = $pointer . '/type';
            $this->record(new SchemaChoicePoint(SchemaChoicePointKind::Nullable, $choicePointer, 2, $ancestors));
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
            if (!is_string($name) || !is_array($propertySchema)) {
                continue;
            }

            $childPointer = $pointer . '/properties/' . self::escapePointerSegment($name);
            $childAncestors = $ancestors;
            if (!in_array($name, $required, true)) {
                $this->record(new SchemaChoicePoint(
                    SchemaChoicePointKind::OptionalProperty,
                    $childPointer,
                    2,
                    $ancestors,
                ));
                $childAncestors = [...$childAncestors, $childPointer => SchemaChoicePoint::PRESENT];
            }

            $this->visitNode($propertySchema, $childPointer, $childAncestors, $depth + 1);
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

    private function record(SchemaChoicePoint $point): void
    {
        // The same effective pointer can be rediscovered under a different
        // branch context. Branches an earlier discovery already covers stay
        // covered; a wider rediscovery contributes only its uncovered tail,
        // under the ancestor chain that makes those extra branches reachable.
        $covered = $this->coveredBranches[$point->pointer] ?? 0;
        if ($point->branchCount <= $covered) {
            return;
        }

        $this->points[] = new SchemaChoicePoint(
            $point->kind,
            $point->pointer,
            $point->branchCount,
            $point->ancestors,
            $covered,
        );
        $this->coveredBranches[$point->pointer] = $point->branchCount;
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
