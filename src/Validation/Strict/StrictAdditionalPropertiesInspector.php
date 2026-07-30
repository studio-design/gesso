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
 * @phpstan-type InspectedSchema array{
 *     schema: array<string, mixed>,
 *     dialect: string
 * }
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
            [self::schemaContext($schema, $jsonSchemaDialect, $honorSchemaDialectOverride)],
            '',
            $findings,
            $honorSchemaDialectOverride,
        );
        ksort($findings);

        return $findings;
    }

    /**
     * @param non-empty-list<InspectedSchema> $schemas
     * @param array<string, string> $findings
     */
    private static function walk(
        mixed $value,
        array $schemas,
        string $pointer,
        array &$findings,
        bool $honorSchemaDialectOverride,
    ): void {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return;
        }

        // There is no safe branch-selection rule here without running a
        // second JSON Schema evaluation and retaining its winning branch.
        // Stay conservative: disjunction-shaped and conditional nodes produce
        // no finding. The latter includes dependentSchemas because selecting
        // its active subschemas depends on the current instance.
        foreach ($schemas as $inspectedSchema) {
            if (
                self::hasDisjunction($inspectedSchema['schema']) ||
                self::hasConditionalApplicator(
                    $inspectedSchema['schema'],
                    $inspectedSchema['dialect'],
                    $honorSchemaDialectOverride,
                )
            ) {
                return;
            }
        }

        if ($value !== [] && array_is_list($value)) {
            foreach ($value as $index => $element) {
                $items = self::collectItemSchemasForIndex(
                    $schemas,
                    $index,
                    $honorSchemaDialectOverride,
                );
                if ($items === []) {
                    continue;
                }
                self::walk(
                    $element,
                    $items,
                    $pointer . '[*]',
                    $findings,
                    $honorSchemaDialectOverride,
                );
            }

            return;
        }

        $properties = [];
        $patterns = [];
        $openSchemas = [];
        $hasExplicitOpenKeyword = false;
        foreach ($schemas as $inspectedSchema) {
            foreach (self::collectProperties($inspectedSchema, $honorSchemaDialectOverride) as $name => $children) {
                $properties[$name] ??= [];
                foreach ($children as $childSchema) {
                    $properties[$name][] = $childSchema;
                }
            }
            foreach (self::collectPatternProperties($inspectedSchema, $honorSchemaDialectOverride) as $pattern => $children) {
                $patterns[$pattern] ??= [];
                foreach ($children as $childSchema) {
                    $patterns[$pattern][] = $childSchema;
                }
            }
            foreach (self::collectOpenSchemas($inspectedSchema, $honorSchemaDialectOverride) as $openSchema) {
                $openSchemas[] = $openSchema;
            }
            if (self::hasExplicitOpenKeyword(
                $inspectedSchema['schema'],
                $inspectedSchema['dialect'],
                $honorSchemaDialectOverride,
            )) {
                $hasExplicitOpenKeyword = true;
            }
        }

        foreach ($value as $propertyName => $child) {
            if (!is_string($propertyName)) {
                $propertyName = (string) $propertyName;
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
                if (!is_string($pattern)) {
                    $pattern = (string) $pattern;
                }
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
                if ($openSchemas !== []) {
                    self::walk(
                        $child,
                        $openSchemas,
                        $propertyPointer,
                        $findings,
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
                $childSchemas,
                $propertyPointer,
                $findings,
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
     * @param InspectedSchema $inspectedSchema
     *
     * @return array<array-key, list<InspectedSchema>>
     */
    private static function collectProperties(
        array $inspectedSchema,
        bool $honorSchemaDialectOverride,
    ): array {
        $out = [];
        $schema = $inspectedSchema['schema'];
        $dialect = $inspectedSchema['dialect'];
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $name => $child) {
                if (!is_string($name)) {
                    $name = (string) $name;
                }
                $out[$name] ??= [];
                if (is_array($child)) {
                    $out[$name][] = self::schemaContext(
                        $child,
                        $dialect,
                        $honorSchemaDialectOverride,
                    );
                }
            }
        }
        foreach (self::allOfBranches($schema) as $branch) {
            $branchContext = self::schemaContext($branch, $dialect, $honorSchemaDialectOverride);
            foreach (self::collectProperties($branchContext, $honorSchemaDialectOverride) as $name => $children) {
                $out[$name] ??= [];
                foreach ($children as $child) {
                    $out[$name][] = $child;
                }
            }
        }

        return $out;
    }

    /**
     * @param InspectedSchema $inspectedSchema
     *
     * @return array<array-key, list<InspectedSchema>>
     */
    private static function collectPatternProperties(
        array $inspectedSchema,
        bool $honorSchemaDialectOverride,
    ): array {
        $out = [];
        $schema = $inspectedSchema['schema'];
        $dialect = $inspectedSchema['dialect'];
        $patterns = $schema['patternProperties'] ?? null;
        if (is_array($patterns)) {
            foreach ($patterns as $pattern => $child) {
                if (!is_string($pattern)) {
                    $pattern = (string) $pattern;
                }
                $out[$pattern] ??= [];
                if (is_array($child)) {
                    $out[$pattern][] = self::schemaContext(
                        $child,
                        $dialect,
                        $honorSchemaDialectOverride,
                    );
                }
            }
        }
        foreach (self::allOfBranches($schema) as $branch) {
            $branchContext = self::schemaContext($branch, $dialect, $honorSchemaDialectOverride);
            foreach (self::collectPatternProperties($branchContext, $honorSchemaDialectOverride) as $pattern => $children) {
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
     * Return schema-form open-property declarations with their dialects.
     *
     * @param InspectedSchema $inspectedSchema
     *
     * @return list<InspectedSchema>
     */
    private static function collectOpenSchemas(
        array $inspectedSchema,
        bool $honorSchemaDialectOverride,
    ): array {
        $schemas = [];
        $schema = $inspectedSchema['schema'];
        $dialect = $inspectedSchema['dialect'];

        // additionalProperties evaluates every property that was not matched
        // by properties/patternProperties at this schema location. Therefore
        // unevaluatedProperties at the same location must not be reapplied to
        // that property, regardless of whether additionalProperties is a
        // boolean or schema form.
        if (array_key_exists('additionalProperties', $schema)) {
            if (is_array($schema['additionalProperties'])) {
                $schemas[] = self::schemaContext(
                    $schema['additionalProperties'],
                    $dialect,
                    $honorSchemaDialectOverride,
                );
            }
        } elseif (
            self::supportsUnevaluatedProperties($dialect) &&
            isset($schema['unevaluatedProperties']) &&
            is_array($schema['unevaluatedProperties'])
        ) {
            $schemas[] = self::schemaContext(
                $schema['unevaluatedProperties'],
                $dialect,
                $honorSchemaDialectOverride,
            );
        }
        foreach (self::allOfBranches($schema) as $branch) {
            $branchContext = self::schemaContext($branch, $dialect, $honorSchemaDialectOverride);
            foreach (self::collectOpenSchemas($branchContext, $honorSchemaDialectOverride) as $child) {
                $schemas[] = $child;
            }
        }

        return $schemas;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return InspectedSchema
     */
    private static function schemaContext(
        array $schema,
        string $inheritedDialect,
        bool $honorSchemaDialectOverride,
    ): array {
        return [
            'schema' => $schema,
            'dialect' => self::effectiveDialect(
                $schema,
                $inheritedDialect,
                $honorSchemaDialectOverride,
            ),
        ];
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
     * @param non-empty-list<InspectedSchema> $inspectedSchemas
     *
     * @return list<InspectedSchema>
     */
    private static function collectItemSchemasForIndex(
        array $inspectedSchemas,
        int $index,
        bool $honorSchemaDialectOverride,
    ): array {
        $schemas = [];
        foreach ($inspectedSchemas as $inspectedSchema) {
            $schema = $inspectedSchema['schema'];
            $dialect = $inspectedSchema['dialect'];
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
                    $schemas[] = self::schemaContext(
                        $itemSchema,
                        $dialect,
                        $honorSchemaDialectOverride,
                    );
                }
            } elseif (is_array($items) && array_is_list($items) && $items !== []) {
                $itemSchema = $items[$index] ?? ($schema['additionalItems'] ?? null);
                if (is_array($itemSchema)) {
                    $schemas[] = self::schemaContext(
                        $itemSchema,
                        $dialect,
                        $honorSchemaDialectOverride,
                    );
                }
            } elseif (is_array($items)) {
                $schemas[] = self::schemaContext(
                    $items,
                    $dialect,
                    $honorSchemaDialectOverride,
                );
            }
            foreach (self::allOfBranches($schema) as $branch) {
                $branchContext = self::schemaContext($branch, $dialect, $honorSchemaDialectOverride);
                foreach (self::collectItemSchemasForIndex(
                    [$branchContext],
                    $index,
                    $honorSchemaDialectOverride,
                ) as $child) {
                    $schemas[] = $child;
                }
            }
        }

        return $schemas;
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
