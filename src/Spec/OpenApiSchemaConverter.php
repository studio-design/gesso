<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use const E_USER_WARNING;

use Studio\OpenApiContractTesting\Exception\MalformedDiscriminatorException;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Validation\Support\DiscriminatorContext;
use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function sort;
use function sprintf;
use function str_starts_with;
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
        'xml',
        'externalDocs',
        'example',
        'examples',
        'deprecated',
        '$schema',
        OpenApiRefResolver::IMPLICIT_SCHEMA_NAME_EXTENSION,
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
     * `$discriminator` carries the resolved root + enforce gate for
     * `discriminator.mapping` / 3.2 `defaultMapping` lowering (Issues #262
     * and #273). `null` (the default for
     * callers that cannot enforce — parameter / header validators, the fuzz
     * explorer) is normalised to {@see DiscriminatorContext::disabled()}, under
     * which `discriminator` is stripped rather than enforced.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function convert(
        array $schema,
        OpenApiVersion $version = OpenApiVersion::V3_0,
        SchemaContext $context = SchemaContext::Response,
        ?DiscriminatorContext $discriminator = null,
    ): array {
        self::convertInPlace($schema, $version, $context, $discriminator ?? DiscriminatorContext::disabled());

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
    private static function convertInPlace(
        array &$schema,
        OpenApiVersion $version,
        SchemaContext $context,
        DiscriminatorContext $discriminator,
    ): void {
        $implicitDiscriminatorValues = self::implicitDiscriminatorValues($schema);

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

        self::warnIfDependentKeywordsPresent($schema);
        self::warnIfUnknownFormat($schema);

        // `discriminator` is OAS-only (not a JSON Schema keyword) and is
        // therefore no longer in OPENAPI_COMMON_KEYS — it is consumed by
        // lowerDiscriminator() at the end of this method, which either lowers
        // its `mapping` into enforceable `if`/`then` conditionals or strips it
        // (see Issue #262). Running the lowering last lets it convert the
        // resolved subtype it inlines into each `then` with the recursion guard
        // active, without the generic combiner recursion below re-visiting it.
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
                    self::convertInPlace($property, $version, $context, $discriminator);
                }
            }
            unset($property);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            if (array_is_list($schema['items'])) {
                foreach ($schema['items'] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version, $context, $discriminator);
                    }
                }
                unset($item);
            } else {
                self::convertInPlace($schema['items'], $version, $context, $discriminator);
            }
        }

        // Primary trigger: handlePrefixItems hoists a 3.1 sibling `items`
        // into `additionalItems` (a 2020-12 subschema that may itself need
        // lowering — nested prefixItems, $dynamicRef, etc.). Also handles
        // hand-authored Draft-07-style input that declares `additionalItems`
        // directly. None of the other recursion sites in convertInPlace
        // descend into it, so route it through here.
        if (isset($schema['additionalItems']) && is_array($schema['additionalItems'])) {
            self::convertInPlace($schema['additionalItems'], $version, $context, $discriminator);
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner]) && is_array($schema[$combiner])) {
                foreach ($schema[$combiner] as &$item) {
                    if (is_array($item)) {
                        self::convertInPlace($item, $version, $context, $discriminator);
                    }
                }
                unset($item);
            }
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            self::convertInPlace($schema['additionalProperties'], $version, $context, $discriminator);
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            self::convertInPlace($schema['not'], $version, $context, $discriminator);
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
                self::convertInPlace($schema[$key], $version, $context, $discriminator);
            }
        }

        if (isset($schema['patternProperties']) && is_array($schema['patternProperties'])) {
            foreach ($schema['patternProperties'] as &$sub) {
                if (is_array($sub)) {
                    self::convertInPlace($sub, $version, $context, $discriminator);
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
                    self::convertInPlace($sub, $version, $context, $discriminator);
                }
            }
            unset($sub);
        }

        // Consume `discriminator` last: when enforcing, lower its `mapping`
        // into `if`/`then` conditionals (resolving + converting each subtype
        // it references); otherwise strip it. Running after the combiner
        // recursion above means the lowered `allOf` entries we append are
        // already converted by lowerDiscriminator itself and are not
        // re-visited here. See Issue #262.
        self::lowerDiscriminator($schema, $version, $context, $discriminator, $implicitDiscriminatorValues);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function implicitDiscriminatorValues(array $schema): array
    {
        $values = [];
        foreach (['oneOf', 'anyOf'] as $combiner) {
            if (!is_array($schema[$combiner] ?? null)) {
                continue;
            }

            foreach ($schema[$combiner] as $alternative) {
                if (!is_array($alternative)) {
                    continue;
                }

                $name = $alternative[OpenApiRefResolver::IMPLICIT_SCHEMA_NAME_EXTENSION] ?? null;
                if (is_string($name) && $name !== '') {
                    $values[$name] = true;
                }
            }
        }

        return array_keys($values);
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
     * Lower a schema's `discriminator` into enforceable Draft-07 conditionals,
     * including OpenAPI 3.2 `defaultMapping`, or strip it when enforcement is
     * off (Issues #262 and #273).
     *
     * `discriminator` is OAS-only — opis (Draft 07) cannot interpret it, so
     * historically the converter stripped it and the underlying `oneOf` /
     * `anyOf` was validated as a plain union: a body that lied about its type
     * (e.g. `kty=RSA` carrying EC-only fields) passed as long as it matched any
     * branch. When `$discriminator->enforce` is on, this rewrites the block
     * into an `allOf` the union-agnostic validator DOES honour:
     *
     *  - an unknown-value guard requiring the discriminator property to be
     *    present and one of the mapping keys; and
     *  - one `if`/`then` per mapping value, where `then` is the resolved
     *    subtype schema — so the discriminator value steers validation toward a
     *    single branch.
     *
     * Each resolved subtype is itself run through {@see convertInPlace()} with
     * the discriminator's signature added to the recursion guard: `$ref` is
     * eagerly inlined at load time, so a subtype typically re-contains the
     * base's `discriminator` (`allOf:[{$ref base}, …]`) and an unguarded
     * lowering would recurse forever.
     *
     * Structural defects (missing / non-string `propertyName`, non-array
     * `mapping`, non-string mapping value, unresolvable pointer, non-object
     * target) throw {@see MalformedDiscriminatorException} — caught by the body
     * validators' boundary and surfaced as one loud failure, consistent with
     * the {@see MalformedSpecNode}
     * guards. A bare `propertyName`-only discriminator, an empty `mapping`, the
     * off gate, and the no-root sentinel all strip silently (the historical
     * behaviour, minus the removed warning).
     *
     * @param array<string, mixed> $schema
     * @param list<string> $implicitValues component names referenced directly by adjacent oneOf/anyOf entries
     */
    private static function lowerDiscriminator(
        array &$schema,
        OpenApiVersion $version,
        SchemaContext $context,
        DiscriminatorContext $discriminator,
        array $implicitValues,
    ): void {
        if (!array_key_exists('discriminator', $schema)) {
            return;
        }

        // Off, or no root to resolve mapping pointers against → strip, matching
        // the pre-#262 behaviour (now without the E_USER_WARNING).
        if (!$discriminator->enforce || $discriminator->root === []) {
            unset($schema['discriminator']);

            return;
        }

        $block = $schema['discriminator'];
        if (!is_array($block)) {
            throw new MalformedDiscriminatorException(sprintf(
                "Malformed 'discriminator': expected object, got %s.",
                get_debug_type($block),
            ));
        }

        $propertyName = $block['propertyName'] ?? null;
        if (!is_string($propertyName) || $propertyName === '') {
            throw new MalformedDiscriminatorException(sprintf(
                "Malformed 'discriminator.propertyName': expected a non-empty string, got %s.",
                get_debug_type($block['propertyName'] ?? null),
            ));
        }

        $hasDefaultMapping = $version === OpenApiVersion::V3_2 && array_key_exists('defaultMapping', $block);
        $defaultMapping = $hasDefaultMapping ? $block['defaultMapping'] : null;
        if ($hasDefaultMapping && (!is_string($defaultMapping) || $defaultMapping === '')) {
            throw new MalformedDiscriminatorException(sprintf(
                "Malformed 'discriminator.defaultMapping': expected a non-empty string reference, got %s.",
                get_debug_type($defaultMapping),
            ));
        }

        // A bare discriminator (no mapping or 3.2 defaultMapping) is
        // documentation-only — strip it.
        if (!array_key_exists('mapping', $block) && !$hasDefaultMapping) {
            unset($schema['discriminator']);

            return;
        }

        $mapping = $block['mapping'] ?? [];
        if (!is_array($mapping)) {
            throw new MalformedDiscriminatorException(sprintf(
                "Malformed 'discriminator.mapping': expected object, got %s.",
                get_debug_type($mapping),
            ));
        }
        if ($mapping === [] && !$hasDefaultMapping) {
            unset($schema['discriminator']);

            return;
        }

        // Self-referential re-appearance via eager $ref inlining: the outer
        // lowering already enforces this exact discriminator on the matched
        // branch, so strip without re-lowering. This both terminates the
        // recursion and avoids combinatorial blow-up for large mappings.
        $signature = self::discriminatorSignature($propertyName, $mapping, $defaultMapping);
        if ($discriminator->hasSignature($signature)) {
            unset($schema['discriminator']);

            return;
        }

        $childContext = $discriminator->withSignature($signature);
        $knownValues = [];
        $explicitValues = [];
        /** @var list<array{value: string, pointer: string}> $routes */
        $routes = [];
        foreach ($mapping as $value => $pointer) {
            $value = (string) $value;
            $knownValues[] = $value;
            $explicitValues[$value] = true;

            if (!is_string($pointer)) {
                throw new MalformedDiscriminatorException(sprintf(
                    "Malformed 'discriminator.mapping[%s]': expected a string reference, got %s.",
                    $value,
                    get_debug_type($pointer),
                ));
            }

            $routes[] = ['value' => $value, 'pointer' => $pointer];
        }

        foreach ($implicitValues as $value) {
            if (isset($explicitValues[$value])) {
                continue;
            }

            $knownValues[] = $value;
            $routes[] = ['value' => $value, 'pointer' => $value];
        }

        $branches = [];
        foreach ($routes as $route) {
            $value = $route['value'];
            $pointer = $route['pointer'];

            $subschema = self::resolveMappingTarget($value, $pointer, $discriminator->root);
            self::convertInPlace($subschema, $version, $context, $childContext);

            $branches[] = [
                'if' => [
                    'properties' => [$propertyName => ['enum' => [$value]]],
                    'required' => [$propertyName],
                ],
                'then' => $subschema,
            ];
        }

        $defaultSubschema = null;
        if (is_string($defaultMapping)) {
            $defaultSubschema = self::resolveMappingTarget('default', $defaultMapping, $discriminator->root, 'defaultMapping');
            self::convertInPlace($defaultSubschema, $version, $context, $childContext);
        }

        $allOf = (isset($schema['allOf']) && is_array($schema['allOf'])) ? $schema['allOf'] : [];
        $knownValueCondition = [
            'properties' => [$propertyName => ['enum' => $knownValues]],
            'required' => [$propertyName],
        ];

        if ($defaultSubschema === null) {
            // OpenAPI 3.0/3.1 behavior: unknown or missing discriminator
            // values fail loudly when explicit mappings are enforced.
            $allOf[] = $knownValueCondition;
        } elseif ($knownValues !== []) {
            // OpenAPI 3.2: missing and unknown values route to
            // defaultMapping, while explicit values keep their mapped branch.
            $allOf[] = [
                'if' => ['not' => $knownValueCondition],
                'then' => $defaultSubschema,
            ];
        } else {
            // Without explicit or recoverable implicit mapping entries we can
            // still enforce the missing-value fallback and make the residual
            // unknown-value gap observable.
            $allOf[] = [
                'if' => ['not' => ['required' => [$propertyName]]],
                'then' => $defaultSubschema,
            ];
            $warningKey = 'discriminator.defaultMapping-without-explicit-mapping';
            if (!isset(self::$warnedKeywords[$warningKey])) {
                self::$warnedKeywords[$warningKey] = true;
                trigger_error(
                    '[OpenAPI 3.2 discriminator] defaultMapping without an explicit or recoverable implicit mapping '
                    . 'can be enforced for a missing discriminator value, but unknown present values rely on the '
                    . 'underlying oneOf/anyOf.',
                    E_USER_WARNING,
                );
            }
        }
        foreach ($branches as $branch) {
            $allOf[] = $branch;
        }
        $schema['allOf'] = $allOf;

        unset($schema['discriminator']);
    }

    /**
     * Resolve a single `discriminator.mapping` value to its subtype schema in
     * the root document. The value is either a JSON Pointer (`#/...`) or the
     * OAS bare-name shorthand, which resolves to `#/components/schemas/{name}`.
     * Reuses {@see OpenApiRefResolver}'s pointer logic so escape handling stays
     * in one place.
     *
     * @param array<string, mixed> $root
     *
     * @return array<string, mixed>
     */
    private static function resolveMappingTarget(
        string $value,
        string $pointer,
        array $root,
        string $field = 'mapping',
    ): array {
        $jsonPointer = str_starts_with($pointer, '#/')
            ? $pointer
            : '#/components/schemas/' . OpenApiRefResolver::escapePointerSegment($pointer);

        [$found, $target] = OpenApiRefResolver::resolvePointer($jsonPointer, $root);
        if (!$found) {
            throw new MalformedDiscriminatorException(sprintf(
                "Malformed 'discriminator.%s[%s]': '%s' does not resolve to a schema in the spec.",
                $field,
                $value,
                $pointer,
            ));
        }
        if (!is_array($target)) {
            throw new MalformedDiscriminatorException(sprintf(
                "Malformed 'discriminator.%s[%s]': '%s' must reference a schema object, got %s.",
                $field,
                $value,
                $pointer,
                get_debug_type($target),
            ));
        }

        /** @var array<string, mixed> $target */
        return $target;
    }

    /**
     * Stable identity for a discriminator: its property name plus the sorted
     * set of `key => target` mapping pairs. The recursion guard uses it to
     * detect the SAME discriminator re-appearing through eager `$ref` inlining
     * (the base↔subtype cycle), independent of declaration order. Folding the
     * resolved target into each pair means two genuinely distinct
     * discriminators that merely share a property name and key set do NOT
     * collide — only an identical mapping (the self-reference case) matches, so
     * a nested *distinct* discriminator is still lowered rather than silently
     * stripped (which would be a silent under-enforcement).
     *
     * A non-string mapping value is folded in by its type token; such a value
     * is rejected with a loud throw later in the lowering, so the rendering
     * here only needs to be stable, not meaningful.
     *
     * @param array<array-key, mixed> $mapping
     */
    private static function discriminatorSignature(string $propertyName, array $mapping, mixed $defaultMapping = null): string
    {
        $pairs = [];
        foreach ($mapping as $key => $value) {
            $pairs[] = (string) $key . "\0" . (is_string($value) ? $value : get_debug_type($value));
        }
        sort($pairs);

        return $propertyName . "\0" . implode("\0", $pairs) . "\0default\0"
            . (is_string($defaultMapping) ? $defaultMapping : get_debug_type($defaultMapping));
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
