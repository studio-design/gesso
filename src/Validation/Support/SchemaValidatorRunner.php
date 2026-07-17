<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const PHP_INT_MAX;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
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
use function is_int;
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
            return ['/' => ['Schema validation failed but opis reported no error detail.']];
        }

        $cascadeActions = self::computeCascadeActions($error, $jsonSchema);

        return self::applyCascadeActions(
            $this->errorFormatter->format($error),
            $cascadeActions,
        );
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
     * before string interpolation), and declared property names come from
     * walking `$jsonSchema` via the error's raw `fullPath()` segments. Neither
     * step parses or trims the rendered error message, so property names
     * containing commas / spaces / empty strings / JSON-Pointer-escape-worthy
     * characters all compare correctly against the declared set.
     *
     * Degrades safely:
     * - Non-object root schema (opis accepts `true`/`false`/scalar) → empty map
     * - Composition keywords (`oneOf` / `allOf` / `anyOf`) routing the data
     *   through an alternate sub-schema → walker returns null at that path,
     *   no entry in the map, message kept untouched.
     *
     * @return array<string, list<string>>
     */
    private static function computeCascadeActions(ValidationError $rootError, mixed $jsonSchema): array
    {
        if (!$jsonSchema instanceof stdClass) {
            return [];
        }

        $actions = [];
        self::collectCascadeActions($rootError, $jsonSchema, $actions);

        return $actions;
    }

    /**
     * @param array<string, list<string>> $actions
     */
    private static function collectCascadeActions(
        ValidationError $error,
        stdClass $rootSchema,
        array &$actions,
    ): void {
        if ($error->keyword() === 'additionalProperties') {
            $listed = $error->args()['properties'] ?? null;
            if (is_array($listed)) {
                $segments = $error->data()->fullPath();
                $declared = self::declaredPropertyNamesAtSegments($rootSchema, $segments);
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
                        $actions[JsonPointer::pathToString($segments)] = $real;
                    }
                }
            }
        }

        foreach ($error->subErrors() as $sub) {
            self::collectCascadeActions($sub, $rootSchema, $actions);
        }
    }

    /**
     * Apply the cascade-action map to the formatted errors. For each entry in
     * the map, find the `additionalProperties` line at that path and either
     * drop it (when the kept list is empty) or rewrite it with only the
     * genuinely-additional names. Sibling messages at the same path (e.g. a
     * `required` failure that fired in the same object) are preserved.
     *
     * The detection of which message line is the cascade target uses a regex
     * against opis's English template (`Additional object properties are not
     * allowed: ...`). If opis ever rewords this template, the regex stops
     * matching and we leave the messages unchanged — fail-safe in the noisy
     * direction (no silent suppression of real violations). This is the only
     * string-based step in the pipeline; the property-name comparison itself
     * is fully structural.
     *
     * @param array<string, string[]> $errors
     * @param array<string, list<string>> $actions
     *
     * @return array<string, string[]>
     */
    private static function applyCascadeActions(array $errors, array $actions): array
    {
        if ($actions === []) {
            return $errors;
        }

        foreach ($errors as $path => $messages) {
            if (!array_key_exists($path, $actions)) {
                continue;
            }

            $real = $actions[$path];
            $kept = [];
            foreach ($messages as $message) {
                if (preg_match('/^Additional object properties are not allowed: /', $message) !== 1) {
                    $kept[] = $message;

                    continue;
                }

                if ($real === []) {
                    // Whole message is cascade artifact — suppress.
                    continue;
                }

                $kept[] = sprintf(
                    'Additional object properties are not allowed: %s',
                    implode(', ', $real),
                );
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
     * Walk `$schema` via raw data-path segments and return the keys of the
     * `properties` keyword at that location, or null when the path doesn't
     * resolve through plain property nesting / array-element nesting.
     *
     * The walker recognises property and array-item transitions and treats
     * every other shape as unresolvable (returns null):
     * - `properties.<name>` for string segments (object property access)
     * - `items` for int segments (array element access). Both single-schema
     *   form (`items: <stdClass>`), Draft 07 tuple form
     *   (`items: [<stdClass>, <stdClass>, ...]`), and native 2020-12
     *   `prefixItems` are supported.
     *
     * Anything that does not match those two transitions falls through to
     * `null` — the dedup never silently rewrites a path it cannot prove. In
     * practice this catches (non-exhaustive list, illustrative only):
     * composition keywords (`oneOf` / `anyOf` / `allOf`),
     * `additionalProperties: <schema>`, `patternProperties`, `additionalItems`,
     * boolean schemas at item level (`items: true | false`), tuple-form
     * indices out of range, and digit-only object property names — these
     * arrive as int segments via PHP's automatic key cast in opis's data
     * path, take the array branch, and bail when no `items` keyword exists
     * (a no-op, not a regression).
     *
     * Segments are compared as raw values (opis hands us decoded segments
     * via `DataInfo::fullPath()`), so a property declared as `a/b` matches
     * without any JSON-Pointer escape handling on our side.
     *
     * @param array<int, mixed> $segments
     *
     * @return null|list<string>
     */
    private static function declaredPropertyNamesAtSegments(stdClass $schema, array $segments): ?array
    {
        $current = $schema;

        foreach ($segments as $segment) {
            // int segment → array element (descend through `items`)
            if (is_int($segment)) {
                if (property_exists($current, 'prefixItems') &&
                    is_array($current->prefixItems) &&
                    array_key_exists($segment, $current->prefixItems) &&
                    $current->prefixItems[$segment] instanceof stdClass
                ) {
                    $current = $current->prefixItems[$segment];

                    continue;
                }

                if (!property_exists($current, 'items')) {
                    return null;
                }
                $items = $current->items;
                if ($items instanceof stdClass) {
                    $current = $items;

                    continue;
                }
                if (is_array($items) && array_key_exists($segment, $items) && $items[$segment] instanceof stdClass) {
                    $current = $items[$segment];

                    continue;
                }

                return null;
            }

            // string segment → object property (descend through `properties.<name>`)
            if (!property_exists($current, 'properties') || !$current->properties instanceof stdClass) {
                return null;
            }
            $key = (string) $segment;
            if (!property_exists($current->properties, $key)) {
                return null;
            }
            $next = $current->properties->{$key};
            if (!$next instanceof stdClass) {
                return null;
            }
            $current = $next;
        }

        if (!property_exists($current, 'properties') || !$current->properties instanceof stdClass) {
            return null;
        }

        return array_keys(get_object_vars($current->properties));
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
