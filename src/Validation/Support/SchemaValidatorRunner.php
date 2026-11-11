<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_INT_MAX;

use Closure;
use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use stdClass;
use Studio\Gesso\Internal\CurlCommandFormatter;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_values;
use function count;
use function get_object_vars;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;
use function json_encode;
use function preg_match;
use function preg_replace;
use function property_exists;
use function serialize;
use function sprintf;
use function strlen;

/**
 * @phpstan-type FormattedEntry array{message: string, keyword: string, schema_pointer: ?string, actual: ?string, expected: ?string}
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SchemaValidatorRunner
{
    /**
     * Budget for the schemas the process keeps parsed, across every runner:
     * the summed `serialize()` length of the converted schemas, plus
     * {@see self::SHARED_ENTRY_BYTES} for each.
     *
     * Validators live per test-case instance, so a cache on the runner made
     * every test parse again each schema it touched (issue #723). A parsed
     * schema stays in memory for as long as it is cached, though, and what it
     * retains follows its serialized length rather than the number of
     * schemas: up to 28 times the bytes charged here in the measurements of
     * `docs/benchmarks/shared-schema-cache.md`, which puts a full cache at
     * about 14 MB. Over budget, the least recently used schemas are dropped
     * one at a time and parsed again when they come back; a single schema
     * larger than the budget is still kept while it is the latest one.
     */
    private const MAX_SHARED_BYTES = 512 * 1024;

    /** Charged per cached schema on top of its length: a tiny schema still retains about 3.5 KB. */
    private const SHARED_ENTRY_BYTES = 128;

    /**
     * Most schema locations remembered at once; over it, the oldest is
     * forgotten and takes the fingerprint path again. A full
     * {@see self::$shared} holds fewer schemas than this, and a full map
     * retains about 0.9 MB (`docs/benchmarks/schema-location-cache.md`).
     */
    private const MAX_LOCATIONS = 4096;

    /** Longest `actual` / `expected` excerpt, in characters, before it is cut with `…`. */
    private const MAX_EXCERPT = 80;

    /**
     * Each entry owns an opis validator that has loaded exactly one schema.
     * Opis can only empty a loader as a whole, and a loader resolves schemas
     * by `$id`: one loader for all schemas could neither drop a single entry
     * nor tell apart two schemas that declare the same `$id`.
     *
     * @var array<string, array{Validator, object, int}> "<resolved maxErrors>:<SHA-256 of the serialized converted schema>" => [validator, canonical schema object, bytes charged], least recently used first
     */
    private static array $shared = [];
    private static int $sharedBytes = 0;

    /**
     * Where in a loaded spec a schema sits, to the entry that holds it
     * converted and parsed: a hit skips the conversion, the object conversion
     * and the fingerprint, which together cost more than the lookup they feed
     * (issue #724). Several locations may share one entry.
     *
     * @var array<string, string> "<resolved maxErrors>:<xxh128 of the location>" => key of self::$shared, oldest first
     */
    private static array $locations = [];

    /** Stateless, and the costly part of building an opis validator, so every loader shares it. */
    private static ?SchemaParser $parser = null;
    private readonly int $maxErrors;
    private readonly ErrorFormatter $errorFormatter;

    public function __construct(int $maxErrors)
    {
        if ($maxErrors < 0) {
            throw new InvalidArgumentException(
                sprintf('maxErrors must be 0 (unlimited) or a positive integer, got %d.', $maxErrors),
            );
        }

        $this->maxErrors = $maxErrors === 0 ? PHP_INT_MAX : $maxErrors;
        $this->errorFormatter = new ErrorFormatter();
    }

    /**
     * Forget every parsed schema. {@see OpenApiSpecLoader} calls this wherever
     * it drops its cached specs, so a parsed schema never outlives the spec it
     * came from.
     */
    public static function clearShared(): void
    {
        self::$shared = [];
        self::$sharedBytes = 0;
        self::$locations = [];
    }

    /**
     * Validate `$data` against `$jsonSchema` (typically both converted via
     * {@see ObjectConverter::convert()}; opis also accepts `true` / `false`
     * top-level schemas and raw scalars). Returns one {@see SchemaViolation}
     * per reported error after the cascade-dedup pipeline (issue #159); `[]`
     * means valid. Pointers are RFC 6901 (`''` root), while
     * {@see SchemaViolation::displayPath()} keeps the legacy `'/'` rendering
     * used in flat error prose.
     *
     * @return list<SchemaViolation>
     */
    public function validateStructured(mixed $jsonSchema, mixed $data): array
    {
        return $this->validateAt(null, static fn(): mixed => $jsonSchema, $data);
    }

    /**
     * {@see self::validateStructured()} for a schema that sits at a known
     * place in a spec {@see OpenApiSpecLoader} has cached. `$schema` builds
     * the converted schema object and only runs when `$location` is not
     * remembered, so a repeat validation neither converts nor fingerprints.
     *
     * `$location` must determine the result of `$schema`: the spec name, the
     * place in it, and every argument the conversion takes. `null` keeps the
     * fingerprint path.
     *
     * @param Closure(): mixed $schema
     *
     * @return list<SchemaViolation>
     */
    public function validateAt(?string $location, Closure $schema, mixed $data): array
    {
        // Hashed only to keep the map small: a location runs to a few hundred bytes.
        $location = $location === null ? null : $this->maxErrors . ':' . hash('xxh128', $location);
        $key = $location === null ? null : self::$locations[$location] ?? null;
        $entry = $key === null ? null : self::$shared[$key] ?? null;
        $jsonSchema = null;
        $serialized = '';
        if ($entry === null) {
            // Callers convert the schema to a fresh object graph on every
            // call, and opis caches a parsed schema by object identity. The
            // content fingerprint maps equivalent graphs back to the one
            // already parsed. Boolean schemas are not objects and are never
            // kept.
            $jsonSchema = $schema();
            $key = null;
            if (is_object($jsonSchema)) {
                $serialized = serialize($jsonSchema);
                $key = $this->maxErrors . ':' . hash('sha256', $serialized);
                $entry = self::$shared[$key] ?? null;
            }
        }
        [$validator, $jsonSchema] = $entry ?? [$this->newValidator(), $jsonSchema];
        $result = $validator->validate($data, $jsonSchema);

        if ($key !== null) {
            // Only after opis accepted the schema, so one it refuses to load
            // is refused again on the next call. Re-appending keeps the map
            // ordered least recently used first.
            unset(self::$shared[$key]);
            self::$shared[$key] = $entry ?? [$validator, $jsonSchema, strlen($serialized) + self::SHARED_ENTRY_BYTES];
            if ($entry === null) {
                self::$sharedBytes += self::$shared[$key][2];
                while (self::$sharedBytes > self::MAX_SHARED_BYTES && count(self::$shared) > 1) {
                    $oldest = array_key_first(self::$shared);
                    self::$sharedBytes -= self::$shared[$oldest][2];
                    unset(self::$shared[$oldest]);
                }
            }
            if ($location !== null && !isset(self::$locations[$location])) {
                if (count(self::$locations) >= self::MAX_LOCATIONS) {
                    unset(self::$locations[array_key_first(self::$locations)]);
                }
                self::$locations[$location] = $key;
            }
        }

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();
        if ($error === null) {
            // Defensive: ValidationResult::isValid() is defined as
            // `$this->error === null`, so this branch is unreachable today.
            // Return a synthetic entry rather than letting a null slip to
            // ErrorFormatter::format() and producing a TypeError, so the
            // validator still surfaces *something* if the opis invariant
            // ever changes.
            return [new SchemaViolation('', null, 'Schema validation failed but opis reported no error detail.')];
        }

        $cascadeActions = self::computeCascadeActions($error);

        // Custom formatter callable so each entry keeps its keyword next to
        // the interpolated message; the custom key formatter produces RFC
        // 6901 pointers (root = '') instead of opis's default, which renders
        // the root and the empty-string key identically as '/'. Grouping and
        // order are otherwise exactly what `validate()` has always produced.
        /** @var array<string, list<FormattedEntry>> $formatted */
        $formatted = $this->errorFormatter->format(
            $error,
            true,
            fn(ValidationError $entryError, ?string $message = null): array => [
                'message' => $this->errorFormatter->formatErrorMessage($entryError, $message),
                'keyword' => $entryError->keyword(),
                ...self::context($entryError),
            ],
            static fn(ValidationError $entryError): string => self::instancePointer($entryError->data()->fullPath()),
        );

        $violations = [];
        foreach (self::applyCascadeActions($formatted, $cascadeActions) as $path => $entries) {
            foreach ($entries as $entry) {
                $violations[] = new SchemaViolation(
                    $path,
                    $entry['keyword'],
                    $entry['message'],
                    $entry['schema_pointer'],
                    $entry['actual'],
                    $entry['expected'],
                );
            }
        }

        return $violations;
    }

    /**
     * Convert an OpenAPI Schema Object, validate `$value` against it, and
     * render every violation as a {@see NamedError} for the named spec object
     * (`$prefix` is the bracket label, e.g. `query.limit`; the violation's
     * display path is appended to it unless the violation sits at the root).
     *
     * `$scope` names the spec object that owns `$prefix` (an operation, or one
     * of its responses) in a spec {@see OpenApiSpecLoader} has cached, and
     * lets the schema be remembered by location ({@see self::validateAt()}).
     * Pass it only when `$schema` was read from that cached spec.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<NamedError>
     */
    public function validateNamed(
        string $name,
        string $prefix,
        array $schema,
        mixed $value,
        OpenApiVersion $version,
        SchemaContext $context,
        ?string $jsonSchemaDialect = null,
        ?string $scope = null,
    ): array {
        $violations = $this->validateAt(
            $scope === null ? null : serialize([$scope, $prefix, $version, $context, $jsonSchemaDialect]),
            static fn(): mixed => ObjectConverter::convert(OpenApiSchemaConverter::convert($schema, $version, $context, null, $jsonSchemaDialect)),
            ObjectConverter::convert($value),
        );

        $errors = [];
        foreach ($violations as $violation) {
            $suffix = $violation->displayPath() === '/' ? '' : $violation->displayPath();
            // The value of a credential-like parameter or header is never echoed.
            if ($violation->actual !== null && CurlCommandFormatter::isSensitiveName($name)) {
                $violation = $violation->withActual(CurlCommandFormatter::REDACTED_PLACEHOLDER);
            }
            $errors[] = new NamedError($name, "[{$prefix}{$suffix}] {$violation->message}", $violation->instancePath, $violation->keyword, $violation);
        }

        return $errors;
    }

    /**
     * Where in the validated schema the error was raised, and the failing and
     * expected values as short JSON excerpts (v3 vision item A3).
     *
     * `schema_pointer` is an RFC 6901 pointer into the schema the validator
     * ran — the operation's resolved schema, after `$ref` inlining and OpenAPI
     * 3.0 conversion — ending at the failing keyword when that keyword is
     * present there. `actual` is the JSON encoding of a scalar failing value;
     * objects and arrays are left out (`null`), and a value under a
     * credential-like property name or a `format: password` schema is
     * replaced by `<redacted>`. `expected` is the JSON encoding of the
     * keyword's value when that value is a scalar or a list of scalars; a
     * subschema (`oneOf`, `items`, ...) is left out.
     *
     * @return array{schema_pointer: ?string, actual: ?string, expected: ?string}
     */
    private static function context(ValidationError $error): array
    {
        $keyword = $error->keyword();
        $schema = $error->schema()->info()->data();
        $declared = $schema instanceof stdClass && property_exists($schema, $keyword);
        $segments = $error->schema()->info()->path();
        if ($declared) {
            $segments[] = $keyword;
        }

        $value = $error->data()->value();
        $actual = null;
        if ($value === null || is_scalar($value)) {
            $sensitive = $schema instanceof stdClass && ($schema->format ?? null) === 'password';
            foreach ($error->data()->fullPath() as $segment) {
                $sensitive = $sensitive || (is_string($segment) && CurlCommandFormatter::isSensitiveName($segment));
            }
            $actual = $sensitive ? CurlCommandFormatter::REDACTED_PLACEHOLDER : self::excerpt($value);
        }

        $expected = null;
        if ($declared) {
            $constraint = $schema->{$keyword};
            $scalarList = is_array($constraint) && array_filter($constraint, static fn(mixed $item): bool => $item !== null && !is_scalar($item)) === [];
            if ($constraint === null || is_scalar($constraint) || $scalarList) {
                $expected = self::excerpt($constraint);
            }
        }

        return ['schema_pointer' => self::instancePointer($segments), 'actual' => $actual, 'expected' => $expected];
    }

    private static function excerpt(mixed $value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if ($encoded === false) {
            return null;
        }

        return preg_replace('/^(.{' . self::MAX_EXCERPT . '}).+$/su', '$1…', $encoded) ?? $encoded;
    }

    /**
     * Render raw data-path segments as an RFC 6901 JSON Pointer. Non-empty
     * paths match opis's `JsonPointer::pathToString()` byte-for-byte; the
     * empty path becomes `''` (the RFC root pointer) where opis would return
     * `'/'` — which is also what it returns for the empty-string key, an
     * ambiguity the structured output must not inherit.
     *
     * @param array<int, mixed> $segments
     */
    private static function instancePointer(array $segments): string
    {
        return $segments === [] ? '' : JsonPointer::pathToString($segments);
    }

    /**
     * Walk the opis ValidationError tree, identify `additionalProperties`
     * errors that are cascade artifacts of opis's `PropertiesKeyword` skipping
     * its `addCheckedProperties()` call when any sub-property fails (see issue
     * #159 for the upstream root cause), and return a per-path map of which
     * property names ARE genuinely additional — i.e. survive the dedup.
     *
     * Empty list at a path = whole `additionalProperties` message will be
     * dropped at format-time. Path absent from the map = no cascade action
     * (message kept as-is). The map is consumed by {@see applyCascadeActions()}.
     *
     * Detection is structural — the listed property names come straight from
     * `ValidationError::args()['properties']` (the raw array opis populates
     * before string interpolation), and declared property names come from the
     * schema object that raised the error, via `ValidationError::schema()`.
     * Neither step parses or trims the rendered error message, so property
     * names containing commas / spaces / empty strings /
     * JSON-Pointer-escape-worthy characters all compare correctly against the
     * declared set.
     *
     * Reading the *raising* schema is what makes composition safe. Under
     * `allOf` / `oneOf` / `anyOf` several schemas constrain one instance
     * location, each with `properties` of its own, so a name declared by the
     * enclosing schema can be genuinely additional to the branch that
     * complained — locating the schema by walking the instance path from the
     * root would cancel that real violation.
     *
     * Degrades safely: a boolean or otherwise non-object raising schema, or
     * one without an object `properties`, yields no entry in the map and the
     * message is kept untouched.
     *
     * @return array<string, list<string>>
     */
    private static function computeCascadeActions(ValidationError $rootError): array
    {
        $actions = [];
        self::collectCascadeActions($rootError, $actions);

        return $actions;
    }

    /**
     * @param array<string, list<string>> $actions
     */
    private static function collectCascadeActions(ValidationError $error, array &$actions): void
    {
        if ($error->keyword() === 'additionalProperties') {
            $listed = $error->args()['properties'] ?? null;
            if (is_array($listed)) {
                $segments = $error->data()->fullPath();
                $declared = self::declaredPropertyNames($error->schema());
                if ($declared !== null) {
                    $real = array_values(array_filter(
                        $listed,
                        static fn(mixed $name): bool => is_string($name) && !in_array($name, $declared, true),
                    ));

                    // Only record an action when the cascade actually
                    // contracts the list — pure-real-additionals report
                    // unchanged so we don't pay the format-pass cost or
                    // risk re-encoding the message.
                    if (count($real) < count($listed)) {
                        $actions[self::instancePointer($segments)] = $real;
                    }
                }
            }
        }

        foreach ($error->subErrors() as $sub) {
            self::collectCascadeActions($sub, $actions);
        }
    }

    /**
     * The property names the schema that raised the error declares itself.
     * `null` when it declares none the dedup can compare against — a boolean
     * schema, or an object without an object-shaped `properties` — in which
     * case the message is left exactly as opis rendered it.
     *
     * @return null|list<string>
     */
    private static function declaredPropertyNames(Schema $schema): ?array
    {
        $data = $schema->info()->data();

        if (!$data instanceof stdClass ||
            !property_exists($data, 'properties') ||
            !$data->properties instanceof stdClass
        ) {
            return null;
        }

        return array_keys(get_object_vars($data->properties));
    }

    /**
     * Apply the cascade-action map to the formatted errors. For each entry in
     * the map, find the `additionalProperties` line at that path and either
     * drop it (when the kept list is empty) or rewrite it with only the
     * genuinely-additional names. Sibling messages at the same path (e.g. a
     * `required` failure that fired in the same object) are preserved.
     *
     * The cascade target is identified structurally (`keyword ===
     * 'additionalProperties'`) AND by opis's English template (`Additional
     * object properties are not allowed: ...`) — the rewrite re-renders that
     * template, so it must not fire on a message whose wording it cannot
     * reproduce. If opis ever rewords the template, the regex stops matching
     * and the messages are left unchanged — fail-safe in the noisy direction
     * (no silent suppression of real violations). The property-name
     * comparison itself is fully structural.
     *
     * @param array<string, list<FormattedEntry>> $errors
     * @param array<string, list<string>> $actions
     *
     * @return array<string, list<FormattedEntry>>
     */
    private static function applyCascadeActions(array $errors, array $actions): array
    {
        if ($actions === []) {
            return $errors;
        }

        foreach ($errors as $path => $entries) {
            if (!array_key_exists($path, $actions)) {
                continue;
            }

            $real = $actions[$path];
            $kept = [];
            foreach ($entries as $entry) {
                if ($entry['keyword'] !== 'additionalProperties' ||
                    preg_match('/^Additional object properties are not allowed: /', $entry['message']) !== 1
                ) {
                    $kept[] = $entry;

                    continue;
                }

                if ($real === []) {
                    // Whole message is cascade artifact — suppress.
                    continue;
                }

                $kept[] = [
                    ...$entry,
                    'message' => sprintf(
                        'Additional object properties are not allowed: %s',
                        implode(', ', $real),
                    ),
                ];
            }

            if ($kept === []) {
                unset($errors[$path]);
            } else {
                $errors[$path] = $kept;
            }
        }

        return $errors;
    }

    private function newValidator(): Validator
    {
        if (self::$parser === null) {
            self::$parser = new SchemaParser();
            // Converted schemas always carry an explicit `$schema`: Draft 07
            // for OAS 3.0 and the selected native dialect for OAS 3.1/3.2.
            // Keep the internal runner's bare-schema fallback at Draft 07 for
            // compatibility with callers and tests that exercise it directly.
            self::$parser->setDefaultDraftVersion('07');
        }

        return new Validator(
            new SchemaLoader(self::$parser, new SchemaResolver(), true),
            $this->maxErrors,
            $this->maxErrors === 1,
        );
    }
}
