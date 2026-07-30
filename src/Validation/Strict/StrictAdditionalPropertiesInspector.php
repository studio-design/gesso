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
 * @phpstan-type PropertySelection array{
 *     documented: bool,
 *     schemas: list<InspectedSchema>
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
        $isObject = $value instanceof stdClass;
        if ($isObject) {
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

        if (!$isObject && $value !== [] && array_is_list($value)) {
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

        foreach ($value as $propertyName => $child) {
            if (!is_string($propertyName)) {
                $propertyName = (string) $propertyName;
            }
            $propertyPointer = self::appendProperty($pointer, $propertyName);

            $childSchemas = [];
            $documented = false;
            foreach ($schemas as $inspectedSchema) {
                $selection = self::selectPropertySchemas(
                    $inspectedSchema,
                    $propertyName,
                    $honorSchemaDialectOverride,
                );
                if ($selection['documented']) {
                    $documented = true;
                }
                foreach ($selection['schemas'] as $childSchema) {
                    $childSchemas[] = $childSchema;
                }
            }

            if (!$documented) {
                $findings[$propertyPointer] = $propertyName;

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
     * Select every child schema that applies to one property at this schema
     * location and at each allOf branch below it.
     *
     * @param InspectedSchema $inspectedSchema
     *
     * @return PropertySelection
     */
    private static function selectPropertySchemas(
        array $inspectedSchema,
        string $propertyName,
        bool $honorSchemaDialectOverride,
    ): array {
        $schemas = [];
        $schema = $inspectedSchema['schema'];
        $dialect = $inspectedSchema['dialect'];
        $matchedHere = false;

        $properties = $schema['properties'] ?? null;
        if (is_array($properties) && array_key_exists($propertyName, $properties)) {
            $matchedHere = true;
            if (is_array($properties[$propertyName])) {
                $schemas[] = self::schemaContext(
                    $properties[$propertyName],
                    $dialect,
                    $honorSchemaDialectOverride,
                );
            }
        }

        $patterns = $schema['patternProperties'] ?? null;
        if (is_array($patterns)) {
            foreach ($patterns as $pattern => $child) {
                if (!is_string($pattern)) {
                    $pattern = (string) $pattern;
                }
                if (!self::patternMatches($pattern, $propertyName)) {
                    continue;
                }
                $matchedHere = true;
                if (is_array($child)) {
                    $schemas[] = self::schemaContext(
                        $child,
                        $dialect,
                        $honorSchemaDialectOverride,
                    );
                }
            }
        }

        $documentedByBranch = false;
        foreach (self::allOfBranches($schema) as $branch) {
            $selection = self::selectPropertySchemas(
                self::schemaContext($branch, $dialect, $honorSchemaDialectOverride),
                $propertyName,
                $honorSchemaDialectOverride,
            );
            if ($selection['documented']) {
                $documentedByBranch = true;
            }
            foreach ($selection['schemas'] as $child) {
                $schemas[] = $child;
            }
        }

        // additionalProperties evaluates every property that was not matched
        // by properties/patternProperties at this schema location. Therefore
        // unevaluatedProperties at the same location must not be reapplied to
        // that property, regardless of whether additionalProperties is a
        // boolean or schema form.
        $documentedByOpenKeyword = false;
        if (!$matchedHere && array_key_exists('additionalProperties', $schema)) {
            $documentedByOpenKeyword = true;
            if (is_array($schema['additionalProperties'])) {
                $schemas[] = self::schemaContext(
                    $schema['additionalProperties'],
                    $dialect,
                    $honorSchemaDialectOverride,
                );
            }
        } elseif (
            !$matchedHere &&
            !$documentedByBranch &&
            self::supportsUnevaluatedProperties($dialect) &&
            array_key_exists('unevaluatedProperties', $schema)
        ) {
            $documentedByOpenKeyword = true;
            if (is_array($schema['unevaluatedProperties'])) {
                $schemas[] = self::schemaContext(
                    $schema['unevaluatedProperties'],
                    $dialect,
                    $honorSchemaDialectOverride,
                );
            }
        }

        return [
            'documented' => $matchedHere || $documentedByBranch || $documentedByOpenKeyword,
            'schemas' => $schemas,
        ];
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
