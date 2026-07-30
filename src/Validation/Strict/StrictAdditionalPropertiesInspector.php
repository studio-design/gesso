<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

use stdClass;
use Studio\Gesso\Spec\OpenApiSchemaDialect;

use function array_is_list;
use function array_key_exists;
use function count;
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
        string $jsonSchemaDialect = OpenApiSchemaDialect::OAS_3_1,
        bool $honorSchemaDialectOverride = true,
    ): array {
        $findings = [];
        self::walk(
            $body,
            $schema,
            '',
            $findings,
            $jsonSchemaDialect,
            $honorSchemaDialectOverride,
        );
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
        string $inheritedDialect,
        bool $honorSchemaDialectOverride,
    ): void {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return;
        }

        $dialect = self::effectiveDialect($schema, $inheritedDialect, $honorSchemaDialectOverride);

        // There is no safe branch-selection rule here without running a
        // second JSON Schema evaluation and retaining its winning branch.
        // Stay conservative: disjunction-shaped and conditional nodes produce
        // no finding. The latter includes dependentSchemas because selecting
        // its active subschemas depends on the current instance.
        if (
            self::hasDisjunction($schema) ||
            self::hasConditionalApplicator($schema, $dialect, $honorSchemaDialectOverride)
        ) {
            return;
        }

        if ($value !== [] && array_is_list($value)) {
            foreach ($value as $index => $element) {
                $items = self::collectItemSchemaForIndex(
                    $schema,
                    $index,
                    $dialect,
                    $honorSchemaDialectOverride,
                );
                if ($items === null) {
                    continue;
                }
                self::walk(
                    $element,
                    $items,
                    $pointer . '[*]',
                    $findings,
                    $dialect,
                    $honorSchemaDialectOverride,
                );
            }

            return;
        }

        $properties = self::collectProperties($schema);
        $patterns = self::collectPatternProperties($schema);
        $openSchema = self::collectOpenSchema($schema, $dialect, $honorSchemaDialectOverride);
        $hasExplicitOpenKeyword = self::hasExplicitOpenKeyword(
            $schema,
            $dialect,
            $honorSchemaDialectOverride,
        );

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
                    self::walk(
                        $child,
                        $openSchema,
                        $propertyPointer,
                        $findings,
                        $dialect,
                        $honorSchemaDialectOverride,
                    );
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
                $dialect,
                $honorSchemaDialectOverride,
            );
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function hasDisjunction(array $schema): bool
    {
        if (
            (isset($schema['anyOf']) && is_array($schema['anyOf'])) ||
            (isset($schema['oneOf']) && is_array($schema['oneOf']))
        ) {
            return true;
        }
        foreach (self::allOfBranches($schema) as $branch) {
            if (self::hasDisjunction($branch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function hasConditionalApplicator(
        array $schema,
        string $inheritedDialect,
        bool $honorSchemaDialectOverride,
    ): bool {
        $dialect = self::effectiveDialect($schema, $inheritedDialect, $honorSchemaDialectOverride);
        if (
            self::supportsIfThenElse($dialect) &&
            (
                array_key_exists('if', $schema) ||
                array_key_exists('then', $schema) ||
                array_key_exists('else', $schema)
            )
        ) {
            return true;
        }
        if (self::supportsUnevaluatedProperties($dialect) && array_key_exists('dependentSchemas', $schema)) {
            return true;
        }
        foreach (self::allOfBranches($schema) as $branch) {
            if (self::hasConditionalApplicator($branch, $dialect, $honorSchemaDialectOverride)) {
                return true;
            }
        }

        return false;
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
        string $inheritedDialect,
        bool $honorSchemaDialectOverride,
    ): bool {
        $dialect = self::effectiveDialect($schema, $inheritedDialect, $honorSchemaDialectOverride);
        if (
            array_key_exists('additionalProperties', $schema) ||
            (self::supportsUnevaluatedProperties($dialect) && array_key_exists('unevaluatedProperties', $schema))
        ) {
            return true;
        }
        foreach (self::allOfBranches($schema) as $branch) {
            if (self::hasExplicitOpenKeyword($branch, $dialect, $honorSchemaDialectOverride)) {
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
        string $inheritedDialect,
        bool $honorSchemaDialectOverride,
    ): ?array {
        $schemas = [];
        $dialect = self::effectiveDialect($schema, $inheritedDialect, $honorSchemaDialectOverride);

        // additionalProperties evaluates every property that was not matched
        // by properties/patternProperties at this schema location. Therefore
        // unevaluatedProperties at the same location must not be reapplied to
        // that property, regardless of whether additionalProperties is a
        // boolean or schema form.
        if (array_key_exists('additionalProperties', $schema)) {
            if (is_array($schema['additionalProperties'])) {
                $schemas[] = $schema['additionalProperties'];
            }
        } elseif (
            self::supportsUnevaluatedProperties($dialect) &&
            isset($schema['unevaluatedProperties']) &&
            is_array($schema['unevaluatedProperties'])
        ) {
            $schemas[] = $schema['unevaluatedProperties'];
        }
        foreach (self::allOfBranches($schema) as $branch) {
            $child = self::collectOpenSchema($branch, $dialect, $honorSchemaDialectOverride);
            if ($child !== null) {
                $schemas[] = $child;
            }
        }

        return $schemas === [] ? null : self::combineSchemas($schemas);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function effectiveDialect(
        array $schema,
        string $inheritedDialect,
        bool $honorSchemaDialectOverride,
    ): string {
        if (
            $honorSchemaDialectOverride &&
            isset($schema['$schema']) &&
            is_string($schema['$schema']) &&
            $schema['$schema'] !== ''
        ) {
            return $schema['$schema'];
        }

        return $inheritedDialect;
    }

    private static function supportsIfThenElse(string $dialect): bool
    {
        if ($dialect === OpenApiSchemaDialect::OAS_3_1) {
            return true;
        }

        return preg_match(
            '~json-schema\.org/draft(?:/|-)(?:07|2019-09|2020-12)/schema#?$~i',
            $dialect,
        ) === 1;
    }

    private static function supportsUnevaluatedProperties(string $dialect): bool
    {
        if ($dialect === OpenApiSchemaDialect::OAS_3_1) {
            return true;
        }

        return preg_match(
            '~json-schema\.org/draft(?:/|-)(?:2019-09|2020-12)/schema#?$~i',
            $dialect,
        ) === 1;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return null|array<string, mixed>
     */
    private static function collectItemSchemaForIndex(
        array $schema,
        int $index,
        string $inheritedDialect,
        bool $honorSchemaDialectOverride,
    ): ?array {
        $schemas = [];
        $dialect = self::effectiveDialect($schema, $inheritedDialect, $honorSchemaDialectOverride);
        $items = $schema['items'] ?? null;

        if (self::supportsPrefixItems($dialect)) {
            $prefixItems = $schema['prefixItems'] ?? null;
            $prefixCount = is_array($prefixItems) && array_is_list($prefixItems)
                ? count($prefixItems)
                : 0;
            $itemSchema = $index < $prefixCount
                ? $prefixItems[$index]
                : $items;
            if (is_array($itemSchema)) {
                $schemas[] = $itemSchema;
            }
        } elseif (is_array($items) && array_is_list($items) && $items !== []) {
            $itemSchema = $items[$index] ?? ($schema['additionalItems'] ?? null);
            if (is_array($itemSchema)) {
                $schemas[] = $itemSchema;
            }
        } elseif (is_array($items)) {
            $schemas[] = $items;
        }
        foreach (self::allOfBranches($schema) as $branch) {
            $child = self::collectItemSchemaForIndex(
                $branch,
                $index,
                $dialect,
                $honorSchemaDialectOverride,
            );
            if ($child !== null) {
                $schemas[] = $child;
            }
        }

        return $schemas === [] ? null : self::combineSchemas($schemas);
    }

    private static function supportsPrefixItems(string $dialect): bool
    {
        if ($dialect === OpenApiSchemaDialect::OAS_3_1) {
            return true;
        }

        return preg_match(
            '~json-schema\.org/draft(?:/|-)2020-12/schema#?$~i',
            $dialect,
        ) === 1;
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
