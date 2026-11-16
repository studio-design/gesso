<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use function array_chunk;
use function array_key_exists;
use function array_map;
use function array_pad;
use function count;
use function explode;
use function in_array;
use function is_bool;
use function is_string;
use function rawurldecode;
use function str_ireplace;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function urldecode;

/**
 * Turns a parameter's serialized string back into the value its schema
 * describes, following the declared `style` / `explode` (OAS 3.2 §4.12.3,
 * Style Examples §4.12.6):
 *
 * - `simple` (path, header): `a,b` → list; objects `R,100,G,200` or, exploded,
 *   `R=100,G=200` → map.
 * - `label` (path): strips the leading `.`; elements are joined by `,`, or by
 *   `.` when exploded.
 * - `matrix` (path): strips `;name=`; an exploded array repeats it
 *   (`;id=1;id=2`) and an exploded object drops it (`;R=100;G=200`).
 * - `form` + `explode: false`, `pipeDelimited`, `spaceDelimited` (query):
 *   split on `,` / `|` / space into a list, or into a map for objects.
 *
 * Primitives come back as one string with the `label` / `matrix` prefix
 * stripped. Exploded `form` values arrive already split by the framework, and
 * `deepObject` values already nested, so they pass through. So does anything
 * this class cannot read — a malformed `style` / `explode`, a missing prefix,
 * an odd-length key/value list — and the schema then reports the mismatch.
 *
 * Splitting happens before percent-decoding wherever the encoded form is
 * available, because RFC 6570 expansion percent-encodes a delimiter inside a
 * value (`%2C`) and joins elements with the literal one. Path values arrive
 * encoded and are decoded per piece with `rawurldecode()`; headers are never
 * percent-decoded (OAS 3.2 §4.12.2.2). Query values arrive decoded, so the
 * caller may supply the raw wire value; it is used only when it decodes to
 * the framework-parsed value — PSR-7 allows the parsed query map to diverge
 * from the URI, and the parsed map is what the application saw. Without a
 * usable raw value the decoded string is split as a best effort.
 *
 * Parameters with no `in` (form-body `encoding` entries) are read as query
 * parameters.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ParameterStyleDeserializer
{
    private const DELIMITERS = [
        'simple' => ',',
        'form' => ',',
        'label' => ',',
        'matrix' => ',',
        'pipeDelimited' => '|',
        'spaceDelimited' => ' ',
    ];

    /**
     * @param array<string, mixed> $parameter
     * @param array<string, mixed> $schema
     * @param null|string $rawValue the query parameter's percent-encoded wire value, when the caller has access to the raw query string
     */
    public static function deserialize(mixed $value, array $parameter, array $schema, ?string $rawValue = null): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        [$wire, $decode] = match ($parameter['in'] ?? 'query') {
            'path' => [$value, rawurldecode(...)],
            'header' => [$value, static fn(string $piece): string => $piece],
            // PSR-7 allows the parsed query map to diverge from the URI; only
            // trust the raw value when it decodes to what the application saw.
            default => $rawValue !== null && urldecode($rawValue) === $value
                ? [$rawValue, urldecode(...)]
                : [$value, static fn(string $piece): string => $piece],
        };
        $untouched = $decode($wire);

        $serialization = self::serialization($parameter);
        if ($serialization === null) {
            return $untouched;
        }
        [$style, $explode] = $serialization;
        $kind = self::kind($schema);

        $pieces = self::pieces($wire, $style, $explode, $kind, self::name($parameter), $wire === $rawValue);
        if ($pieces === null) {
            return $untouched;
        }

        if ($kind === null) {
            return $decode($pieces[0]);
        }

        if ($kind === 'array') {
            return array_map($decode, $pieces);
        }

        $object = [];
        if ($explode) {
            foreach ($pieces as $pair) {
                $pair = explode('=', $pair, 2);
                if (count($pair) !== 2) {
                    return $untouched;
                }
                $object[$decode($pair[0])] = $decode($pair[1]);
            }

            return $object;
        }

        if (count($pieces) % 2 !== 0) {
            return $untouched;
        }
        foreach (array_chunk($pieces, 2) as [$key, $item]) {
            $object[$decode($key)] = $decode($item);
        }

        return $object;
    }

    /**
     * The leading delimiter a `label` / `matrix` value must start with
     * (OAS 3.2 §4.12.5: it "MUST be included"), when `$value` lacks it.
     * `null` when the value has it, or the style has none.
     *
     * @param array<string, mixed> $parameter
     * @param array<string, mixed> $schema
     */
    public static function missingPrefix(string $value, array $parameter, array $schema): ?string
    {
        $serialization = self::serialization($parameter);
        if ($serialization === null) {
            return null;
        }

        $prefix = self::prefix($serialization[0], $serialization[1], self::kind($schema), self::name($parameter));

        return str_starts_with($value, $prefix) ? null : $prefix;
    }

    /**
     * Parse a raw query string into parameter name → percent-encoded values
     * in wire order. Names are decoded (to match framework-parsed maps);
     * values are kept encoded so non-exploded styles can be split before
     * decoding. WireQueryParser (PSR-7, the MockHttpClient fake) builds on this.
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

    /**
     * The declared `style` and `explode`, defaulted by location (OAS 3.2
     * §4.12.2.2), or `null` for a style this class does not read. An explicit
     * `style: null` / `explode: null` is a malformed declaration, not an
     * unspecified one, so it must not collapse to the defaults.
     *
     * @param array<string, mixed> $parameter
     *
     * @return null|array{string, bool}
     */
    private static function serialization(array $parameter): ?array
    {
        $style = array_key_exists('style', $parameter)
            ? $parameter['style']
            : (in_array($parameter['in'] ?? 'query', ['path', 'header'], true) ? 'simple' : 'form');
        $explode = array_key_exists('explode', $parameter) ? $parameter['explode'] : $style === 'form';

        return is_string($style) && is_bool($explode) && isset(self::DELIMITERS[$style]) ? [$style, $explode] : null;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return null|'array'|'object'
     */
    private static function kind(array $schema): ?string
    {
        return TypeCoercer::isArraySchema($schema) ? 'array' : (TypeCoercer::isObjectSchema($schema) ? 'object' : null);
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private static function name(array $parameter): string
    {
        $name = $parameter['name'] ?? null;

        return is_string($name) ? $name : '';
    }

    /**
     * @param null|'array'|'object' $kind
     */
    private static function prefix(string $style, bool $explode, ?string $kind, string $name): string
    {
        return match ($style) {
            'label' => '.',
            // Style Examples: `;color=blue`, `;color=blue;color=black`, and an
            // exploded object drops the name: `;R=100;G=200`.
            'matrix' => match (true) {
                $explode && $kind === 'object' => ';',
                $explode && $kind === 'array' => ";{$name}=",
                default => ";{$name}",
            },
            default => '',
        };
    }

    /**
     * Strip the style's prefix and split the rest into still-encoded pieces:
     * one piece for a primitive, the elements for an array, and for an
     * object either `key=value` pairs (exploded) or alternating keys and
     * values. `null` when the value does not have the declared shape, or
     * when the style leaves nothing to split.
     *
     * @param null|'array'|'object' $kind
     *
     * @return null|list<string>
     */
    private static function pieces(string $wire, string $style, bool $explode, ?string $kind, string $name, bool $isRawQuery): ?array
    {
        $prefix = self::prefix($style, $explode, $kind, $name);
        if (!str_starts_with($wire, $prefix)) {
            return null;
        }
        $wire = substr($wire, strlen($prefix));
        $delimiter = self::DELIMITERS[$style];

        if ($style === 'label') {
            $delimiter = $explode ? '.' : ',';
        } elseif ($style === 'matrix' && $explode && $kind !== null) {
            $delimiter = $kind === 'object' ? ';' : ";{$name}=";
        } elseif ($style === 'matrix') {
            // `;color` alone is the empty value; otherwise `=` must follow.
            if ($wire !== '' && !str_starts_with($wire, '=')) {
                return null;
            }
            $wire = substr($wire, 1);
        } elseif ($kind !== null && $explode && $style !== 'simple') {
            // An exploded query value arrives already split into repeated keys.
            return null;
        }

        if ($kind === null) {
            return [$wire];
        }

        if ($isRawQuery && $style === 'spaceDelimited') {
            // The space delimiter itself can only appear percent-encoded on
            // the wire: `%20` (RFC 6570 expansion) or `+` (form-urlencoding).
            // A literal plus in data stays `%2B` and is untouched by the
            // normalization.
            $wire = str_replace('%20', '+', $wire);
            $delimiter = '+';
        } elseif ($isRawQuery && $style === 'pipeDelimited') {
            // The OAS Style Examples serialize the pipeDelimited delimiter
            // percent-encoded (`blue%7Cblack%7Cbrown`) because `|` is not a
            // legal query character, but clients also send it literally.
            // Normalizing makes both split; a pipe inside a value is
            // consequently unrepresentable (undefined per OAS Appendix E).
            $wire = str_ireplace('%7C', '|', $wire);
        }

        return explode($delimiter, $wire);
    }
}
