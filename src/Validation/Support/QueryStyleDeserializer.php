<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use function explode;
use function is_array;
use function is_string;

/**
 * Splits non-exploded query parameter values according to the declared
 * `style` / `explode` serialization before schema validation.
 *
 * Supported: `form` + `explode: false` (comma), `pipeDelimited` (pipe) and
 * `spaceDelimited` (space) for `type: array` schemas. Exploded values arrive
 * as repeated keys already parsed into arrays by the framework, so they pass
 * through untouched, as does anything this class does not understand
 * (`deepObject`, object schemas, malformed style/explode declarations).
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class QueryStyleDeserializer
{
    private const DELIMITERS = [
        'form' => ',',
        'pipeDelimited' => '|',
        'spaceDelimited' => ' ',
    ];

    /**
     * @param array<string, mixed> $parameter
     * @param array<string, mixed> $schema
     */
    public static function deserialize(mixed $value, array $parameter, array $schema): mixed
    {
        if (!is_string($value) || !self::isArraySchema($schema)) {
            return $value;
        }

        $style = $parameter['style'] ?? 'form';
        if (!is_string($style)) {
            return $value;
        }

        $delimiter = self::DELIMITERS[$style] ?? null;
        if ($delimiter === null) {
            return $value;
        }

        // `explode` defaults to true for `form` and false for every other
        // style (OAS 3.x, Parameter Object). Only a non-exploded declaration
        // means the delimiter-joined form was used on the wire.
        $explode = $parameter['explode'] ?? ($style === 'form');
        if ($explode !== false) {
            return $value;
        }

        // `?name=` is the serialization of an empty list, not of [""].
        if ($value === '') {
            return [];
        }

        return explode($delimiter, $value);
    }

    /** @param array<string, mixed> $schema */
    private static function isArraySchema(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $type = TypeCoercer::firstPrimitiveType($type);
        }

        return $type === 'array';
    }
}
