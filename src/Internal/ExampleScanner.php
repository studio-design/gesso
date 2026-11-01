<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const ARRAY_FILTER_USE_KEY;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\DiscriminatorContext;
use Studio\Gesso\Validation\Support\FormBodyDecoder;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\SchemaViolation;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function hash;
use function in_array;
use function is_array;
use function is_string;
use function serialize;
use function str_replace;
use function str_starts_with;

/**
 * Find `example` / `examples` values in a resolved spec that do not validate
 * against their own schema, for `gesso doctor` (example drift).
 *
 * Values are checked with the runtime pipeline — {@see OpenApiSchemaConverter}
 * and {@see SchemaValidatorRunner} with the document's version, dialect and
 * discriminator enforcement — so doctor and runtime cannot disagree. Media
 * types the runtime does not validate (non-JSON, non-form) are not checked,
 * and `externalValue` is never fetched.
 *
 * @internal Implementation detail of `gesso doctor`.
 */
final class ExampleScanner
{
    /** Schema keywords holding one subschema. */
    private const SUBSCHEMA = ['items', 'additionalItems', 'additionalProperties', 'not', 'if', 'then', 'else', 'contains', 'propertyNames', 'unevaluatedItems', 'unevaluatedProperties', 'contentSchema'];

    /** Schema keywords holding a map of subschemas. */
    private const SUBSCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /** Schema keywords holding a list of subschemas. */
    private const SUBSCHEMA_LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems', 'items'];
    private readonly SchemaValidatorRunner $runner;

    /** @var array<string, true> */
    private array $seen = [];

    /** @var list<array{pointer: string, violation: SchemaViolation}> */
    private array $drift = [];

    private function __construct(
        private readonly OpenApiVersion $version,
        private readonly string $dialect,
        private readonly DiscriminatorContext $discriminator,
    ) {
        $this->runner = new SchemaValidatorRunner(ValidationPolicyDefaults::maxErrors());
    }

    /**
     * Every drifting example, keyed by its JSON pointer in the spec, with the
     * first violation. The loader inlines `$ref` targets, so `$raw` (the same
     * document before resolution) decides where an example is defined: a
     * branch that is an internal `$ref` in `$raw` is reported at its target
     * only, while a copied example is reported at every place it appears.
     * Below an external-file `$ref` the raw shape is unknown, and an
     * identical (side, schema, value) is reported once.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $raw
     *
     * @return list<array{pointer: string, violation: SchemaViolation}>
     */
    public static function scan(array $spec, array $raw, OpenApiVersion $version, string $dialect, DiscriminatorContext $discriminator): array
    {
        $scanner = new self($version, $dialect, $discriminator);
        // Definitions first, so a report lists components before their users.
        $scanner->walk(['components' => $spec['components'] ?? []], '', null, ['components' => $raw['components'] ?? []]);
        unset($spec['components'], $raw['components']);
        $scanner->walk($spec, '', null, $raw);

        return $scanner->drift;
    }

    private static function pointer(string $parent, int|string $key): string
    {
        return $parent . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
    }

    /**
     * The direct subschemas of `$schema`: the keyword, the map key or list
     * index under it (`null` for a single subschema), and the subschema.
     *
     * @param array<mixed> $schema
     *
     * @return iterable<array{0: string, 1: null|int|string, 2: array<mixed>}>
     */
    private static function subschemas(array $schema): iterable
    {
        foreach (self::SUBSCHEMA as $keyword) {
            if (is_array($schema[$keyword] ?? null) && !array_is_list($schema[$keyword])) {
                yield [$keyword, null, $schema[$keyword]];
            }
        }
        foreach ([...self::SUBSCHEMA_MAPS, ...self::SUBSCHEMA_LISTS] as $keyword) {
            $children = $schema[$keyword] ?? null;
            if (!is_array($children) || (in_array($keyword, self::SUBSCHEMA_LISTS, true) && !array_is_list($children))) {
                continue;
            }
            foreach ($children as $name => $child) {
                if (is_array($child)) {
                    yield [$keyword, $name, $child];
                }
            }
        }
    }

    /**
     * `$schema` without `readOnly` / `writeOnly` on it or any subschema, so
     * the converter forbids no property in either context.
     *
     * @param array<mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function withoutAccessMarkers(array $schema): array
    {
        unset($schema['readOnly'], $schema['writeOnly']);
        foreach (self::subschemas($schema) as [$keyword, $name, $child]) {
            if ($name === null) {
                $schema[$keyword] = self::withoutAccessMarkers($child);
            } else {
                $schema[$keyword][$name] = self::withoutAccessMarkers($child);
            }
        }

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * The raw counterpart of `$raw[$key]`: false when it is an internal
     * `$ref` (reported at its target instead), null when unknown (below an
     * external-file `$ref`). A schema node keeps its internal `$ref` so
     * {@see self::walkSchema()} can still check `example` siblings.
     *
     * @param null|array<mixed> $raw
     *
     * @return null|array<mixed>|false
     */
    private static function rawChild(?array $raw, string $key, bool $schemaNode = false): null|array|false
    {
        if ($raw === null) {
            return null;
        }
        $child = $raw[$key] ?? null;
        if (!is_array($child)) {
            return null;
        }
        $ref = $child['$ref'] ?? null;
        if (!is_string($ref)) {
            return $child;
        }
        if (!str_starts_with($ref, '#')) {
            return null;
        }

        return $schemaNode ? $child : false;
    }

    /** @param array<mixed> $raw */
    private static function isInternalRef(array $raw): bool
    {
        return is_string($raw['$ref'] ?? null) && str_starts_with($raw['$ref'], '#');
    }

    /**
     * Walk non-schema spec nodes. `$context` is the side of the exchange the
     * node describes, as runtime validation applies `readOnly` / `writeOnly`.
     *
     * @param array<mixed> $node
     * @param null|array<mixed> $raw `$node` before `$ref` resolution; null when unknown
     */
    private function walk(array $node, string $pointer, ?SchemaContext $context, ?array $raw): void
    {
        foreach ($node as $key => $value) {
            $key = (string) $key;
            if (!is_array($value) || in_array($key, ['example', 'examples', 'itemSchema'], true) || str_starts_with($key, 'x-')) {
                continue;
            }
            $rawChild = self::rawChild($raw, $key, $key === 'schema');
            if ($rawChild === false) {
                continue;
            }
            $child = self::pointer($pointer, $key);
            if ($key === 'schema') {
                $this->walkSchema($value, $child, $rawChild);

                continue;
            }
            if ($key === 'schemas' && $pointer === '/components') {
                foreach ($value as $name => $schema) {
                    $rawSchema = self::rawChild($rawChild, (string) $name, true);
                    if (is_array($schema) && $rawSchema !== false) {
                        $this->walkSchema($schema, self::pointer($child, $name), $rawSchema);
                    }
                }

                continue;
            }
            if ($key === 'content') {
                // Runtime validation evaluates JSON and form bodies only.
                $value = array_filter($value, static function (int|string $mediaType): bool {
                    $normalized = ContentTypeMatcher::normalizeMediaType((string) $mediaType);

                    return ContentTypeMatcher::isJsonContentType($normalized) || FormBodyDecoder::isFormMediaType($normalized);
                }, ARRAY_FILTER_USE_KEY);
            }
            $this->walk($value, $child, match ($key) {
                'responses' => SchemaContext::Response,
                'requestBody', 'requestBodies', 'parameters' => SchemaContext::Request,
                // Response and component headers; encoding headers stay on the request side.
                'headers' => $context ?? SchemaContext::Response,
                default => $context,
            }, $rawChild);
        }

        // A Media Type, Parameter or Header object.
        $schema = $node['schema'] ?? null;
        if (!is_array($schema)) {
            return;
        }
        /** @var array<string, mixed> $schema */
        $context ??= SchemaContext::Response;
        if (array_key_exists('example', $node)) {
            $this->check($schema, $node['example'], self::pointer($pointer, 'example'), $context, $raw === null);
        }
        if (is_array($node['examples'] ?? null)) {
            foreach ($node['examples'] as $name => $example) {
                if (is_array($example) && array_key_exists('value', $example)) {
                    $this->check($schema, $example['value'], self::pointer(self::pointer(self::pointer($pointer, 'examples'), $name), 'value'), $context, $raw === null);
                }
            }
        }
    }

    /**
     * @param array<mixed> $schema
     * @param null|array<mixed> $raw `$schema` before `$ref` resolution; null when unknown
     */
    private function walkSchema(array $schema, string $pointer, ?array $raw): void
    {
        /** @var array<string, mixed> $schema */
        // A 3.1 `$ref` with an `example` sibling: the sibling is this node's
        // own example; everything else belongs to the target.
        $ownsExamples = $raw === null || !self::isInternalRef($raw) || array_key_exists('example', $raw) || array_key_exists('examples', $raw);
        if ($ownsExamples && array_key_exists('example', $schema)) {
            $this->check($schema, $schema['example'], self::pointer($pointer, 'example'), null, $raw === null);
        }
        // `examples` is a Schema keyword from OpenAPI 3.1 (JSON Schema 2020-12) only.
        if ($ownsExamples && $this->version !== OpenApiVersion::V3_0 && is_array($schema['examples'] ?? null) && array_is_list($schema['examples'])) {
            foreach ($schema['examples'] as $index => $example) {
                $this->check($schema, $example, self::pointer(self::pointer($pointer, 'examples'), $index), null, $raw === null);
            }
        }
        if ($raw !== null && self::isInternalRef($raw)) {
            return;
        }
        foreach (self::subschemas($schema) as [$keyword, $name, $child]) {
            $rawContainer = $name === null ? $raw : self::rawChild($raw, $keyword);
            $rawChild = is_array($rawContainer) ? self::rawChild($rawContainer, $name === null ? $keyword : (string) $name, true) : null;
            $this->walkSchema($child, $name === null ? self::pointer($pointer, $keyword) : self::pointer(self::pointer($pointer, $keyword), $name), $rawChild === false ? null : $rawChild);
        }
    }

    /**
     * Validate one example. A Schema-level example (`$context` null)
     * describes the schema in both directions, so its `readOnly` and
     * `writeOnly` properties are both allowed.
     *
     * @param array<string, mixed> $schema
     * @param bool $dedupe below an external-file `$ref`, where copies and shared definitions look alike
     */
    private function check(array $schema, mixed $value, string $pointer, ?SchemaContext $context, bool $dedupe): void
    {
        if ($dedupe) {
            $key = hash('xxh128', serialize([$context?->name, $schema, $value]));
            if (isset($this->seen[$key])) {
                return;
            }
            $this->seen[$key] = true;
        }

        $converted = OpenApiSchemaConverter::convert(
            $context === null ? self::withoutAccessMarkers($schema) : $schema,
            $this->version,
            $context ?? SchemaContext::Response,
            $this->discriminator,
            $this->dialect,
        );
        $violations = $this->runner->validateStructured(ObjectConverter::convert($converted), ObjectConverter::convert($value));
        if ($violations !== []) {
            $this->drift[] = ['pointer' => $pointer, 'violation' => $violations[0]];
        }
    }
}
