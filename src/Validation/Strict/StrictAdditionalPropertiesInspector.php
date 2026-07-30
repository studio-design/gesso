<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

use stdClass;

use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_string;
use function ksort;
use function preg_match;
use function str_replace;

/**
 * Finds response properties that are not covered by an effective schema.
 *
 * `allOf` branches contribute to the same effective object view. A node
 * with an explicit `additionalProperties` or `unevaluatedProperties`
 * keyword is intentionally open for this check, regardless of the keyword
 * value: `false` is already enforced by conformance validation, while
 * `true` and schema forms explicitly document openness.
 *
 * @internal Used on the successful response-validation path.
 */
final class StrictAdditionalPropertiesInspector
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, string> property JSON pointer => property name
     */
    public static function inspect(
        mixed $body,
        array $schema,
        bool $supportsUnevaluatedProperties = true,
    ): array {
        $findings = [];
        self::walk($body, $schema, '', $findings, $supportsUnevaluatedProperties);
        ksort($findings);

        return $findings;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, string> $findings
     */
    private static function walk(
        mixed $value,
        array $schema,
        string $pointer,
        array &$findings,
        bool $supportsUnevaluatedProperties,
    ): void {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return;
        }

        // There is no safe branch-selection rule here without running a
        // second JSON Schema evaluation and retaining its winning branch.
        // Stay conservative: disjunction-shaped nodes produce no finding.
        if (self::hasDisjunction($schema)) {
            return;
        }

        if ($value !== [] && array_is_list($value)) {
            $items = self::collectItemsSchema($schema);
            if ($items === null) {
                return;
            }
            foreach ($value as $element) {
                self::walk($element, $items, $pointer . '[*]', $findings, $supportsUnevaluatedProperties);
            }

            return;
        }

        $properties = self::collectProperties($schema);
        $patterns = self::collectPatternProperties($schema);
        $openSchema = self::collectOpenSchema($schema, $supportsUnevaluatedProperties);
        $hasExplicitOpenKeyword = self::hasExplicitOpenKeyword($schema, $supportsUnevaluatedProperties);

        foreach ($value as $propertyName => $child) {
            if (!is_string($propertyName)) {
                continue;
            }
            $propertyPointer = self::appendProperty($pointer, $propertyName);

            $childSchemas = [];
            $declared = array_key_exists($propertyName, $properties);
            if ($declared) {
                foreach ($properties[$propertyName] as $propertySchema) {
                    $childSchemas[] = $propertySchema;
                }
            }
            foreach ($patterns as $pattern => $patternSchemas) {
                if (self::patternMatches($pattern, $propertyName)) {
                    $declared = true;
                    foreach ($patternSchemas as $patternSchema) {
                        $childSchemas[] = $patternSchema;
                    }
                }
            }

            if (!$declared) {
                if (!$hasExplicitOpenKeyword) {
                    $findings[$propertyPointer] = $propertyName;

                    continue;
                }
                if ($openSchema !== null) {
                    self::walk($child, $openSchema, $propertyPointer, $findings, $supportsUnevaluatedProperties);
                }

                continue;
            }
            if ($childSchemas === []) {
                // Boolean `true` / `false` property schemas still declare
                // the name. `false` cannot reach this success-only path
                // with a present value; `true` has no nested shape to walk.
                continue;
            }

            self::walk(
                $child,
                self::combineSchemas($childSchemas),
                $propertyPointer,
                $findings,
                $supportsUnevaluatedProperties,
            );
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function hasDisjunction(array $schema): bool
    {
        return (isset($schema['anyOf']) && is_array($schema['anyOf'])) ||
            (isset($schema['oneOf']) && is_array($schema['oneOf']));
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function collectProperties(array $schema): array
    {
        $out = [];
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $name => $child) {
                if (!is_string($name)) {
                    continue;
                }
                $out[$name] ??= [];
                if (is_array($child)) {
                    $out[$name][] = $child;
                }
            }
        }
        foreach (self::allOfBranches($schema) as $branch) {
            foreach (self::collectProperties($branch) as $name => $children) {
                $out[$name] ??= [];
                foreach ($children as $child) {
                    $out[$name][] = $child;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function collectPatternProperties(array $schema): array
    {
        $out = [];
        $patterns = $schema['patternProperties'] ?? null;
        if (is_array($patterns)) {
            foreach ($patterns as $pattern => $child) {
                if (!is_string($pattern)) {
                    continue;
                }
                $out[$pattern] ??= [];
                if (is_array($child)) {
                    $out[$pattern][] = $child;
                }
            }
        }
        foreach (self::allOfBranches($schema) as $branch) {
            foreach (self::collectPatternProperties($branch) as $pattern => $children) {
                $out[$pattern] ??= [];
                foreach ($children as $child) {
                    $out[$pattern][] = $child;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function hasExplicitOpenKeyword(
        array $schema,
        bool $supportsUnevaluatedProperties,
    ): bool {
        if (
            array_key_exists('additionalProperties', $schema) ||
            ($supportsUnevaluatedProperties && array_key_exists('unevaluatedProperties', $schema))
        ) {
            return true;
        }
        foreach (self::allOfBranches($schema) as $branch) {
            if (self::hasExplicitOpenKeyword($branch, $supportsUnevaluatedProperties)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a schema-form open-property declaration when one is present.
     *
     * @param array<string, mixed> $schema
     *
     * @return null|array<string, mixed>
     */
    private static function collectOpenSchema(
        array $schema,
        bool $supportsUnevaluatedProperties,
    ): ?array {
        $schemas = [];
        $keywords = $supportsUnevaluatedProperties
            ? ['additionalProperties', 'unevaluatedProperties']
            : ['additionalProperties'];
        foreach ($keywords as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $schemas[] = $schema[$keyword];
            }
        }
        foreach (self::allOfBranches($schema) as $branch) {
            $child = self::collectOpenSchema($branch, $supportsUnevaluatedProperties);
            if ($child !== null) {
                $schemas[] = $child;
            }
        }

        return $schemas === [] ? null : self::combineSchemas($schemas);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return null|array<string, mixed>
     */
    private static function collectItemsSchema(array $schema): ?array
    {
        $schemas = [];
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schemas[] = $schema['items'];
        }
        foreach (self::allOfBranches($schema) as $branch) {
            $child = self::collectItemsSchema($branch);
            if ($child !== null) {
                $schemas[] = $child;
            }
        }

        return $schemas === [] ? null : self::combineSchemas($schemas);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<array<string, mixed>>
     */
    private static function allOfBranches(array $schema): array
    {
        $out = [];
        $branches = $schema['allOf'] ?? null;
        if (!is_array($branches)) {
            return [];
        }
        foreach ($branches as $branch) {
            if (is_array($branch)) {
                $out[] = $branch;
            }
        }

        return $out;
    }

    /**
     * @param non-empty-list<array<string, mixed>> $schemas
     *
     * @return array<string, mixed>
     */
    private static function combineSchemas(array $schemas): array
    {
        if (isset($schemas[1])) {
            return ['allOf' => $schemas];
        }

        return $schemas[0];
    }

    private static function patternMatches(string $pattern, string $propertyName): bool
    {
        $escapedDelimiter = str_replace('~', '\~', $pattern);

        return @preg_match('~' . $escapedDelimiter . '~u', $propertyName) === 1;
    }

    private static function appendProperty(string $pointer, string $propertyName): string
    {
        $escaped = str_replace('~', '~0', $propertyName);
        $escaped = str_replace('/', '~1', $escaped);
        $escaped = str_replace('[*]', '[~*]', $escaped);

        return $pointer . '/' . $escaped;
    }
}
