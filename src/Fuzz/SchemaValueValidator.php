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
use function max;
use function memory_get_usage;
use function sprintf;
use function strlen;

/** @internal */
final class SchemaValueValidator
{
    /**
     * Memory the parsed schemas may hold, in bytes.
     *
     * The generators check every candidate value, mostly against a schema
     * they have checked before, and opis parses a schema again unless it is
     * handed the same object (issue #726). Over budget, the least recently
     * used schemas are dropped one at a time and parsed again when they come
     * back; a single schema larger than the budget is still kept while it is
     * the latest one. `docs/benchmarks/fuzz-value-checks.md` has what the
     * budget covers.
     */
    public const DEFAULT_MAX_CACHED_BYTES = 16 * 1024 * 1024;

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

    /** Charged per cached schema with its JSON length: the map key and the entry, which no measurement sees. */
    private const CACHED_ENTRY_BYTES = 128;

    /**
     * Each entry owns an opis validator that has loaded exactly one schema.
     * A loader resolves a root schema by its `$id` before it looks at the
     * object, and the generators check a value against variants of one schema
     * that keep its `$id`: one loader for all of them would answer for a
     * variant with the schema it parsed first.
     *
     * @var array<string, array{Validator, mixed, int}> schema JSON => [validator, decoded schema, bytes charged], least recently used first
     */
    private static array $cache = [];
    private static int $cachedBytes = 0;
    private static int $maxCachedBytes = self::DEFAULT_MAX_CACHED_BYTES;

    /** Stateless, and the costly part of building an opis validator, so every loader shares it. */
    private static ?SchemaParser $parser = null;

    /** @param array<string, mixed> $schema */
    public static function isValid(mixed $value, array $schema): bool
    {
        $instance = json_decode((string) json_encode($value));
        $json = (string) json_encode(self::schemaObject($schema));
        $entry = self::$cache[$json] ?? null;
        self::$parser ??= new SchemaParser();

        // What a parsed schema holds follows no length: opis parses a
        // subschema when a value first reaches it, so the same JSON costs 8 to
        // 58 times its length. Each check is charged what it left behind,
        // which for the first ones of a process includes the opis classes
        // they load: an overcharge that ends when the entry is dropped.
        $before = memory_get_usage();
        [$validator, $jsonSchema] = $entry ?? [
            new Validator(new SchemaLoader(self::$parser, new SchemaResolver(), true)),
            json_decode($json),
        ];
        $valid = $validator->validate($instance, $jsonSchema)->isValid();
        $bytes = ($entry[2] ?? strlen($json) + self::CACHED_ENTRY_BYTES) + max(0, memory_get_usage() - $before);

        // Only after opis accepted the schema, so one it refuses to load is
        // refused again on the next call. Re-appending keeps the map ordered
        // least recently used first.
        unset(self::$cache[$json]);
        self::$cache[$json] = [$validator, $jsonSchema, $bytes];
        self::$cachedBytes += $bytes - ($entry[2] ?? 0);
        while (self::$cachedBytes > self::$maxCachedBytes && count(self::$cache) > 1) {
            $oldest = (string) array_key_first(self::$cache);
            self::$cachedBytes -= self::$cache[$oldest][2];
            unset(self::$cache[$oldest]);
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
