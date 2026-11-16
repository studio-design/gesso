<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use Opis\JsonSchema\Helper;

use function array_chunk;
use function array_key_exists;
use function array_map;
use function array_pad;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function ltrim;
use function preg_match;
use function rawurldecode;
use function str_contains;
use function str_ends_with;
use function str_ireplace;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
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
 * - `form` + `explode: false`, `pipeDelimited`, `spaceDelimited` (query), and
 *   `form` / `cookie` + `explode: false` (cookie): split on `,` / `|` / space
 *   into a list, or into a map for objects.
 *
 * Primitives come back as one string with the `label` / `matrix` prefix
 * stripped. Exploded `form` / `cookie` values arrive already split into
 * separate keys, and `deepObject` values already nested, so they pass through
 * ({@see self::spreadObject()} gathers an exploded object). So does anything
 * this class cannot read — a malformed `style` / `explode`, a missing prefix,
 * an odd-length key/value list — and the schema then reports the mismatch.
 *
 * Splitting happens before percent-decoding wherever the encoded form is
 * available, because RFC 6570 expansion percent-encodes a delimiter inside a
 * value (`%2C`) and joins elements with the literal one. Path values arrive
 * encoded and are decoded per piece with `rawurldecode()`; headers are never
 * percent-decoded (OAS 3.2 §4.12.2.2), and cookies arrive already decoded
 * (PHP's `$_COOKIE`, {@see self::parseCookieHeader()}), so neither is
 * decoded here. Query values arrive decoded, so the caller may supply the
 * raw wire value; it is used only when it decodes to
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
        'cookie' => ',',
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
            'header', 'cookie' => [$value, static fn(string $piece): string => $piece],
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
     * Rebuild an object parameter whose properties travel as values of their
     * own: an exploded `form` query object (`R=100&G=200`), an exploded
     * `form` / `cookie` cookie object (`R=100; G=200`), and a `deepObject`
     * (`color[R]=100`) when the query map was not parsed with PHP's bracket
     * syntax — the client-side PSR-7 adapter and the MockHttpClient fake keep
     * `color[R]` as a flat key. Frameworks hand a `deepObject` over already
     * nested, under `$name`.
     *
     * An exploded object owns every key that no other parameter of the
     * operation in the same location declares and that its schema can hold
     * as a property ({@see self::admitsProperty()}). Two such objects that can
     * both hold a key each receive it: OpenAPI leaves that serialization
     * ambiguous.
     *
     * @param array<string, mixed> $parameter
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $values the location's name → value map (query parameters or cookies)
     * @param list<array<string, mixed>> $parameters every parameter of the operation
     *
     * @return null|array<string, mixed> `null` when no property was sent
     */
    public static function spreadObject(string $name, array $parameter, array $schema, array $values, array $parameters): ?array
    {
        if (!TypeCoercer::isObjectSchema($schema)) {
            return null;
        }

        $in = $parameter['in'] ?? 'query';
        $style = $parameter['style'] ?? 'form';
        $object = [];
        if ($style === 'deepObject') {
            foreach ($values as $key => $value) {
                $key = (string) $key;
                if (str_starts_with($key, "{$name}[") && str_ends_with($key, ']')) {
                    $object[substr($key, strlen($name) + 1, -1)] = $value;
                }
            }
        } elseif (in_array($style, ['form', 'cookie'], true) && ($parameter['explode'] ?? true) === true) {
            $others = [];
            foreach ($parameters as $other) {
                if (($other['in'] ?? null) === $in && is_string($other['name'] ?? null) && $other['name'] !== $name) {
                    $others[] = $other['name'];
                }
            }

            foreach ($values as $key => $value) {
                $key = (string) $key;
                if (!self::claimedByOther($key, $others) && self::admitsProperty($schema, $key)) {
                    $object[$key] = $value;
                }
            }
        }

        return $object === [] ? null : $object;
    }

    /**
     * Parse `Cookie` request header lines (RFC 6265 §4.2: `n1=v1; n2=v2`)
     * into name → value, for adapters that have no framework-parsed cookie
     * map. Values are `rawurldecode()`d, as PHP does for `$_COOKIE`; a
     * repeated name keeps its last value.
     *
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    public static function parseCookieHeader(array $lines): array
    {
        $cookies = [];
        foreach ($lines as $line) {
            foreach (explode(';', $line) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || !str_contains($pair, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $pair, 2);
                $name = trim($name);
                if ($name === '') {
                    continue;
                }

                $cookies[$name] = rawurldecode(ltrim($value));
            }
        }

        return $cookies;
    }

    /**
     * Whether another parameter declares the key, directly or as one of its
     * `deepObject` members (`filter[age]`).
     *
     * @param list<string> $others
     */
    private static function claimedByOther(string $key, array $others): bool
    {
        foreach ($others as $other) {
            if ($key === $other || (str_starts_with($key, "{$other}[") && str_ends_with($key, ']'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the object schema can hold `$key` as a property: it is declared
     * in `properties`, matches a `patternProperties` pattern (with the regex
     * conversion opis applies), or neither `additionalProperties` nor
     * `unevaluatedProperties` is `false`. `allOf` branches all constrain the
     * same object, so every one of them must admit it.
     *
     * @param array<string, mixed> $schema
     */
    private static function admitsProperty(array $schema, string $key): bool
    {
        $properties = $schema['properties'] ?? null;
        $admitted = is_array($properties) && array_key_exists($key, $properties);

        $patterns = $schema['patternProperties'] ?? null;
        foreach (is_array($patterns) ? $patterns : [] as $pattern => $_) {
            if ($admitted) {
                break;
            }
            $admitted = @preg_match(Helper::patternToRegex((string) $pattern), $key) === 1;
        }

        $admitted = $admitted || (($schema['additionalProperties'] ?? true) !== false && ($schema['unevaluatedProperties'] ?? true) !== false);
        if (!$admitted) {
            return false;
        }

        $branches = $schema['allOf'] ?? null;
        foreach (is_array($branches) ? $branches : [] as $branch) {
            if (is_array($branch) && !self::admitsProperty($branch, $key)) {
                return false;
            }
        }

        return true;
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
        // OAS 3.2 `explode`: "When `style` is `form` or `cookie`, the default value is `true`."
        $explode = array_key_exists('explode', $parameter) ? $parameter['explode'] : in_array($style, ['form', 'cookie'], true);

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
