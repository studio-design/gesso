<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use const E_USER_WARNING;

use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;

use function array_is_list;
use function array_key_exists;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;
use function trigger_error;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class OpenApiSchemaConverter
{
    /**
     * OAS keys to remove for both 3.0 and 3.1.
     *
     * `$schema` is stripped because the converter's output targets Draft 07
     * (the SchemaValidatorRunner pins opis's default to 07). A spec author
     * who inlines `$schema: ".../2020-12/schema"` (legitimate per OAS 3.1
     * `jsonSchemaDialect`) would otherwise force opis to interpret the
     * already-lowered schema under 2020-12, where the array-form `items`
     * we emit for `prefixItems` is invalid. Stripping keeps the validator
     * draft consistent with what the converter actually produces.
     */
    private const OPENAPI_COMMON_KEYS = [
        'discriminator',
        'xml',
        'externalDocs',
        'example',
        'examples',
        'deprecated',
        '$schema',
    ];

    /** OAS 3.0 specific keys (not in JSON Schema Draft 07) */
    private const OPENAPI_3_0_KEYS = [
        'nullable',
        'readOnly',
        'writeOnly',
    ];

    /** Draft 2020-12 keys that don't exist in Draft 07 */
    private const DRAFT_2020_12_KEYS = [
        '$dynamicRef',
        '$dynamicAnchor',
        'contentSchema',
    ];

    /**
     * 2019-09 / 2020-12 keywords opis Draft 07 truly does not implement.
     * Pre-fix, this set wrongly included `patternProperties`,
     * `contentMediaType`, and `contentEncoding` — opis Draft 06+ DOES
     * implement those (see vendor/opis/json-schema/src/Parsers/Drafts/Draft06.php),
     * so warning that "the contract is NOT being enforced" was misinformation.
     * Only `unevaluatedProperties` and `unevaluatedItems` are genuinely
     * dropped silently by Draft 07 — those keep the warning so users notice.
     */
    private const DRAFT_2020_12_UNSUPPORTED_KEYS = [
        'unevaluatedProperties',
        'unevaluatedItems',
    ];

    /**
     * `format` keywords opis (Draft 06+) actually validates. Values outside
     * this set are silently accepted by opis regardless of the data, so
     * `format: emial` (typo for `email`) would pass any string. Mirrors
     * `vendor/opis/json-schema/src/Resolvers/FormatResolver.php` registrations.
     *
     * Re-check this list whenever `opis/json-schema` is upgraded — a new
     * format registered upstream that is not added here will warn as
     * "unknown" and spam users for spec-correct usage. The
     * `known_opis_formats_do_not_warn` regression test pins the current
     * set, so a stale list will surface as a test failure when a newly
     * supported format is asserted there.
     */
    private const KNOWN_OPIS_FORMATS = [
        'date',
        'time',
        'date-time',
        'duration',
        'uri',
        'uri-reference',
        'uri-template',
        'iri',
        'iri-reference',
        'regex',
        'ipv4',
        'ipv6',
        'hostname',
        'idn-hostname',
        'uuid',
        'email',
        'idn-email',
        'json-pointer',
        'relative-json-pointer',
    ];

    /**
     * `format` keywords the OpenAPI spec defines as advisory hints rather
     * than enforcement targets (numeric width, binary encoding, password
     * sensitivity). README documents these as "not range-checked / not
     * specifically handled" — they are deliberately not enforced and must
     * not trigger the unknown-format warning.
     */
    private const ADVISORY_FORMATS = [
        'int32',
        'int64',
        'float',
        'double',
        'byte',
        'binary',
        'password',
    ];

    /** @var array<string, true> */
    private static array $warnedKeywords = [];

    /**
     * Convert an OpenAPI schema to a JSON Schema Draft 07 compatible schema.
     *
     * `$context` drives asymmetric handling of `readOnly` / `writeOnly`:
     * in `Request` context, `readOnly` properties are turned into forbidden
     * subschemas (boolean `false`) and stripped from `required`; in `Response`
     * context the same happens for `writeOnly`. See `SchemaContext` for the
     * motivating OpenAPI semantics.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function convert(
        array $schema,
        OpenApiVersion $version = OpenApiVersion::V3_0,
        SchemaContext $context = SchemaContext::Response,
    ): array {
        self::convertInPlace($schema, $version, $context);

        return $schema;
    }

    /**
     * Reset the per-process "already-warned" set used by
     * {@see warnIfUsesUnsupportedKeywords()}. Test seam — production code
     * never needs this.
     *
     * @internal
     */
    public static function resetWarningStateForTesting(): void
    {
        self::$warnedKeywords = [];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function convertInPlace(array &$schema, OpenApiVersion $version, SchemaContext $context): void
    {
        if ($version === OpenApiVersion::V3_0) {
            self::handleNullable($schema);
        } else {
            self::handlePrefixItems($schema);
            self::lowerConstToEnum($schema);
            self::removeKeys($schema, self::DRAFT_2020_12_KEYS);
        }

        // Warn for both 3.0 and 3.1: `patternProperties` and friends are valid
        // Draft 04/07 keywords used in 3.0 specs as well, so silent ignoring is
        // just as risky there.
        self::warnIfUsesUnsupportedKeywords($schema);

        // Run discriminator warning BEFORE the common-key removal below —
        // once `discriminator` is stripped its `mapping` is gone, and the
        // "this constraint was silently lost" signal would be impossible to
        // emit. `format` is not in OPENAPI_COMMON_KEYS so its order is
        // independent.
        self::warnIfDiscriminatorMappingPresent($schema);
        self::warnIfDependentKeywordsPresent($schema);
        self::warnIfUnknownFormat($schema);

        self::removeKeys($schema, self::OPENAPI_COMMON_KEYS);

        // Enforce readOnly/writeOnly on this object's own `properties` before
        // recursing: the recursive call's 3.0 key scrub will strip the child's
        // top-level marker, so an enforcement pass that looks at child
        // subschemas only sees the keyword while we are still at the parent.
        // It also avoids needlessly descending into a subtree we're about to
        // replace with boolean `false`.
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            self::enforceContextOnProperties($schema, $context);
        }

        if ($version === OpenApiVersion::V3_0) {
            self::removeKeys($schema, self::OPENAPI_3_0_KEYS);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as &$property) {
                if (is_array($property)) {
                    self::convertInPlace($property, $version, $context);
                }
            }
            unset($property);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            if (array_is_list($schema['items'])) {
                foreach ($schema['items'] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version, $context);
                    }
                }
                unset($item);
            } else {
                self::convertInPlace($schema['items'], $version, $context);
            }
        }

        // Primary trigger: handlePrefixItems hoists a 3.1 sibling `items`
        // into `additionalItems` (a 2020-12 subschema that may itself need
        // lowering — nested prefixItems, $dynamicRef, etc.). Also handles
        // hand-authored Draft-07-style input that declares `additionalItems`
        // directly. None of the other recursion sites in convertInPlace
        // descend into it, so route it through here.
        if (isset($schema['additionalItems']) && is_array($schema['additionalItems'])) {
            self::convertInPlace($schema['additionalItems'], $version, $context);
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                foreach ($schema[$combiner] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version, $context);
                    }
                }
                unset($item);
            }
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            self::convertInPlace($schema['additionalProperties'], $version, $context);
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            self::convertInPlace($schema['not'], $version, $context);
        }

        // Descend into the remaining subschema positions opis Draft 07
        // honours (#214). Without this, OAS-only and 2020-12-only keywords
        // nested inside them survive untouched into the validator and opis
        // silently ignores them — the same silent-bypass class fixed by
        // #213 for `prefixItems` siblings. `dependentSchemas` is a 2019-09
        // keyword whose outer form opis Draft 07 ignores entirely; we still
        // recurse into its values for hygiene and to stay symmetric with
        // the other map-of-schemas positions.
        foreach (['if', 'then', 'else', 'propertyNames', 'contains'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                self::convertInPlace($schema[$key], $version, $context);
            }
        }

        if (isset($schema['patternProperties']) && is_array($schema['patternProperties'])) {
            foreach ($schema['patternProperties'] as &$sub) {
                if (is_array($sub)) {
                    self::convertInPlace($sub, $version, $context);
                }
            }
            unset($sub);
        }

        if (isset($schema['dependentSchemas']) && is_array($schema['dependentSchemas'])) {
            foreach ($schema['dependentSchemas'] as &$sub) {
                // A list-shaped value here belongs under `dependentRequired`
                // (an array of property names), not `dependentSchemas` (a
                // map of schemas). Skip descent so a sibling-keyword misuse
                // remains a no-op rather than being silently routed through
                // schema lowering — the same silent-defect class the rest
                // of this method exists to surface.
                if (is_array($sub) && !array_is_list($sub)) {
                    self::convertInPlace($sub, $version, $context);
                }
            }
            unset($sub);
        }
    }

    /**
     * Replace the subschema of every context-forbidden property with boolean
     * `false` (Draft-07 canonical "this property must not appear") and prune
     * forbidden names from the parent `required` array.
     *
     * Detection only looks at the property's own top-level `readOnly` /
     * `writeOnly`; markers buried inside a property's `allOf` / `oneOf` /
     * `anyOf` children are not handled (known limitation).
     *
     * @param array<string, mixed> $schema
     */
    private static function enforceContextOnProperties(array &$schema, SchemaContext $context): void
    {
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        $forbiddenKeyword = $context->forbiddenKeyword();
        $forbiddenNames = [];

        foreach ($properties as $name => $property) {
            if (!is_array($property)) {
                continue;
            }

            if (($property[$forbiddenKeyword] ?? null) !== true) {
                continue;
            }

            $properties[$name] = false;
            $forbiddenNames[] = $name;
        }

        if ($forbiddenNames === []) {
            return;
        }

        $schema['properties'] = $properties;

        if (!isset($schema['required']) || !is_array($schema['required'])) {
            return;
        }

        /** @var array<int, mixed> $required */
        $required = $schema['required'];
        $filtered = [];
        foreach ($required as $entry) {
            if (is_string($entry) && in_array($entry, $forbiddenNames, true)) {
                continue;
            }
            $filtered[] = $entry;
        }

        if ($filtered === []) {
            unset($schema['required']);

            return;
        }

        $schema['required'] = $filtered;
    }

    /**
     * Convert OpenAPI 3.0 nullable to JSON Schema compatible type.
     *
     * When `enum` is present alongside `nullable: true`, append `null` to the
     * enum so opis accepts null values — this matches the OAS 3.0 convention
     * (most spec authors don't list null inside `enum` even when nullable is on).
     *
     * @param array<string, mixed> $schema
     */
    private static function handleNullable(array &$schema): void
    {
        if (!isset($schema['nullable']) || $schema['nullable'] !== true) {
            return;
        }

        unset($schema['nullable']);

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }

        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];

            return;
        }

        foreach (['oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                $schema[$combiner][] = ['type' => 'null'];

                return;
            }
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $allOf = $schema['allOf'];
            unset($schema['allOf']);
            $schema['oneOf'] = [
                ['allOf' => $allOf],
                ['type' => 'null'],
            ];
        }
    }

    /**
     * OAS 3.1 / Draft 2019-09 introduced `const`. Draft 07 (the schema dialect
     * we delegate to via opis) does not understand it, so a `const: "fixed"`
     * schema would silently accept any value of the correct type. Lower to
     * `enum: [value]` so the constraint is actually enforced.
     *
     * Conflict policy: when both `const` and `enum` are present (rare and
     * arguably malformed), we keep `enum` and drop `const`. JSON Schema
     * semantics are that the two intersect — the result should equal `[const]`
     * if `const ∈ enum`, else unsatisfiable — but reproducing that precisely
     * is more delicate than v1.0 needs. The chosen behaviour LOOSENS the
     * constraint relative to the spec; treat this conflict as a known
     * limitation and prefer `const`-only or `enum`-only schemas.
     *
     * @param array<string, mixed> $schema
     */
    private static function lowerConstToEnum(array &$schema): void
    {
        if (!array_key_exists('const', $schema)) {
            return;
        }

        if (!array_key_exists('enum', $schema)) {
            $schema['enum'] = [$schema['const']];
        }

        unset($schema['const']);
    }

    /**
     * Issue a one-shot E_USER_WARNING for each Draft 2019-09 / 2020-12 keyword
     * we cannot honour through opis Draft 07. Without this, specs relying on
     * `patternProperties` / `unevaluatedProperties` / `contentMediaType` would
     * silently pass — a contract test that does not actually enforce the
     * contract is the worst possible failure mode.
     *
     * Warns once per keyword per process to avoid log spam in long test runs.
     *
     * @param array<string, mixed> $schema
     */
    private static function warnIfUsesUnsupportedKeywords(array $schema): void
    {
        foreach (self::DRAFT_2020_12_UNSUPPORTED_KEYS as $keyword) {
            if (!array_key_exists($keyword, $schema) || isset(self::$warnedKeywords[$keyword])) {
                continue;
            }

            self::$warnedKeywords[$keyword] = true;
            trigger_error(
                sprintf(
                    "[OpenAPI Schema] '%s' is not supported by the JSON Schema Draft 07 validator opis uses internally. The keyword will be ignored — your spec's constraint is NOT being enforced. Consider rewriting using Draft 07 equivalents (additionalProperties, required, etc.).",
                    $keyword,
                ),
                E_USER_WARNING,
            );
        }
    }

    /**
     * Issue a one-shot E_USER_WARNING when a schema declares a non-empty
     * `discriminator.mapping`. The converter strips the entire `discriminator`
     * keyword (it's OAS-only, not a JSON Schema keyword) before handing the
     * schema to opis — the underlying `oneOf` / `anyOf` is still validated
     * as a plain union, but the mapping does not steer validation toward a
     * single branch. A polymorphic body with the wrong discriminator value
     * passes as long as it satisfies any branch, masking serialiser bugs.
     *
     * Dedup key is the literal `discriminator.mapping` string — fired at
     * most once per process regardless of how many polymorphic schemas
     * carry the keyword.
     *
     * @param array<string, mixed> $schema
     */
    private static function warnIfDiscriminatorMappingPresent(array $schema): void
    {
        if (!isset($schema['discriminator']) || !is_array($schema['discriminator'])) {
            return;
        }
        $mapping = $schema['discriminator']['mapping'] ?? null;
        if (!is_array($mapping) || $mapping === []) {
            return;
        }

        $dedupKey = 'discriminator.mapping';
        if (isset(self::$warnedKeywords[$dedupKey])) {
            return;
        }

        self::$warnedKeywords[$dedupKey] = true;
        trigger_error(
            "[OpenAPI Schema] 'discriminator.mapping' is silently stripped — the underlying oneOf/anyOf is still validated as a plain union, but the mapping does not steer validation toward a single branch. A body with the wrong discriminator value passes as long as it satisfies any branch, masking serialiser bugs. See README \"Schema features\" for the full limitation note.",
            E_USER_WARNING,
        );
    }

    /**
     * Issue a one-shot E_USER_WARNING when a schema declares the 2019-09
     * keywords `dependentSchemas` or `dependentRequired`. opis Draft 07 does
     * not register either keyword (they are introduced in Draft 2019-09 —
     * see vendor/opis/json-schema/src/Parsers/Drafts/Draft201909.php), so
     * the property-dependency constraint is dropped wholesale: a payload
     * carrying the trigger property without its dependents passes silently.
     *
     * The converter still recurses into `dependentSchemas` map-shaped value
     * subschemas for keyword hygiene (#214), but that does not restore the
     * outer keyword's intended validation — hence this separate observability
     * warning. The Draft 07 equivalent (`if` / `then` / `else`) differs from
     * the `additionalProperties` / `required` advice in
     * {@see warnIfUsesUnsupportedKeywords()}, so the copy lives here rather
     * than folding these keywords into `DRAFT_2020_12_UNSUPPORTED_KEYS`.
     *
     * Called from `convertInPlace()`, so the warning fires for every schema
     * node the converter recurses into. A keyword buried inside a position
     * `convertInPlace()` deliberately does not visit — notably a list-shaped
     * `dependentSchemas` value, itself a spec defect skipped at the recursion
     * site — is not surfaced; that residual gap is defect-on-defect only.
     *
     * Warns once per keyword per process; dedup shares `$warnedKeywords`.
     *
     * @param array<string, mixed> $schema
     */
    private static function warnIfDependentKeywordsPresent(array $schema): void
    {
        foreach (['dependentSchemas', 'dependentRequired'] as $keyword) {
            if (!array_key_exists($keyword, $schema) || isset(self::$warnedKeywords[$keyword])) {
                continue;
            }

            self::$warnedKeywords[$keyword] = true;
            trigger_error(
                sprintf(
                    "[OpenAPI Schema] '%s' is a Draft 2019-09 keyword that the JSON Schema Draft 07 validator opis uses internally does not register. The keyword will be ignored — your spec's property-dependency constraint is NOT being enforced. Rewrite it as a Draft 07 conditional using if/then/else (the `if` clause tests for the trigger property, the `then` clause carries the dependent requirement).",
                    $keyword,
                ),
                E_USER_WARNING,
            );
        }
    }

    /**
     * Issue a one-shot E_USER_WARNING per format value when a schema
     * declares a `format` opis cannot validate. Without this, a typo like
     * `format: emial` (instead of `email`) silently passes against any
     * value — the user thinks they're enforcing email syntax but anything
     * goes.
     *
     * Three input shapes are distinguished:
     *
     * 1. `format` absent or empty string  → no warning (no constraint declared).
     * 2. `format` non-string (int / null / array)  → one-shot
     *    `format-malformed:<gettype>` warning. Per OAS 3.x §4.7 the value
     *    must be a string; a non-string value is a spec defect that opis
     *    would silently swallow without this guard.
     * 3. `format` string in {@see KNOWN_OPIS_FORMATS} or
     *    {@see ADVISORY_FORMATS} → no warning (validated, or advisory-by-
     *    design respectively).
     * 4. `format` string outside both lists → one-shot `format:<value>`
     *    silent-pass warning.
     *
     * Custom formats registered against opis at runtime are NOT detected by
     * this static check — users who extend opis's format set will see false
     * positives and must rename their format, suppress the warning, or
     * filter their error handler.
     *
     * @param array<string, mixed> $schema
     */
    private static function warnIfUnknownFormat(array $schema): void
    {
        if (!array_key_exists('format', $schema)) {
            return;
        }

        $format = $schema['format'];

        if (!is_string($format)) {
            $malformedKey = 'format-malformed:' . get_debug_type($format);
            if (isset(self::$warnedKeywords[$malformedKey])) {
                return;
            }

            self::$warnedKeywords[$malformedKey] = true;
            trigger_error(
                sprintf(
                    "[OpenAPI Schema] 'format' must be a string per OAS 3.x §4.7, got %s. opis silently ignores non-string format values, so any constraint a non-string `format` was meant to express is NOT enforced. Fix the spec.",
                    get_debug_type($format),
                ),
                E_USER_WARNING,
            );

            return;
        }

        if ($format === '') {
            return;
        }

        if (in_array($format, self::KNOWN_OPIS_FORMATS, true)) {
            return;
        }

        if (in_array($format, self::ADVISORY_FORMATS, true)) {
            return;
        }

        $dedupKey = 'format:' . $format;
        if (isset(self::$warnedKeywords[$dedupKey])) {
            return;
        }

        self::$warnedKeywords[$dedupKey] = true;
        trigger_error(
            sprintf(
                "[OpenAPI Schema] format '%s' is not in opis's known set; the validator will silently accept any value regardless of its content (opis returns pass for unrecognised formats independent of the data type). Common cause: a typo (e.g. 'emial' instead of 'email'). Validated formats: %s. Advisory formats (not enforced by design, no warning): %s.",
                $format,
                implode(', ', self::KNOWN_OPIS_FORMATS),
                implode(', ', self::ADVISORY_FORMATS),
            ),
            E_USER_WARNING,
        );
    }

    /**
     * Convert Draft 2020-12 prefixItems to Draft 07 items array (tuple validation).
     *
     * If `items` appears alongside `prefixItems` (2020-12 semantics:
     * "schema for every element at index >= count(prefixItems)"), preserve
     * that constraint as Draft 07 `additionalItems`. Overwriting `items`
     * without preserving its sibling would silently drop the overflow
     * constraint — a contract bypass (issue #212). `items: true` is the
     * implicit Draft 07 default and is omitted instead of emitted; this
     * relies on the validator running under Draft 07, pinned by
     * SchemaValidatorRunner's `setDefaultDraftVersion('07')` call. Should
     * that default change, the
     * `prefix_items_with_items_true_matches_prefix_items_without_sibling_under_opis_draft07`
     * regression test will fail.
     *
     * A non-bool / non-array `items` sibling is a spec defect (JSON Schema
     * 2020-12 §10.3 requires `Schema | bool`). Hoisting it into
     * `additionalItems` would surface much later as an opis parse error
     * with no clue to the source — emit a one-shot E_USER_WARNING and drop
     * it, matching the pattern used by {@see warnIfUnknownFormat()}.
     *
     * @param array<string, mixed> $schema
     */
    private static function handlePrefixItems(array &$schema): void
    {
        if (!isset($schema['prefixItems']) || !is_array($schema['prefixItems'])) {
            return;
        }

        if (array_key_exists('items', $schema)) {
            $overflow = $schema['items'];
            if (is_array($overflow) || is_bool($overflow)) {
                if ($overflow !== true) {
                    $schema['additionalItems'] = $overflow;
                }
            } else {
                self::warnMalformedPrefixItemsSibling($overflow);
            }
        }

        $schema['items'] = $schema['prefixItems'];
        unset($schema['prefixItems']);
    }

    private static function warnMalformedPrefixItemsSibling(mixed $overflow): void
    {
        $dedupKey = 'prefix-items-sibling-malformed:' . get_debug_type($overflow);
        if (isset(self::$warnedKeywords[$dedupKey])) {
            return;
        }

        self::$warnedKeywords[$dedupKey] = true;
        trigger_error(
            sprintf(
                "[OpenAPI Schema] sibling 'items' of 'prefixItems' must be a schema object or boolean per JSON Schema 2020-12 §10.3, got %s. The overflow constraint is silently dropped — any element past the tuple is NOT enforced. Fix the spec.",
                get_debug_type($overflow),
            ),
            E_USER_WARNING,
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @param string[] $keys
     */
    private static function removeKeys(array &$schema, array $keys): void
    {
        foreach ($keys as $key) {
            unset($schema[$key]);
        }
    }
}
