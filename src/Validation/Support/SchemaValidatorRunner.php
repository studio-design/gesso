<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const PHP_INT_MAX;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\Validator;
use stdClass;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function get_object_vars;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function preg_match;
use function property_exists;
use function serialize;
use function sprintf;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SchemaValidatorRunner
{
    /**
     * Bound the canonical schema set retained by this runner. Opis keeps every
     * parsed schema object in a SplObjectStorage cache, so accepting a fresh
     * but equivalent stdClass on every validation grows memory linearly with
     * the number of assertions. Reusing one canonical object per schema lets
     * Opis reuse its parsed representation instead.
     *
     * A runner is normally shared by one request or response validator. 1,024
     * entries covers large multi-spec suites while keeping dynamically-created
     * schema workloads bounded. Reaching the limit clears both sides of the
     * cache atomically so Opis cannot retain objects we no longer own.
     */
    private const MAX_CANONICAL_SCHEMAS = 1024;
    private readonly Validator $opisValidator;
    private readonly ErrorFormatter $errorFormatter;

    /** @var array<string, object> SHA-256 of the serialized converted schema => canonical schema object */
    private array $canonicalSchemas = [];

    public function __construct(int $maxErrors)
    {
        if ($maxErrors < 0) {
            throw new InvalidArgumentException(
                sprintf('maxErrors must be 0 (unlimited) or a positive integer, got %d.', $maxErrors),
            );
        }

        $resolvedMaxErrors = $maxErrors === 0 ? PHP_INT_MAX : $maxErrors;
        $this->opisValidator = new Validator(
            max_errors: $resolvedMaxErrors,
            stop_at_first_error: $resolvedMaxErrors === 1,
        );
        // Converted schemas always carry an explicit `$schema`: Draft 07 for
        // OAS 3.0 and the selected native dialect for OAS 3.1/3.2. Keep the
        // internal runner's bare-schema fallback at Draft 07 for compatibility
        // with callers and tests that exercise it directly.
        $this->opisValidator->parser()->setDefaultDraftVersion('07');
        $this->errorFormatter = new ErrorFormatter();
    }

    /**
     * Validate `$data` against `$jsonSchema` (typically both converted via
     * {@see ObjectConverter::convert()}, although opis also accepts `true` /
     * `false` top-level schemas and raw scalars) and return a map of JSON
     * Pointer path → list of human-readable error messages.
     *
     * An empty array means the data validated successfully. The pointer key
     * matches opis's ErrorFormatter output, with `/` indicating the document
     * root. Success is determined by `ValidationResult::isValid()` rather
     * than by the formatter output shape, so a future opis change that
     * returned `[]` for a suppressed/filtered error would still be reported
     * as a failure (no silent pass).
     *
     * @return array<string, string[]>
     */
    public function validate(mixed $jsonSchema, mixed $data): array
    {
        $grouped = [];
        foreach ($this->validateStructured($jsonSchema, $data) as $violation) {
            $grouped[$violation->displayPath()][] = $violation->message;
        }

        return $grouped;
    }

    /**
     * Structured variant of {@see self::validate()}: same violations, same
     * order (the pointer-keyed map above is derived from this list, so the
     * two views can never drift), but each entry keeps the instance pointer
     * and failing keyword as fields instead of baking the pointer into a
     * `[{pointer}] {message}` prefix. Runs through the same cascade-dedup
     * pipeline (issue #159).
     *
     * Pointers here are RFC 6901 (`''` = document root, `'/'` = the
     * empty-string key) — unlike the map view, which keeps opis's legacy
     * rendering of both as `'/'` ({@see SchemaViolation::displayPath()}).
     *
     * @return list<SchemaViolation>
     */
    public function validateStructured(mixed $jsonSchema, mixed $data): array
    {
        $jsonSchema = $this->canonicalSchema($jsonSchema);
        $result = $this->opisValidator->validate($data, $jsonSchema);

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
        /** @var array<string, list<array{message: string, keyword: string}>> $formatted */
        $formatted = $this->errorFormatter->format(
            $error,
            true,
            fn(ValidationError $entryError, ?string $message = null): array => [
                'message' => $this->errorFormatter->formatErrorMessage($entryError, $message),
                'keyword' => $entryError->keyword(),
            ],
            static fn(ValidationError $entryError): string => self::instancePointer($entryError->data()->fullPath()),
        );

        $violations = [];
        foreach (self::applyCascadeActions($formatted, $cascadeActions) as $path => $entries) {
            foreach ($entries as $entry) {
                $violations[] = new SchemaViolation($path, $entry['keyword'], $entry['message']);
            }
        }

        return $violations;
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
     * @param array<string, list<array{message: string, keyword: string}>> $errors
     * @param array<string, list<string>> $actions
     *
     * @return array<string, list<array{message: string, keyword: string}>>
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
                    'message' => sprintf(
                        'Additional object properties are not allowed: %s',
                        implode(', ', $real),
                    ),
                    'keyword' => $entry['keyword'],
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

    /**
     * Return the stable object identity Opis uses for its parsed-schema cache.
     *
     * Callers intentionally convert PHP schema arrays to stdClass before
     * every validation. Object equality is not enough for Opis: its loader is
     * keyed by identity, so equivalent fresh objects are parsed and retained
     * independently. A content fingerprint maps them back to one canonical
     * object without changing validation semantics. Boolean schemas bypass
     * the cache because Opis does not retain them by object identity.
     */
    private function canonicalSchema(mixed $schema): mixed
    {
        if (!is_object($schema)) {
            return $schema;
        }

        $fingerprint = hash('sha256', serialize($schema));
        if (isset($this->canonicalSchemas[$fingerprint])) {
            return $this->canonicalSchemas[$fingerprint];
        }

        if (count($this->canonicalSchemas) >= self::MAX_CANONICAL_SCHEMAS) {
            $this->canonicalSchemas = [];
            $this->opisValidator->loader()->clearCache();
        }

        $this->canonicalSchemas[$fingerprint] = $schema;

        return $schema;
    }
}
