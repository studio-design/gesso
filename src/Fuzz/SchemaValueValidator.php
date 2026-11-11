<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use stdClass;

use function array_key_first;
use function count;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;
use function strlen;

/** @internal */
final class SchemaValueValidator
{
    /** @var list<string> */
    private const SCHEMA_MAP_KEYWORDS = [
        'properties',
        'patternProperties',
        'dependentSchemas',
        '$defs',
        'definitions',
    ];

    /** @var list<string> */
    private const SCHEMA_LIST_KEYWORDS = [
        'allOf',
        'anyOf',
        'oneOf',
        'prefixItems',
    ];

    /** @var list<string> */
    private const SCHEMA_VALUE_KEYWORDS = [
        'items',
        'additionalItems',
        'additionalProperties',
        'contains',
        'not',
        'if',
        'then',
        'else',
        'propertyNames',
        'contentSchema',
        'unevaluatedProperties',
        'unevaluatedItems',
    ];

    /**
     * Budget for the schemas kept parsed: the summed length of their JSON,
     * plus {@see self::CACHED_ENTRY_BYTES} for each.
     *
     * The generators check every candidate value, mostly against a schema
     * they have checked before, and opis parses a schema again unless it is
     * handed the same object (issue #726). A parsed schema retains up to 58
     * times its JSON length in the measurements of
     * `docs/benchmarks/fuzz-value-checks.md`, which puts a full cache at
     * about 14 MB. Over budget, the least recently used schemas are dropped
     * one at a time and parsed again when they come back; a single schema
     * larger than the budget is still kept while it is the latest one.
     */
    private const MAX_CACHED_BYTES = 256 * 1024;

    /** Charged per cached schema on top of its length: a tiny schema still retains about 3.4 KB. */
    private const CACHED_ENTRY_BYTES = 128;

    /**
     * Each entry owns an opis validator that has loaded exactly one schema.
     * A loader resolves a root schema by its `$id` before it looks at the
     * object, and the generators check a value against variants of one schema
     * that keep its `$id`: one loader for all of them would answer for a
     * variant with the schema it parsed first.
     *
     * @var array<string, array{Validator, mixed}> schema JSON => [validator, decoded schema], least recently used first
     */
    private static array $cache = [];
    private static int $cachedBytes = 0;

    /** Stateless, and the costly part of building an opis validator, so every loader shares it. */
    private static ?SchemaParser $parser = null;

    /** @param array<string, mixed> $schema */
    public static function isValid(mixed $value, array $schema): bool
    {
        $instance = json_decode((string) json_encode($value));
        $json = (string) json_encode(self::schemaObject($schema));
        $entry = self::$cache[$json] ?? null;
        [$validator, $jsonSchema] = $entry ?? [
            new Validator(new SchemaLoader(self::$parser ??= new SchemaParser(), new SchemaResolver(), true)),
            json_decode($json),
        ];
        $valid = $validator->validate($instance, $jsonSchema)->isValid();

        // Only after opis accepted the schema, so one it refuses to load is
        // refused again on the next call. Re-appending keeps the map ordered
        // least recently used first.
        unset(self::$cache[$json]);
        self::$cache[$json] = [$validator, $jsonSchema];
        if ($entry === null) {
            self::$cachedBytes += strlen($json) + self::CACHED_ENTRY_BYTES;
            while (self::$cachedBytes > self::MAX_CACHED_BYTES && count(self::$cache) > 1) {
                $oldest = (string) array_key_first(self::$cache);
                self::$cachedBytes -= strlen($oldest) + self::CACHED_ENTRY_BYTES;
                unset(self::$cache[$oldest]);
            }
        }

        return $valid;
    }

    /** Forget every parsed schema. */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$cachedBytes = 0;
    }

    /** @param array<string, mixed> $schema */
    public static function assertValid(mixed $value, array $schema, int $iteration): void
    {
        if (self::isValid($value, $schema)) {
            return;
        }

        throw new FuzzGenerationException(sprintf(
            'Internal fuzz generator defect: valid case %d does not satisfy its converted JSON Schema.',
            $iteration,
        ), $iteration);
    }

    /**
     * PHP uses `[]` for both an empty mapping and an empty list. Restore JSON
     * objects at schema-valued keyword positions before handing the converted
     * schema to opis; opaque values and list-valued keywords remain untouched.
     *
     * @param array<string, mixed> $schema
     */
    private static function schemaObject(array $schema): stdClass
    {
        $object = new stdClass();

        foreach ($schema as $key => $value) {
            if (in_array($key, self::SCHEMA_MAP_KEYWORDS, true) && is_array($value)) {
                $object->{$key} = self::schemaMap($value);

                continue;
            }

            if (in_array($key, self::SCHEMA_LIST_KEYWORDS, true) && is_array($value)) {
                $items = [];
                foreach ($value as $item) {
                    $items[] = is_array($item) ? self::schemaObject($item) : $item;
                }
                $object->{$key} = $items;

                continue;
            }

            if (in_array($key, self::SCHEMA_VALUE_KEYWORDS, true) && is_array($value)) {
                $object->{$key} = self::schemaObject($value);

                continue;
            }

            $object->{$key} = $value;
        }

        return $object;
    }

    /**
     * @param array<array-key, mixed> $schemas
     */
    private static function schemaMap(array $schemas): stdClass
    {
        $object = new stdClass();

        foreach ($schemas as $name => $schema) {
            $object->{(string) $name} = is_array($schema) ? self::schemaObject($schema) : $schema;
        }

        return $object;
    }
}
