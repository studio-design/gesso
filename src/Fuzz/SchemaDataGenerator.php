<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use const E_USER_WARNING;
use const PHP_FLOAT_EPSILON;

use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use stdClass;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function ceil;
use function class_exists;
use function count;
use function floor;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;
use function preg_match_all;
use function round;
use function sprintf;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trigger_error;

/**
 * Generate happy-path values that conform to a converted JSON Schema.
 *
 * Inputs are expected to be already-converted via {@see OpenApiSchemaConverter}
 * — i.e. OAS-only keys have been stripped or lowered (including
 * `discriminator`), and OAS 3.1 type-arrays normalised.
 *
 * Supported keywords: `type` (string|integer|number|boolean|object|array|null),
 * `const`, `enum`, `format` (email|uuid|date|date-time|uri|url),
 * length/numeric/collection boundaries, object properties, `items` /
 * `prefixItems`, common patterns, and composition (`oneOf`, `anyOf`, `allOf`,
 * `not`, and conditionals). The public strategy matrix in docs/fuzzing.md is
 * authoritative for exact support and limitations.
 *
 * Determinism: when a `$seed` is supplied AND `fakerphp/faker` is installed,
 * faker is seeded so the same `(schema, count, seed)` triple produces the same
 * output across runs. Without faker, the generator falls back to deterministic
 * values keyed off the per-case iteration index — repeated calls still produce
 * identical output for the same `count`.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SchemaDataGenerator
{
    private const MAX_SYNTHESIZED_PATTERN_LENGTH = 10_000;

    /**
     * Per-process record of formats already announced as "faker missing".
     * Keyed by format name; we only warn once per format to avoid spamming
     * a long fuzz run that touches many `email` properties.
     *
     * @var array<string, true>
     */
    private static array $warnedFakerFormats = [];

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<mixed>
     */
    public static function generate(array $schema, int $count, ?int $seed = null): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException(
                'SchemaDataGenerator::generate() requires count >= 1, got ' . $count . '.',
            );
        }

        $faker = self::createFaker($seed);
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $value = self::generateOne($schema, $faker, $i);
            SchemaValueValidator::assertValid($value, $schema, $i);
            $results[] = $value;
        }

        return $results;
    }

    /**
     * Single-value generation entry — exposed so callers like
     * {@see OpenApiEndpointExplorer} can share a faker instance across
     * multiple schemas (body + parameters) within a single case.
     *
     * @param array<string, mixed> $schema
     */
    public static function generateOne(array $schema, ?Generator $faker, int $iteration): mixed
    {
        $schema = self::resolveComposition($schema, $faker, $iteration);

        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $values = array_values($schema['enum']);

            return $values[$iteration % count($values)];
        }

        if (is_array($schema['type'] ?? null) && in_array('null', $schema['type'], true) && ($iteration % 3) === 2) {
            return null;
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            $withoutNot = $schema;
            unset($withoutNot['not']);
            $candidate = self::generateOne($withoutNot, $faker, $iteration);
            if (!SchemaValueValidator::isValid($candidate, $schema)) {
                foreach ([null, false, 0, 1, '', 'value', [], ['value' => true]] as $alternative) {
                    if (SchemaValueValidator::isValid($alternative, $schema)) {
                        return $alternative;
                    }
                }
            }

            return $candidate;
        }

        $type = self::resolveType($schema);

        return match ($type) {
            'object' => self::generateObject($schema, $faker, $iteration),
            'array' => self::generateArray($schema, $faker, $iteration),
            'string' => self::generateString($schema, $faker, $iteration),
            'integer' => self::generateInteger($schema, $faker, $iteration),
            'number' => self::generateNumber($schema, $faker, $iteration),
            'boolean' => self::generateBoolean($iteration),
            'null' => null,
            default => null,
        };
    }

    /**
     * Build a faker generator when the package is installed; null otherwise.
     * The null branch is documented and exercised by tests — see the class
     * docblock for the determinism contract in either case.
     */
    public static function createFaker(?int $seed): ?Generator
    {
        if (!class_exists(Factory::class)) {
            return null;
        }

        $faker = Factory::create();
        if ($seed !== null) {
            $faker->seed($seed);
        }

        return $faker;
    }

    /**
     * Reset the per-process "already warned" record. Tests use this to
     * exercise the warning path multiple times without leaking state across
     * cases; production callers never need to.
     *
     * @internal
     */
    public static function resetWarningStateForTesting(): void
    {
        self::$warnedFakerFormats = [];
    }

    /**
     * Resolve the effective type of a schema. Type may be a string, a
     * Draft-2020 array (`["string", "null"]` for nullable), or absent — in
     * which case we infer from `properties`/`items` and finally default to
     * `string` so a permissive untyped schema still produces a value.
     *
     * @param array<string, mixed> $schema
     */
    private static function resolveType(array $schema): string
    {
        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            return $type;
        }

        if (is_array($type) && $type !== []) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    return $candidate;
                }
            }

            return 'null';
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            return 'object';
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            return 'array';
        }

        if (isset($schema['prefixItems']) && is_array($schema['prefixItems'])) {
            return 'array';
        }

        return 'string';
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|stdClass
     */
    private static function generateObject(array $schema, ?Generator $faker, int $iteration): array|stdClass
    {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return [];
        }

        $required = [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $name) {
                if (is_string($name)) {
                    $required[] = $name;
                }
            }
        }

        $result = [];
        foreach ($properties as $name => $propSchema) {
            if (!is_string($name) || !is_array($propSchema)) {
                continue;
            }

            $isRequired = in_array($name, $required, true);
            // Optional properties alternate inclusion across cases so the
            // suite exercises both "required-only" and "required+optional"
            // shapes — mirrors Schemathesis' explore-omit toggle on a small
            // budget. Required keys are always emitted.
            if (!$isRequired && ($iteration % 2) === 0) {
                continue;
            }

            $result[$name] = self::generateOne($propSchema, $faker, $iteration);
        }

        $minProperties = isset($schema['minProperties']) && is_int($schema['minProperties'])
            ? $schema['minProperties']
            : 0;
        if (count($result) < $minProperties && ($schema['additionalProperties'] ?? true) !== false) {
            while (count($result) < $minProperties) {
                $name = 'property' . count($result);
                $additionalSchema = is_array($schema['additionalProperties'] ?? null)
                    ? $schema['additionalProperties']
                    : ['type' => 'string'];
                $result[$name] = self::generateOne($additionalSchema, $faker, $iteration + count($result));
            }
        }
        $maxProperties = isset($schema['maxProperties']) && is_int($schema['maxProperties'])
            ? $schema['maxProperties']
            : null;
        if ($maxProperties !== null && ($iteration % 3) === 1 && ($schema['additionalProperties'] ?? true) !== false) {
            while (count($result) < $maxProperties) {
                $name = 'property' . count($result);
                $additionalSchema = is_array($schema['additionalProperties'] ?? null)
                    ? $schema['additionalProperties']
                    : ['type' => 'string'];
                $result[$name] = self::generateOne($additionalSchema, $faker, $iteration + count($result));
            }
        }
        if ($maxProperties !== null && count($result) > $maxProperties) {
            foreach (array_keys($result) as $name) {
                if (count($result) <= $maxProperties) {
                    break;
                }
                if (!in_array($name, $required, true)) {
                    unset($result[$name]);
                }
            }
        }
        if ($result === []) {
            if ($maxProperties === 0 || ($schema['additionalProperties'] ?? true) === false) {
                return new stdClass();
            }
            $result['property0'] = 'value';
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<mixed>
     */
    private static function generateArray(array $schema, ?Generator $faker, int $iteration): array
    {
        $prefixItems = $schema['prefixItems'] ?? null;
        if (is_array($prefixItems)) {
            $prefixCount = count($prefixItems);
            $minimum = isset($schema['minItems']) && is_int($schema['minItems']) ? max(0, $schema['minItems']) : 0;
            $maximum = isset($schema['maxItems']) && is_int($schema['maxItems']) ? max(0, $schema['maxItems']) : null;
            $size = match ($iteration % 3) {
                0 => $minimum,
                1 => $maximum ?? $prefixCount,
                default => $prefixCount,
            };
            if ($maximum !== null) {
                $size = min($size, $maximum);
            }
            if (($schema['items'] ?? true) === false) {
                $size = min($size, $prefixCount);
            }
            $result = [];
            for ($index = 0; $index < $size; $index++) {
                $item = $prefixItems[$index] ?? ($schema['items'] ?? []);
                if (is_array($item)) {
                    $result[] = self::generateOne($item, $faker, $iteration + $index);
                } else {
                    $result[] = 'item-' . $index;
                }
            }

            return $result;
        }

        $items = $schema['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $minimum = isset($schema['minItems']) && is_int($schema['minItems']) ? max(0, $schema['minItems']) : 1;
        $maximum = isset($schema['maxItems']) && is_int($schema['maxItems']) ? max(0, $schema['maxItems']) : null;
        $size = match ($iteration % 3) {
            0 => $minimum,
            1 => $maximum ?? max(1, $minimum),
            default => max(1, $minimum),
        };
        if ($maximum !== null) {
            $size = min($size, $maximum);
        }
        $result = [];
        for ($i = 0; $i < $size; $i++) {
            $item = self::generateOne($items, $faker, $iteration + $i);
            if (($schema['uniqueItems'] ?? false) === true) {
                $attempt = 0;
                while (in_array($item, $result, true) && $attempt < 100) {
                    $attempt++;
                    $item = self::generateOne($items, $faker, $iteration + $i + $attempt);
                }
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateString(array $schema, ?Generator $faker, int $iteration): string
    {
        $format = isset($schema['format']) && is_string($schema['format']) ? $schema['format'] : null;
        if ($faker !== null && $format !== null) {
            $formatted = self::generateStringByFormat($faker, $format);
            if ($formatted !== null) {
                return self::clampLength($formatted, $schema);
            }
        }

        if ($faker === null && $format !== null && self::isSupportedFormat($format)) {
            // Without faker, format-constrained strings (`email`, `uuid`, …)
            // degrade to the deterministic primitive fallback below — which
            // will not satisfy the format constraint and every fuzzed request
            // will fail at validation. Surface this once per process so the
            // user can install fakerphp/faker, instead of letting the test
            // appear "unstable" with no diagnostic.
            self::warnFakerMissing($format);
        }

        $minLength = isset($schema['minLength']) && is_int($schema['minLength']) && $schema['minLength'] >= 0
            ? $schema['minLength']
            : 1;
        $maxLength = isset($schema['maxLength']) && is_int($schema['maxLength']) && $schema['maxLength'] >= 0
            ? $schema['maxLength']
            : 16;

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $patternValue = self::generateCommonPattern($schema['pattern'], $schema, $iteration);
            if ($patternValue !== null) {
                return $patternValue;
            }

            throw new InvalidArgumentException(sprintf(
                "String pattern '%s' is outside the fuzz generator's supported synthesis subset.",
                $schema['pattern'],
            ));
        }

        if ($faker !== null) {
            // bothify('?') yields random alpha sized to the chosen target.
            // Target is always >= 1 because $maxLength is constrained > 0
            // above and min($maxLength, 8) >= 1. clampLength still runs to
            // honor `minLength > 8` (where the target was capped at 8) and
            // to defensively pad when bothify ever returns a short result.
            $target = match ($iteration % 3) {
                0 => $minLength,
                1 => $maxLength,
                default => max($minLength, min($maxLength, 8)),
            };
            $generated = $faker->bothify(str_repeat('?', $target));

            return self::clampLength($generated, $schema);
        }

        $base = match ($iteration % 3) {
            0 => str_repeat('x', $minLength),
            1 => str_repeat('x', $maxLength),
            default => 'string-' . $iteration,
        };

        return self::clampLength($base, $schema);
    }

    private static function generateStringByFormat(Generator $faker, string $format): ?string
    {
        return match ($format) {
            'email', 'idn-email' => $faker->safeEmail(),
            'uuid' => $faker->uuid(),
            'date' => $faker->date(),
            'date-time' => $faker->iso8601(),
            'time' => $faker->time(),
            'uri', 'url', 'iri' => $faker->url(),
            'hostname' => $faker->domainName(),
            'ipv4' => $faker->ipv4(),
            'ipv6' => $faker->ipv6(),
            default => null,
        };
    }

    /**
     * Mirrors the format keys that {@see self::generateStringByFormat()}
     * actually handles. Listing them here lets the faker-missing warning
     * fire only for cases where faker would have helped — exotic formats
     * (e.g. `byte`, `binary`, `password`) stay silent because the
     * deterministic fallback wasn't going to satisfy them either way.
     */
    private static function isSupportedFormat(string $format): bool
    {
        return in_array(
            $format,
            ['email', 'idn-email', 'uuid', 'date', 'date-time', 'time', 'uri', 'url', 'iri', 'hostname', 'ipv4', 'ipv6'],
            true,
        );
    }

    /**
     * Emit a one-shot warning per missing format. We dedupe by `$format` so
     * a long fuzz run with many email fields still only nags once per format
     * — matching how the rest of the library uses E_USER_WARNING for
     * spec-author advisories (see OpenApiCoverageTracker::warnMalformed()).
     */
    private static function warnFakerMissing(string $format): void
    {
        if (isset(self::$warnedFakerFormats[$format])) {
            return;
        }
        self::$warnedFakerFormats[$format] = true;

        trigger_error(
            sprintf(
                '[openapi-contract-testing] fakerphp/faker is not installed; '
                . "string format '%s' will be generated as a deterministic primitive "
                . 'and is unlikely to satisfy the spec constraint. '
                . 'Install via: composer require --dev fakerphp/faker',
                $format,
            ),
            E_USER_WARNING,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function clampLength(string $value, array $schema): string
    {
        $minLength = isset($schema['minLength']) && is_int($schema['minLength']) && $schema['minLength'] >= 0
            ? $schema['minLength']
            : 0;
        $maxLength = isset($schema['maxLength']) && is_int($schema['maxLength']) && $schema['maxLength'] >= 0
            ? $schema['maxLength']
            : null;

        if ($maxLength !== null && self::unicodeLength($value) > $maxLength) {
            $characters = self::unicodeCharacters($value);
            $value = implode('', array_slice($characters, 0, $maxLength));
        }
        if (self::unicodeLength($value) < $minLength) {
            $value .= str_repeat('x', $minLength - self::unicodeLength($value));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateInteger(array $schema, ?Generator $faker, int $iteration): int
    {
        // Resolve bounds in three modes so a one-sided constraint never produces
        // out-of-range values: when only `maximum: 0` is set, anchoring `min`
        // to a static 1 would silently emit 1 every time. Anchor relative to
        // the supplied bound instead and only fall back to flat defaults when
        // both ends are unspecified.
        $minSet = isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']));
        $maxSet = isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']));

        $exclusiveMin = isset($schema['exclusiveMinimum']) && (is_int($schema['exclusiveMinimum']) || is_float($schema['exclusiveMinimum']))
            ? (int) floor($schema['exclusiveMinimum']) + 1
            : null;
        $exclusiveMax = isset($schema['exclusiveMaximum']) && (is_int($schema['exclusiveMaximum']) || is_float($schema['exclusiveMaximum']))
            ? (int) ceil($schema['exclusiveMaximum']) - 1
            : null;

        if ($minSet && $maxSet) {
            $min = (int) ceil($schema['minimum']);
            $max = (int) floor($schema['maximum']);
        } elseif ($minSet) {
            $min = (int) ceil($schema['minimum']);
            $max = $min + 1000;
        } elseif ($maxSet) {
            $max = (int) floor($schema['maximum']);
            $min = $max - 1000;
        } else {
            $min = 1;
            $max = 1000;
        }

        $min = $exclusiveMin !== null ? max($min, $exclusiveMin) : $min;
        $max = $exclusiveMax !== null ? min($max, $exclusiveMax) : $max;

        $multipleOf = isset($schema['multipleOf']) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))
            ? DecimalMultiple::integerStep($schema['multipleOf'])
            : 0;
        if ($multipleOf !== null && $multipleOf > 0) {
            $min = (int) (ceil($min / $multipleOf) * $multipleOf);
            $max = (int) (floor($max / $multipleOf) * $multipleOf);
        }

        if ($max < $min) {
            // Spec inversion (e.g. min=10, max=5). Honor `min` since it is the
            // tighter constraint for "this value must exist at all"; the tests
            // that pass a contradictory schema are expected to fail validation
            // downstream — we just refuse to amplify the contradiction.
            $max = $min;
        }

        if (($iteration % 3) === 0) {
            return $min;
        }
        if (($iteration % 3) === 1) {
            return $max;
        }
        if ($faker !== null) {
            if ($multipleOf !== null && $multipleOf > 0) {
                return $faker->numberBetween(intdiv($min, $multipleOf), intdiv($max, $multipleOf)) * $multipleOf;
            }

            return $faker->numberBetween($min, $max);
        }

        if ($multipleOf !== null && $multipleOf > 0) {
            $span = intdiv($max - $min, $multipleOf) + 1;

            return $min + ($iteration % max(1, $span)) * $multipleOf;
        }

        $span = $max - $min + 1;

        return $min + ($iteration % max(1, $span));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function generateNumber(array $schema, ?Generator $faker, int $iteration): float
    {
        $minSet = isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']));
        $maxSet = isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']));

        $exclusiveMin = isset($schema['exclusiveMinimum']) && (is_int($schema['exclusiveMinimum']) || is_float($schema['exclusiveMinimum']))
            ? (float) $schema['exclusiveMinimum']
            : null;
        $exclusiveMax = isset($schema['exclusiveMaximum']) && (is_int($schema['exclusiveMaximum']) || is_float($schema['exclusiveMaximum']))
            ? (float) $schema['exclusiveMaximum']
            : null;

        if ($minSet && $maxSet) {
            $min = (float) $schema['minimum'];
            $max = (float) $schema['maximum'];
        } elseif ($minSet) {
            $min = (float) $schema['minimum'];
            $max = $min + 1000.0;
        } elseif ($maxSet) {
            $max = (float) $schema['maximum'];
            $min = $max - 1000.0;
        } else {
            $min = 0.0;
            $max = 1000.0;
        }

        $epsilon = isset($schema['multipleOf']) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))
            ? (float) $schema['multipleOf']
            : max(PHP_FLOAT_EPSILON, ($max - $min) / 1000000.0);
        $min = $exclusiveMin !== null ? max($min, $exclusiveMin + $epsilon) : $min;
        $max = $exclusiveMax !== null ? min($max, $exclusiveMax - $epsilon) : $max;

        $multipleOf = isset($schema['multipleOf']) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))
            ? (float) $schema['multipleOf']
            : 0.0;
        if ($multipleOf > 0.0) {
            $min = round(ceil($min / $multipleOf) * $multipleOf, 12);
            $max = round(floor($max / $multipleOf) * $multipleOf, 12);
        }

        if ($max < $min) {
            $max = $min;
        }

        if (($iteration % 3) === 0) {
            return $min;
        }
        if (($iteration % 3) === 1) {
            return $max;
        }
        if ($faker !== null) {
            if ($multipleOf > 0.0) {
                $minimumMultiplier = (int) ceil($min / $multipleOf);
                $maximumMultiplier = (int) floor($max / $multipleOf);

                return round($faker->numberBetween($minimumMultiplier, $maximumMultiplier) * $multipleOf, 12);
            }

            // randomFloat(null, …) lets faker pick precision dynamically so
            // tight ranges (e.g. minimum=0.001 maximum=0.002) don't collapse
            // to 0.00 from a fixed two-decimal rounding.
            return $faker->randomFloat(null, $min, $max);
        }

        if ($multipleOf > 0.0) {
            $minimumMultiplier = (int) ceil($min / $multipleOf);
            $maximumMultiplier = (int) floor($max / $multipleOf);
            $span = $maximumMultiplier - $minimumMultiplier + 1;

            return round(($minimumMultiplier + $iteration % max(1, $span)) * $multipleOf, 12);
        }

        // Scale the iteration-driven offset to the actual span so the value
        // never escapes [min, max] — using a fixed `iter / 10` step would
        // exit the range whenever the span < 10.
        $span = $max - $min;
        if ($span <= 0.0) {
            return $min;
        }

        return $min + ($iteration % 100) / 100.0 * $span;
    }

    private static function generateBoolean(int $iteration): bool
    {
        return ($iteration % 2) === 1;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function resolveComposition(array $schema, ?Generator $faker, int $iteration): array
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword]) || $schema[$keyword] === []) {
                continue;
            }
            $branches = array_values(array_filter($schema[$keyword], is_array(...)));
            if ($branches === []) {
                continue;
            }
            unset($schema[$keyword]);
            $schema = self::mergeSchemas($schema, $branches[$iteration % count($branches)]);
            break;
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $branches = $schema['allOf'];
            unset($schema['allOf']);
            $conditionals = [];
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                if (isset($branch['if']) && is_array($branch['if'])) {
                    $conditionals[] = $branch;
                } else {
                    $schema = self::mergeSchemas($schema, $branch);
                }
            }
            if ($conditionals !== []) {
                $selected = $conditionals[$iteration % count($conditionals)];
                $schema = self::mergeSchemas($schema, $selected['if']);
                if (isset($selected['then']) && is_array($selected['then'])) {
                    $schema = self::mergeSchemas($schema, $selected['then']);
                }
            }
        }

        if (isset($schema['if']) && is_array($schema['if'])) {
            $useThen = ($iteration % 2) === 0;
            $conditional = $useThen
                ? self::mergeSchemas($schema['if'], is_array($schema['then'] ?? null) ? $schema['then'] : [])
                : self::mergeSchemas(
                    ['not' => $schema['if']],
                    is_array($schema['else'] ?? null) ? $schema['else'] : [],
                );
            unset($schema['if'], $schema['then'], $schema['else']);
            $schema = self::mergeSchemas($schema, $conditional);
        }

        return $schema;
    }

    /**
     * Merge the assertion keywords needed for deterministic allOf generation.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private static function mergeSchemas(array $left, array $right): array
    {
        $merged = array_merge($left, $right);
        if (is_array($left['properties'] ?? null) || is_array($right['properties'] ?? null)) {
            $merged['properties'] = self::mergePropertySchemas(
                is_array($left['properties'] ?? null) ? $left['properties'] : [],
                is_array($right['properties'] ?? null) ? $right['properties'] : [],
            );
        }
        if (is_array($left['required'] ?? null) || is_array($right['required'] ?? null)) {
            $merged['required'] = array_values(array_unique(array_merge(
                is_array($left['required'] ?? null) ? $left['required'] : [],
                is_array($right['required'] ?? null) ? $right['required'] : [],
            )));
        }
        if (isset($left['minimum'], $right['minimum'])) {
            $merged['minimum'] = max($left['minimum'], $right['minimum']);
        }
        if (isset($left['maximum'], $right['maximum'])) {
            $merged['maximum'] = min($left['maximum'], $right['maximum']);
        }
        if (isset($left['minLength'], $right['minLength'])) {
            $merged['minLength'] = max($left['minLength'], $right['minLength']);
        }
        if (isset($left['maxLength'], $right['maxLength'])) {
            $merged['maxLength'] = min($left['maxLength'], $right['maxLength']);
        }
        foreach (['minItems', 'minProperties'] as $minimumKeyword) {
            if (isset($left[$minimumKeyword], $right[$minimumKeyword])) {
                $merged[$minimumKeyword] = max($left[$minimumKeyword], $right[$minimumKeyword]);
            }
        }
        foreach (['maxItems', 'maxProperties'] as $maximumKeyword) {
            if (isset($left[$maximumKeyword], $right[$maximumKeyword])) {
                $merged[$maximumKeyword] = min($left[$maximumKeyword], $right[$maximumKeyword]);
            }
        }
        if ((is_int($left['multipleOf'] ?? null) || is_float($left['multipleOf'] ?? null)) &&
            (is_int($right['multipleOf'] ?? null) || is_float($right['multipleOf'] ?? null))) {
            $multipleOf = DecimalMultiple::leastCommonMultiple($left['multipleOf'], $right['multipleOf']);
            if ($multipleOf === null) {
                throw new InvalidArgumentException(
                    'Cannot compose allOf multipleOf constraints within the platform numeric range.',
                );
            }
            $merged['multipleOf'] = $multipleOf;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private static function mergePropertySchemas(array $left, array $right): array
    {
        $merged = $left;
        foreach ($right as $name => $rightSchema) {
            $leftSchema = $merged[$name] ?? null;
            $merged[$name] = is_array($leftSchema) && is_array($rightSchema)
                ? self::mergeSchemas($leftSchema, $rightSchema)
                : $rightSchema;
        }

        return $merged;
    }

    /** @param array<string, mixed> $schema */
    private static function generateCommonPattern(string $pattern, array $schema, int $iteration): ?string
    {
        $fixedQuantifierValue = self::generateFixedQuantifierPattern($pattern, $schema);
        if ($fixedQuantifierValue !== null) {
            return $fixedQuantifierValue;
        }

        $hostnameValue = self::generateHostnamePattern($pattern, $schema);
        if ($hostnameValue !== null) {
            return $hostnameValue;
        }

        $candidates = ['a', 'A', '0', 'abc', 'ABC', '123', 'test-' . $iteration, 'é', '日本語'];
        $minimum = isset($schema['minLength']) && is_int($schema['minLength']) ? max(0, $schema['minLength']) : null;
        $maximum = isset($schema['maxLength']) && is_int($schema['maxLength']) ? max(0, $schema['maxLength']) : null;
        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);
        foreach ($candidates as $candidate) {
            $candidateLength = self::unicodeLength($candidate);
            $targets = array_values(array_unique(array_filter(
                [$minimum, $maximum, $candidateLength],
                static fn(?int $length): bool => $length !== null,
            )));
            foreach ($targets as $target) {
                if ($maximum !== null && $target > $maximum) {
                    continue;
                }
                $value = self::repeatToLength($candidate, $target);
                if (($minimum === null || self::unicodeLength($value) >= $minimum) &&
                    @preg_match($delimiter . $escaped . $delimiter . 'u', $value) === 1) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $schema */
    private static function generateHostnamePattern(string $pattern, array $schema): ?string
    {
        $labelPrefix = '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+';
        if (!str_starts_with($pattern, $labelPrefix) || !str_ends_with($pattern, '$')) {
            return null;
        }

        $escapedSuffix = substr($pattern, strlen($labelPrefix), -1);
        $suffix = str_replace('\\.', '.', $escapedSuffix);
        if (preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/Di', $suffix) !== 1) {
            return null;
        }

        $minimum = isset($schema['minLength']) && is_int($schema['minLength']) ? max(0, $schema['minLength']) : null;
        $maximum = isset($schema['maxLength']) && is_int($schema['maxLength']) ? max(0, $schema['maxLength']) : null;
        $value = 'a.' . $suffix;
        while ($minimum !== null && self::unicodeLength($value) < $minimum) {
            if (self::unicodeLength($value) >= self::MAX_SYNTHESIZED_PATTERN_LENGTH ||
                ($maximum !== null && self::unicodeLength($value) >= $maximum)) {
                return null;
            }
            $value = 'a.' . $value;
        }

        $length = self::unicodeLength($value);
        if ($length > self::MAX_SYNTHESIZED_PATTERN_LENGTH || ($maximum !== null && $length > $maximum)) {
            return null;
        }

        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);

        return @preg_match($delimiter . $escaped . $delimiter . 'u', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $schema */
    private static function generateFixedQuantifierPattern(string $pattern, array $schema): ?string
    {
        if (preg_match('/^\^(\[[^]]+]|\\\\d)\{([0-9]+)\}\$$/D', $pattern, $matches) !== 1) {
            return null;
        }

        $length = (int) $matches[2];
        $minimum = isset($schema['minLength']) && is_int($schema['minLength']) ? max(0, $schema['minLength']) : null;
        $maximum = isset($schema['maxLength']) && is_int($schema['maxLength']) ? max(0, $schema['maxLength']) : null;
        if ($length > self::MAX_SYNTHESIZED_PATTERN_LENGTH ||
            ($minimum !== null && $length < $minimum) ||
            ($maximum !== null && $length > $maximum)) {
            return null;
        }

        $atom = $matches[1];
        $character = $atom === '\\d' ? '0' : self::characterMatchingClass($atom);
        if ($character === null) {
            return null;
        }

        $value = str_repeat($character, $length);
        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);

        return @preg_match($delimiter . $escaped . $delimiter . 'u', $value) === 1 ? $value : null;
    }

    private static function characterMatchingClass(string $characterClass): ?string
    {
        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $characterClass);
        $expression = $delimiter . '^' . $escaped . '$' . $delimiter . 'u';
        $candidates = self::unicodeCharacters(
            'aA0abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_- ',
        );

        foreach ($candidates as $candidate) {
            if (@preg_match($expression, $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    private static function repeatToLength(string $value, int $length): string
    {
        if ($length === 0 || $value === '') {
            return '';
        }

        $repetitions = (int) ceil($length / self::unicodeLength($value));

        return implode('', array_slice(self::unicodeCharacters(str_repeat($value, $repetitions)), 0, $length));
    }

    private static function unicodeLength(string $value): int
    {
        return count(self::unicodeCharacters($value));
    }

    /** @return list<string> */
    private static function unicodeCharacters(string $value): array
    {
        preg_match_all('/./us', $value, $matches);

        return $matches[0];
    }
}
