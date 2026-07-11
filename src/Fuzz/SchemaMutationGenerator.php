<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use Faker\Generator;
use InvalidArgumentException;
use stdClass;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function sprintf;
use function str_repeat;
use function str_replace;

/**
 * Produces deterministic, single-constraint mutations from a known-valid value.
 * Unsupported constraints are omitted instead of emitting an ambiguously invalid case.
 *
 * @internal
 */
final class SchemaMutationGenerator
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return list<SchemaMutation>
     */
    public static function generate(array $schema, int $count, ?Generator $faker, int $iteration = 0): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException(sprintf('Invalid mutation count must be >= 1, got %d.', $count));
        }

        $valid = SchemaDataGenerator::generateOne($schema, $faker, $iteration);
        SchemaValueValidator::assertValid($valid, $schema, $iteration);
        $candidates = self::candidates($schema, $valid, '');
        $invalid = [];
        foreach ($candidates as $candidate) {
            if (SchemaValueValidator::isValid($candidate->value, $schema)) {
                continue;
            }
            $relaxedSchema = self::schemaWithoutTargetConstraint($schema, $candidate);
            if ($relaxedSchema === null || !SchemaValueValidator::isValid($candidate->value, $relaxedSchema)) {
                continue;
            }
            $invalid[] = $candidate;
            if (count($invalid) === $count) {
                break;
            }
        }

        if ($invalid === []) {
            throw new InvalidArgumentException('No deterministic invalid mutation is supported for this schema.');
        }

        return $invalid;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<SchemaMutation>
     */
    private static function candidates(array $schema, mixed $valid, string $pointer): array
    {
        $result = [];
        $type = $schema['type'] ?? null;
        $hasExactValueConstraint = array_key_exists('const', $schema) ||
            (isset($schema['enum']) && is_array($schema['enum']));

        foreach (['oneOf', 'anyOf', 'allOf'] as $composition) {
            if (!isset($schema[$composition]) || !is_array($schema[$composition])) {
                continue;
            }
            foreach ([null, false, '__composition_miss__', [], ['__composition_miss__' => true]] as $candidate) {
                $result[] = new SchemaMutation($candidate, $composition, $pointer);
            }
        }
        if (isset($schema['not']) && is_array($schema['not'])) {
            $result[] = new SchemaMutation(
                SchemaDataGenerator::generateOne($schema['not'], null, 0),
                'not',
                $pointer,
            );
        }

        if (is_string($type) && !$hasExactValueConstraint) {
            $wrong = match ($type) {
                'object' => 'not-an-object',
                'array' => 'not-an-array',
                'string' => 42,
                'integer', 'number' => 'not-a-number',
                'boolean' => 'not-a-boolean',
                'null' => false,
                default => null,
            };
            $result[] = new SchemaMutation($wrong, 'type', $pointer);
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $candidate = self::valueOutsideExactConstraint($schema, 'enum', $schema['enum']);
            if ($candidate !== null) {
                $result[] = new SchemaMutation($candidate['value'], 'enum', $pointer);
            }
        }
        if (array_key_exists('const', $schema)) {
            $candidate = self::valueOutsideExactConstraint($schema, 'const', [$schema['const']]);
            if ($candidate !== null) {
                $result[] = new SchemaMutation($candidate['value'], 'const', $pointer);
            }
        }

        if (is_string($valid) && !$hasExactValueConstraint) {
            if (isset($schema['minLength']) && is_int($schema['minLength']) && $schema['minLength'] > 0) {
                $result[] = new SchemaMutation(str_repeat('x', $schema['minLength'] - 1), 'minLength', $pointer);
            }
            if (isset($schema['maxLength']) && is_int($schema['maxLength'])) {
                $result[] = new SchemaMutation(str_repeat('x', $schema['maxLength'] + 1), 'maxLength', $pointer);
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                foreach (["\n", '__pattern_miss__', '123', 'abc'] as $candidate) {
                    $result[] = new SchemaMutation($candidate, 'pattern', $pointer);
                }
            }
            if (isset($schema['format']) && is_string($schema['format'])) {
                $result[] = new SchemaMutation('not-a-' . $schema['format'], 'format', $pointer);
            }
        }

        if (is_int($valid) || is_float($valid)) {
            if (isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']))) {
                $result[] = new SchemaMutation($schema['minimum'] - 1, 'minimum', $pointer);
            }
            if (isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']))) {
                $result[] = new SchemaMutation($schema['maximum'] + 1, 'maximum', $pointer);
            }
            if (isset($schema['exclusiveMinimum']) && (is_int($schema['exclusiveMinimum']) || is_float($schema['exclusiveMinimum']))) {
                $result[] = new SchemaMutation($schema['exclusiveMinimum'], 'exclusiveMinimum', $pointer);
            }
            if (isset($schema['exclusiveMaximum']) && (is_int($schema['exclusiveMaximum']) || is_float($schema['exclusiveMaximum']))) {
                $result[] = new SchemaMutation($schema['exclusiveMaximum'], 'exclusiveMaximum', $pointer);
            }
            if (isset($schema['multipleOf']) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))) {
                $multipleOfCandidate = self::multipleOfCandidate($schema, $valid);
                if ($multipleOfCandidate !== null) {
                    $result[] = new SchemaMutation($multipleOfCandidate, 'multipleOf', $pointer);
                }
            }
        }

        if (is_array($valid) && array_is_list($valid)) {
            if (isset($schema['minItems']) && is_int($schema['minItems']) && $schema['minItems'] > 0) {
                $result[] = new SchemaMutation(array_slice($valid, 0, $schema['minItems'] - 1), 'minItems', $pointer);
            }
            if (isset($schema['maxItems']) && is_int($schema['maxItems'])) {
                $expanded = $valid;
                while (count($expanded) <= $schema['maxItems']) {
                    $expanded[] = $valid[0] ?? null;
                }
                $result[] = new SchemaMutation($expanded, 'maxItems', $pointer);
            }
            if (($schema['uniqueItems'] ?? false) === true && $valid !== []) {
                $result[] = new SchemaMutation([$valid[0], $valid[0]], 'uniqueItems', $pointer);
            }
        }

        if (is_array($valid) && !array_is_list($valid)) {
            $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
            foreach ($required as $name) {
                if (!is_string($name) || !array_key_exists($name, $valid)) {
                    continue;
                }
                $mutated = $valid;
                unset($mutated[$name]);
                $result[] = new SchemaMutation(
                    $mutated === [] ? new stdClass() : $mutated,
                    'required',
                    $pointer . '/' . $name,
                );
            }
            if (($schema['additionalProperties'] ?? null) === false) {
                $mutated = $valid;
                $mutated['__unexpected__'] = true;
                $result[] = new SchemaMutation($mutated, 'additionalProperties', $pointer . '/__unexpected__');
            }
            if (isset($schema['minProperties']) && is_int($schema['minProperties']) && $schema['minProperties'] > 0) {
                $result[] = new SchemaMutation(new stdClass(), 'minProperties', $pointer);
            }
            if (isset($schema['maxProperties']) && is_int($schema['maxProperties'])) {
                $mutated = $valid;
                while (count($mutated) <= $schema['maxProperties']) {
                    $mutated['extra' . count($mutated)] = true;
                }
                $result[] = new SchemaMutation($mutated, 'maxProperties', $pointer);
            }

            $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
            foreach ($properties as $name => $propertySchema) {
                if (!is_string($name) || !is_array($propertySchema) || !array_key_exists($name, $valid)) {
                    continue;
                }
                foreach (self::candidates($propertySchema, $valid[$name], $pointer . '/' . $name) as $nested) {
                    $mutated = $valid;
                    $mutated[$name] = $nested->value;
                    $result[] = new SchemaMutation($mutated, $nested->keyword, $nested->pointer);
                }
            }
        }

        return $result;
    }

    /**
     * Remove only the mutation's target assertion at its instance pointer.
     * A candidate is accepted only when it validates against this relaxed
     * schema, proving that no sibling constraint is also required to explain
     * the failure.
     *
     * @param array<string, mixed> $schema
     *
     * @return null|array<string, mixed>
     */
    private static function schemaWithoutTargetConstraint(array $schema, SchemaMutation $mutation): ?array
    {
        $segments = $mutation->pointer === ''
            ? []
            : array_map(
                static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
                explode('/', ltrim($mutation->pointer, '/')),
            );
        $propertySegments = in_array($mutation->keyword, ['required', 'additionalProperties'], true)
            ? array_slice($segments, 0, -1)
            : $segments;

        $relaxed = $schema;
        $cursor = &$relaxed;
        foreach ($propertySegments as $segment) {
            if (!isset($cursor['properties'][$segment]) || !is_array($cursor['properties'][$segment])) {
                return null;
            }
            $cursor = &$cursor['properties'][$segment];
        }

        if ($mutation->keyword === 'required') {
            $propertyName = $segments[count($segments) - 1] ?? null;
            if (!is_string($propertyName) || !is_array($cursor['required'] ?? null)) {
                return null;
            }
            $cursor['required'] = array_values(array_filter(
                $cursor['required'],
                static fn(mixed $name): bool => $name !== $propertyName,
            ));

            return $relaxed;
        }

        if (!array_key_exists($mutation->keyword, $cursor)) {
            return null;
        }
        unset($cursor[$mutation->keyword]);
        if ($cursor === []) {
            $cursor['title'] = 'relaxed mutation target';
        }

        return $relaxed;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed> $excluded
     *
     * @return null|array{value: mixed}
     */
    private static function valueOutsideExactConstraint(array $schema, string $keyword, array $excluded): ?array
    {
        unset($schema[$keyword]);
        if ($schema === []) {
            $schema['title'] = 'relaxed exact-value target';
        }
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $candidate = SchemaDataGenerator::generateOne($schema, null, $iteration);
            if (!in_array($candidate, $excluded, true) && SchemaValueValidator::isValid($candidate, $schema)) {
                return ['value' => $candidate];
            }
        }

        return null;
    }

    /**
     * Find a same-type value that remains valid when only `multipleOf` is
     * removed. Some combinations are tautological (`integer` +
     * `multipleOf: 1`), so no honest single-constraint mutation exists.
     *
     * @param array<string, mixed> $schema
     */
    private static function multipleOfCandidate(array $schema, float|int $valid): null|float|int
    {
        $withoutMultipleOf = $schema;
        unset($withoutMultipleOf['multipleOf']);

        if (is_int($valid)) {
            $integerStep = DecimalMultiple::integerStep($schema['multipleOf']);
            if ($integerStep === null || $integerStep === 1) {
                return null;
            }
            for ($offset = 1; $offset <= 1000; $offset++) {
                foreach ([$valid + $offset, $valid - $offset] as $candidate) {
                    if (SchemaValueValidator::isValid($candidate, $withoutMultipleOf) &&
                        !SchemaValueValidator::isValid($candidate, $schema)) {
                        return $candidate;
                    }
                }
            }

            return null;
        }

        $multipleOf = (float) $schema['multipleOf'];
        foreach ([$valid + $multipleOf / 2, $valid - $multipleOf / 2] as $candidate) {
            if (SchemaValueValidator::isValid($candidate, $withoutMultipleOf) &&
                !SchemaValueValidator::isValid($candidate, $schema)) {
                return $candidate;
            }
        }

        return null;
    }
}
