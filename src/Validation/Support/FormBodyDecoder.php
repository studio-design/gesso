<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Studio\Gesso\UploadedPart;

use function array_fill_keys;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function parse_str;
use function sprintf;
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
 * `encoding.headers` / `style` / `explode` are not honoured yet.
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
     * Coerce each field to the type its property subschema declares and apply
     * the `encoding` rules.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $schema the media type's OpenAPI schema
     * @param array<string, mixed> $encoding the media type's `encoding` object
     * @param string $normalizedMediaType the request's form media type; only
     *                                    a multipart body can carry file parts
     *
     * @return array{array<string, mixed>, list<string>} the prepared data and any
     *                                                   encoding-level errors (JSON parts that do not parse, part
     *                                                   content types the encoding object forbids)
     */
    public static function prepare(
        array $fields,
        array $schema,
        array $encoding,
        string $normalizedMediaType = self::MULTIPART,
    ): array {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $errors = [];

        foreach ($fields as $name => $value) {
            $propertySchema = is_array($properties[$name] ?? null) ? $properties[$name] : null;
            $partEncoding = is_array($encoding[$name] ?? null) ? $encoding[$name] : [];
            $declaredContentType = is_string($partEncoding['contentType'] ?? null)
                ? $partEncoding['contentType']
                : null;

            if ($value instanceof UploadedPart || self::containsPart($value)) {
                $errors = [...$errors, ...self::checkPartContentTypes((string) $name, $value, $declaredContentType)];
                $fields[$name] = self::substituteParts($value);

                continue;
            }

            // A part the schema describes as raw bytes must arrive as a file
            // part. This is the one part-shape mismatch that is decidable
            // without the part's own Content-Type header — which no supported
            // adapter preserves for non-file parts — so it is checked from the
            // schema rather than guessed at from the value.
            if ($normalizedMediaType === self::MULTIPART && self::declaresBinaryContent($propertySchema)) {
                $errors[] = sprintf(
                    '[/%s] part is declared as binary content but did not arrive as a file part.',
                    $name,
                );

                continue;
            }

            // Which media types this part may carry. An explicit
            // `encoding.<part>.contentType` wins; otherwise OAS 3.0.3's
            // per-property default applies, which is what makes an `object`
            // part JSON without any encoding entry at all.
            $candidates = $declaredContentType !== null
                ? self::mediaTypeList($declaredContentType)
                : self::defaultContentTypes($propertySchema);

            // league/openapi-psr7-validator#234: a JSON part arrives as a
            // string and must be decoded before its subschema means anything.
            //
            // Only when the contract admits nothing but JSON, though. OAS 3.2
            // §4.15.4.1 selects among several declared media types by the
            // part's own Content-Type and forbids media-type sniffing as the
            // default behaviour; since that header does not survive any
            // adapter's form parsing, a mixed list like
            // `application/json, text/plain` is left undecoded rather than
            // resolved by "it happened to parse". Ordering is irrelevant
            // either way.
            // With several declared media types the part's own Content-Type
            // would decide, so JSON is only forced when nothing else could
            // satisfy the property schema anyway (a plain-text alternative
            // cannot produce an object or an array). That keeps the choice
            // driven by the contract instead of by what happens to parse.
            $jsonOnly = self::allJsonCandidates($candidates);
            $jsonForced = !$jsonOnly &&
                self::hasJsonCandidate($candidates) &&
                self::rejectsPlainString($propertySchema);

            if (is_string($value) && $candidates !== [] && ($jsonOnly || $jsonForced)) {
                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
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

            if ($propertySchema !== null) {
                $fields[$name] = TypeCoercer::coerceQuery($value, $propertySchema);
            }
        }

        return [$fields, $errors];
    }

    /**
     * Whether a property schema describes raw bytes rather than a value the
     * form encoding can carry as text: OAS 3.0's `format: binary`, or the
     * 3.1+ replacement of a `contentMediaType` with no text `contentEncoding`.
     * `format: byte` (base64) is deliberately excluded — it travels fine as a
     * plain field.
     *
     * @param null|array<string, mixed> $propertySchema
     */
    private static function declaresBinaryContent(?array $propertySchema): bool
    {
        if ($propertySchema === null) {
            return false;
        }

        if (($propertySchema['format'] ?? null) === 'binary') {
            return true;
        }

        return isset($propertySchema['contentMediaType']) && !isset($propertySchema['contentEncoding']);
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
     * omitted, per the OAS 3.0.3 Encoding Object: `application/json` for an
     * object, `application/octet-stream` for a binary string, `text/plain`
     * for the other primitives, and the inner type's default for an array.
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

        // A schema with no `type` but object-shaped keywords is still an
        // object part; composition keywords stay unclassified rather than
        // guessing a media type for a schema that may accept several shapes.
        if ($type === 'object' || ($type === null && isset($propertySchema['properties']))) {
            return ['application/json'];
        }

        if ($type === 'string') {
            return [($propertySchema['format'] ?? null) === 'binary' ? 'application/octet-stream' : 'text/plain'];
        }

        return $type === null ? [] : ['text/plain'];
    }

    /**
     * @param list<string> $candidates
     */
    private static function hasJsonCandidate(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (ContentTypeMatcher::isJsonContentType($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the property schema can hold a plain string at all. A part whose
     * schema is an object or an array cannot be the text alternative of a
     * mixed `contentType` list, which makes JSON the only readable choice
     * without inspecting the part's content.
     *
     * @param null|array<string, mixed> $propertySchema
     */
    private static function rejectsPlainString(?array $propertySchema): bool
    {
        if ($propertySchema === null) {
            return false;
        }

        $type = $propertySchema['type'] ?? null;
        if (is_array($type)) {
            $type = TypeCoercer::firstPrimitiveType($type);
        }

        return $type === 'object' || $type === 'array';
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
