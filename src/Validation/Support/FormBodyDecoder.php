<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\UploadedPart;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_pad;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function parse_str;
use function rawurldecode;
use function sprintf;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Turn a form request body (`application/x-www-form-urlencoded` or
 * `multipart/form-data`) into the shape the JSON Schema engine can validate.
 *
 * Form values always arrive as strings (or as {@see UploadedPart} for file
 * parts), so they are coerced against the declared property schemas the same
 * way query parameters are ({@see TypeCoercer::coerceQuery()}). The media
 * type's `encoding` object drives two extra rules: a part declared as JSON is
 * decoded before its subschema is applied, and a binary part's declared
 * `Content-Type` is checked against `encoding.<part>.contentType`.
 *
 * A part declaring `style` / `explode` / `allowReserved` is read as RFC 6570
 * form serialization instead (its `contentType` is ignored, per the spec), so
 * a non-exploded array is split by {@see QueryStyleDeserializer}. Declared
 * per-part `headers` cannot be observed — no adapter preserves them — and are
 * reported back so the caller can skip rather than pass.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class FormBodyDecoder
{
    public const MULTIPART = 'multipart/form-data';
    public const URLENCODED = 'application/x-www-form-urlencoded';

    /**
     * Whether a normalized media type is a form body this class can decode.
     */
    public static function isFormMediaType(string $normalizedMediaType): bool
    {
        return $normalizedMediaType === self::URLENCODED || $normalizedMediaType === self::MULTIPART;
    }

    /**
     * Normalize a decoded body value into a field map, or `null` when the
     * caller has nothing usable — the adapter left the body undecoded, or a
     * multipart body only reached us as raw bytes (no parser here reassembles
     * parts; adapters hand over their framework's parsed parts instead).
     *
     * @return null|array<string, mixed>
     */
    public static function toFieldMap(mixed $body, string $normalizedMediaType): ?array
    {
        if (is_array($body)) {
            /** @var array<string, mixed> $body */
            return $body;
        }

        // A raw urlencoded body is parseable here. parse_str() applies PHP's
        // legacy key mangling (`a.b` / `a b` become `a_b`), which is the same
        // shape frameworks produce from the same bytes, so a spec written
        // against a real PHP app stays consistent.
        if (is_string($body) && $normalizedMediaType === self::URLENCODED) {
            parse_str($body, $parsed);

            return $parsed;
        }

        return null;
    }

    /**
     * The percent-encoded wire value of each field of a raw urlencoded body,
     * as {@see self::toFieldMap()} reads the same bytes: parse_str()'s key
     * mangling (`a.b` becomes `a_b`) and its last-pair-wins rule included, so
     * {@see self::prepare()} pairs every raw value with the parsed one.
     *
     * @return array<string, string>
     */
    public static function rawUrlencodedValues(string $body): array
    {
        // Mangle each pair's name before applying last-wins: `a.b` and `a_b`
        // collide only after mangling, so grouping by raw name first would
        // keep the wrong pair.
        $values = [];
        foreach (explode('&', $body) as $pair) {
            [$encodedName, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            parse_str($encodedName . '=', $parsed);
            $key = array_key_first($parsed);
            if ($key !== null) {
                $values[(string) $key] = $encodedValue;
            }
        }

        return $values;
    }

    /**
     * Coerce each field to the type its property subschema declares and apply
     * the `encoding` rules.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $schema the media type's OpenAPI schema
     * @param array<string, mixed> $encoding the media type's `encoding` object
     * @param string $normalizedMediaType the body's form media type; only a
     *                                    multipart body carries parts whose own Content-Type and headers
     *                                    the encoding object talks about
     * @param OpenApiVersion $version OpenAPI 3.0 applies `style` / `explode` / `allowReserved` to
     *                                urlencoded bodies only; 3.1 and later to multipart as well
     * @param array<string, string> $rawValues percent-encoded wire value per field, when the
     *                                         caller has the raw urlencoded body
     *                                         ({@see self::rawUrlencodedValues()})
     *
     * @return array{array<string, mixed>, list<string>, array<string, array{reason: string, candidates: list<string>}>, list<string>}
     *                                                                                                                                 the prepared data; encoding-level errors (parts that contradict
     *                                                                                                                                 the contract); keyed by field name, why a part could not be
     *                                                                                                                                 checked plus the media types it may have carried (the caller
     *                                                                                                                                 re-reads those parts every way the contract allows, so an
     *                                                                                                                                 unverifiable part is neither counted as a clean pass nor allowed
     *                                                                                                                                 to mask — or fabricate — a violation elsewhere in the body);
     *                                                                                                                                 and why declared per-part headers went unchecked
     */
    public static function prepare(
        array $fields,
        array $schema,
        array $encoding,
        string $normalizedMediaType = self::MULTIPART,
        OpenApiVersion $version = OpenApiVersion::V3_1,
        array $rawValues = [],
    ): array {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $errors = [];
        $unverifiable = [];
        $headerSkips = [];
        $isMultipart = $normalizedMediaType === self::MULTIPART;
        // OAS 3.0 scopes style / explode / allowReserved to urlencoded bodies
        // ("SHALL be ignored" otherwise); 3.1 and 3.2 extend them to multipart.
        $stylesApply = $normalizedMediaType === self::URLENCODED ||
            ($isMultipart && $version !== OpenApiVersion::V3_0);

        foreach ($fields as $name => $value) {
            $propertySchema = is_array($properties[$name] ?? null) ? $properties[$name] : null;
            $partEncoding = is_array($encoding[$name] ?? null) ? $encoding[$name] : [];
            $declaredContentType = is_string($partEncoding['contentType'] ?? null)
                ? $partEncoding['contentType']
                : null;

            // Per-part headers exist only in multipart, and no adapter
            // preserves them, so a declared one goes unchecked — report it
            // instead of passing. `Content-Type` is excluded: the spec says to
            // ignore it here (it is `encoding.<part>.contentType`'s job).
            if ($isMultipart && is_array($partEncoding['headers'] ?? null)) {
                $declared = array_filter(
                    array_keys($partEncoding['headers']),
                    static fn(int|string $header): bool => is_string($header) && strtolower($header) !== 'content-type',
                );
                if ($declared !== []) {
                    $headerSkips[] = sprintf(
                        "part '%s' declares per-part headers (%s) that form parsing does not preserve, so they were not checked",
                        $name,
                        implode(', ', $declared),
                    );
                }
            }

            // A property declaring raw bytes must arrive as a file part —
            // including every element of an array property, which is how
            // OpenAPI describes a multi-file upload. Checked from the schema
            // because it is decidable without the part's Content-Type header.
            if ($isMultipart && self::expectsFileParts($propertySchema) && self::hasNonFileLeaf($value)) {
                $errors[] = sprintf(
                    '[/%s] part is declared as binary content but did not arrive as a file part.',
                    $name,
                );

                // Still normalize any real file parts alongside it, so the
                // schema pass does not pile a second, confusing violation on
                // top of this one.
                $fields[$name] = self::substituteParts($value);

                continue;
            }

            // Any of style / explode / allowReserved selects RFC 6570 form
            // serialization, and then "the value of contentType (implicit or
            // explicit) SHALL be ignored".
            $styled = $stylesApply && (array_key_exists('style', $partEncoding) ||
                array_key_exists('explode', $partEncoding) ||
                array_key_exists('allowReserved', $partEncoding));

            if ($value instanceof UploadedPart || self::containsPart($value)) {
                if (!$styled) {
                    $errors = [...$errors, ...self::checkPartContentTypes((string) $name, $value, $declaredContentType)];
                }
                $fields[$name] = self::substituteParts($value);

                continue;
            }

            // A styled part skips the JSON decoding below. Only the array
            // split applies; allowReserved changes nothing a decoded value
            // still shows.
            if ($styled) {
                $rawValue = $rawValues[$name] ?? null;
                // Form parsing leaves a multipart part's body as sent, so the
                // value is still RFC 6570 percent-encoded: it is the raw wire
                // value. RFC 6570 never emits `+` for a space, so a literal
                // plus is data and is protected from urldecode().
                if ($isMultipart && is_string($value)) {
                    $rawValue = str_replace('+', '%2B', $value);
                    $value = rawurldecode($value);
                    $fields[$name] = $value;
                }

                if ($propertySchema !== null) {
                    $value = QueryStyleDeserializer::deserialize($value, $partEncoding, $propertySchema, $rawValue);
                    $fields[$name] = TypeCoercer::coerceQuery($value, $propertySchema);
                }

                continue;
            }

            // Which media types this part may carry. An explicit
            // `encoding.<part>.contentType` wins; otherwise the OAS
            // per-property default applies, which is what makes an `object`
            // part JSON without any encoding entry at all.
            $candidates = $declaredContentType !== null
                ? self::mediaTypeList($declaredContentType)
                : self::defaultContentTypes($propertySchema);

            // league/openapi-psr7-validator#234: a JSON part arrives as a
            // string and must be decoded before its subschema means anything.
            // Parsing it is not sniffing — when JSON is all the contract
            // allows, a successful parse confirms the declared type and a
            // failed one contradicts it.
            if (is_string($value) && $candidates !== [] && self::allJsonCandidates($candidates)) {
                try {
                    // Objects stay objects: `json_decode(..., true)` would turn
                    // `{}` into `[]`, which then fails its own `type: object`.
                    /** @var mixed $decoded */
                    $decoded = json_decode($value, false, flags: JSON_THROW_ON_ERROR);
                    $fields[$name] = $decoded;

                    continue;
                } catch (JsonException $e) {
                    $errors[] = sprintf(
                        '[/%s] part is declared as %s but its content is not valid JSON: %s',
                        $name,
                        implode(', ', $candidates),
                        $e->getMessage(),
                    );

                    continue;
                }
            }

            // Anything else needs the part's own Content-Type to resolve: to
            // choose between several declared media types (OAS 3.2 §4.15.4.1
            // — sniffing is not a substitute) or to confirm a single non-text
            // one. No adapter preserves that header for a non-file part, so
            // the honest outcome is a skip, not a pass and not a guess.
            if ($isMultipart && !self::triviallySatisfied($candidates)) {
                $unverifiable[(string) $name] = [
                    'reason' => sprintf(
                        "part '%s' declares %s, which cannot be confirmed because the part's own Content-Type "
                        . 'is not preserved by form parsing',
                        $name,
                        implode(', ', $candidates),
                    ),
                    'candidates' => $candidates,
                ];

                continue;
            }

            if ($propertySchema !== null) {
                $fields[$name] = TypeCoercer::coerceQuery($value, $propertySchema);
            }
        }

        return [$fields, $errors, $unverifiable, $headerSkips];
    }

    /**
     * Whether a property describes raw bytes the wire carries as a file part.
     *
     * OAS 3.1 writes raw binary as a `contentMediaType` with **no** `type`,
     * precisely because a JSON string cannot hold arbitrary bytes; OAS 3.0
     * wrote the same thing as `format: binary`. The empty schema counts too —
     * its default media type is `application/octet-stream`, which is what
     * makes the OAS 3.2 multi-file example `type: array, items: {}` a list of
     * files. An array of any of them qualifies.
     *
     * A declared `type` rules binary out: per JSON Schema 2020-12 a string
     * with a `contentMediaType` and no `contentEncoding` is identity-encoded
     * UTF-8 text, so `type: string, contentMediaType: application/sql` is an
     * ordinary field whatever its media type. `format: byte` and an explicit
     * `contentEncoding` such as `base64` are text on the wire as well.
     *
     * @param null|array<string, mixed> $propertySchema
     */
    private static function expectsFileParts(?array $propertySchema): bool
    {
        if ($propertySchema === null) {
            return false;
        }

        if ($propertySchema === []) {
            return true;
        }

        $type = $propertySchema['type'] ?? null;
        if (is_array($type)) {
            $type = TypeCoercer::firstPrimitiveType($type);
        }

        if ($type === 'array') {
            $items = $propertySchema['items'] ?? null;

            return is_array($items) && self::expectsFileParts($items);
        }

        if (isset($propertySchema['contentEncoding'])) {
            return false;
        }

        if ($type !== null) {
            return ($propertySchema['format'] ?? null) === 'binary';
        }

        return isset($propertySchema['contentMediaType']);
    }

    /**
     * Whether the value carries anything that is not a file part — the scalar
     * itself, or any element of an array that is not an {@see UploadedPart}.
     */
    private static function hasNonFileLeaf(mixed $value): bool
    {
        if ($value instanceof UploadedPart) {
            return false;
        }

        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $item) {
            if (self::hasNonFileLeaf($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every candidate media type is satisfied by any text a form field
     * can carry, so nothing is left to confirm. An empty candidate list means
     * the spec declares no content type for the part at all.
     *
     * @param list<string> $candidates
     */
    private static function triviallySatisfied(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== 'text/plain') {
                return false;
            }
        }

        return true;
    }

    /**
     * Split an `encoding.<part>.contentType` value into normalized media
     * types. The value may be a single media type, a `<type>/*` range, or a
     * comma-separated list of either.
     *
     * @return list<string>
     */
    private static function mediaTypeList(string $declared): array
    {
        $candidates = [];
        foreach (explode(',', $declared) as $entry) {
            $entry = ContentTypeMatcher::normalizeMediaType(trim($entry));
            if ($entry !== '' && !in_array($entry, $candidates, true)) {
                $candidates[] = $entry;
            }
        }

        return $candidates;
    }

    /**
     * The media types a part carries when `encoding.<part>.contentType` is
     * omitted, per the OAS Encoding Object: `application/json` for an object,
     * `application/octet-stream` for a binary string or a schema that does
     * not say what it holds, `text/plain` for the other primitives, and the
     * inner type's default for an array. `contentMediaType` is not part of
     * this computation — OpenAPI says to ignore it where the two disagree.
     *
     * Only used to decide whether a part is JSON — an omitted `contentType`
     * is a default for the sender, not a constraint, so it is never matched
     * against a file part's own Content-Type.
     *
     * @param null|array<string, mixed> $propertySchema
     *
     * @return list<string>
     */
    private static function defaultContentTypes(?array $propertySchema): array
    {
        if ($propertySchema === null) {
            return [];
        }

        $type = $propertySchema['type'] ?? null;
        if (is_array($type)) {
            $type = TypeCoercer::firstPrimitiveType($type);
        }

        if ($type === 'array') {
            $items = $propertySchema['items'] ?? null;

            return is_array($items) ? self::defaultContentTypes($items) : [];
        }

        if ($type === 'object') {
            return ['application/json'];
        }

        if ($type === 'string') {
            return [($propertySchema['format'] ?? null) === 'binary' ? 'application/octet-stream' : 'text/plain'];
        }

        if ($type !== null) {
            return ['text/plain'];
        }

        // A schema that does not declare a type defaults to the octet stream,
        // whatever else it says — an object-shaped `properties` block with no
        // `type` is not a licence to read the part as JSON.
        // `contentMediaType` is deliberately not consulted either: the
        // Encoding Object's default is computed from the property type alone,
        // and OpenAPI says to ignore `contentMediaType` when the two disagree.
        return ['application/octet-stream'];
    }

    /**
     * Whether every declared candidate is a JSON media type — the case where a
     * part's content can be treated as JSON without observing its own
     * Content-Type header.
     *
     * @param list<string> $candidates
     */
    private static function allJsonCandidates(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (!ContentTypeMatcher::isJsonContentType($candidate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `encoding.<part>.contentType` accepts the part's own content type.
     */
    private static function contentTypeAccepted(string $partContentType, string $declared): bool
    {
        $candidates = self::mediaTypeList($declared);

        if ($candidates === []) {
            return true;
        }

        return ContentTypeMatcher::findContentTypeKey(
            ContentTypeMatcher::normalizeMediaType($partContentType),
            array_fill_keys($candidates, true),
        ) !== null;
    }

    /**
     * @return list<string>
     */
    private static function checkPartContentTypes(string $name, mixed $value, ?string $declaredContentType): array
    {
        if ($declaredContentType === null) {
            return [];
        }

        $errors = [];
        foreach (self::flattenParts($value) as $part) {
            // RFC 7578 §4.4: a part with no Content-Type header is text/plain.
            // Treating "unknown" as "acceptable" instead would let any part
            // slip past a declared constraint the moment its type is unknown.
            $partContentType = $part->contentType ?? 'text/plain';

            if (self::contentTypeAccepted($partContentType, $declaredContentType)) {
                continue;
            }

            $errors[] = sprintf(
                "[/%s] part Content-Type '%s' does not match the declared encoding contentType '%s'.",
                $name,
                $part->contentType ?? $partContentType . ' (no Content-Type on the part)',
                $declaredContentType,
            );
        }

        return $errors;
    }

    /**
     * @return list<UploadedPart>
     */
    private static function flattenParts(mixed $value): array
    {
        if ($value instanceof UploadedPart) {
            return [$value];
        }

        $parts = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                $parts = [...$parts, ...self::flattenParts($item)];
            }
        }

        return $parts;
    }

    private static function containsPart(mixed $value): bool
    {
        return is_array($value) && self::flattenParts($value) !== [];
    }

    /**
     * Replace file parts with their filename so the surrounding schema still
     * sees a string where `type: string, format: binary` is declared, and
     * `required` counts the part as present. `format: binary` is advisory in
     * OpenAPI, so no string constraint is derived from the real bytes.
     */
    private static function substituteParts(mixed $value): mixed
    {
        if ($value instanceof UploadedPart) {
            return $value->filename ?? '';
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::substituteParts($item);
            }
        }

        return $value;
    }
}
