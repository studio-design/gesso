<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use function array_key_exists;
use function array_map;
use function array_pad;
use function explode;
use function is_array;
use function is_string;
use function str_ireplace;
use function str_replace;
use function urldecode;

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
 * Splitting prefers the raw (still percent-encoded) wire value when the
 * caller supplies one: RFC 6570 form-style expansion percent-encodes a comma
 * inside a value (`%2C`) and joins elements with the literal comma, so only
 * the pre-decoding form can tell data from structure. The raw value is used
 * only when it decodes to the framework-parsed value — PSR-7 allows the
 * parsed query map to diverge from the URI, and the parsed map is what the
 * application saw. Without a usable raw value the decoded string is split as
 * a best effort — correct unless a value contains the delimiter character.
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
     * @param null|string $rawValue the parameter's percent-encoded wire value, when the caller has access to the raw query string
     */
    public static function deserialize(mixed $value, array $parameter, array $schema, ?string $rawValue = null): mixed
    {
        if (!is_string($value) || !self::isArraySchema($schema)) {
            return $value;
        }

        // An explicit `style: null` / `explode: null` is a malformed
        // declaration, not an unspecified one — distinguish it from an absent
        // key so it falls through untouched instead of collapsing to the
        // defaults.
        $style = array_key_exists('style', $parameter) ? $parameter['style'] : 'form';
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
        $explode = array_key_exists('explode', $parameter) ? $parameter['explode'] : $style === 'form';
        if ($explode !== false) {
            return $value;
        }

        // PSR-7 explicitly allows the parsed query map to diverge from the
        // URI (withQueryParams() does not update it). The parsed map is what
        // the application saw, so only use the raw wire value when it decodes
        // to the parsed value.
        if ($rawValue !== null && urldecode($rawValue) !== $value) {
            $rawValue = null;
        }

        if ($rawValue !== null) {
            // The space delimiter itself can only appear percent-encoded on
            // the wire: `%20` (RFC 6570 expansion) or `+` (form-urlencoding).
            // A literal plus in data stays `%2B` and is untouched by the
            // normalization.
            if ($style === 'spaceDelimited') {
                $rawValue = str_replace('%20', '+', $rawValue);
                $delimiter = '+';
            }

            // The OAS Style Examples serialize the pipeDelimited delimiter
            // percent-encoded (`blue%7Cblack%7Cbrown`) because `|` is not a
            // legal query character, but clients also send it literally.
            // Normalizing makes both split; a pipe inside a value is
            // consequently unrepresentable (undefined per OAS Appendix E).
            if ($style === 'pipeDelimited') {
                $rawValue = str_ireplace('%7C', '|', $rawValue);
            }

            return array_map(urldecode(...), explode($delimiter, $rawValue));
        }

        return explode($delimiter, $value);
    }

    /**
     * Parse a raw query string into parameter name → percent-encoded values
     * in wire order. Names are decoded (to match framework-parsed maps);
     * values are kept encoded so non-exploded styles can be split before
     * decoding. Mirrors the pair-splitting conventions of the PSR-7 adapter.
     *
     * @return array<string, list<string>>
     */
    public static function parseRawValues(string $rawQueryString): array
    {
        $values = [];
        foreach (explode('&', $rawQueryString) as $pair) {
            [$encodedName, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($encodedName);
            if ($name === '') {
                continue;
            }

            $values[$name][] = $encodedValue;
        }

        return $values;
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
