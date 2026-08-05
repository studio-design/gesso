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
     *                                    a multipart body carries parts whose own Content-Type the
     *                                    encoding object talks about
     *
     * @return array{array<string, mixed>, list<string>, list<string>} the prepared
     *                                                                 data, encoding-level errors (parts that contradict the
     *                                                                 contract), and the reasons a part could not be checked at
     *                                                                 all — the caller turns those into a body-level skip so an
     *                                                                 unverifiable part is never counted as a clean pass
     */
    public static function prepare(
        array $fields,
        array $schema,
        array $encoding,
        string $normalizedMediaType = self::MULTIPART,
    ): array {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $errors = [];
        $unverifiable = [];
        $isMultipart = $normalizedMediaType === self::MULTIPART;

        foreach ($fields as $name => $value) {
            $propertySchema = is_array($properties[$name] ?? null) ? $properties[$name] : null;
            $partEncoding = is_array($encoding[$name] ?? null) ? $encoding[$name] : [];
            $declaredContentType = is_string($partEncoding['contentType'] ?? null)
                ? $partEncoding['contentType']
                : null;

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

            if ($value instanceof UploadedPart || self::containsPart($value)) {
                $errors = [...$errors, ...self::checkPartContentTypes((string) $name, $value, $declaredContentType)];
                $fields[$name] = self::substituteParts($value);

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

            // Anything else needs the part's own Content-Type to resolve: to
            // choose between several declared media types (OAS 3.2 §4.15.4.1
            // — sniffing is not a substitute) or to confirm a single non-text
            // one. No adapter preserves that header for a non-file part, so
            // the honest outcome is a skip, not a pass and not a guess.
            if ($isMultipart && !self::triviallySatisfied($candidates)) {
                $unverifiable[] = sprintf(
                    "part '%s' declares %s, which cannot be confirmed because the part's own Content-Type "
                    . 'is not preserved by form parsing',
                    $name,
                    implode(', ', $candidates),
                );

                continue;
            }

            if ($propertySchema !== null) {
                $fields[$name] = TypeCoercer::coerceQuery($value, $propertySchema);
            }
        }

        return [$fields, $errors, $unverifiable];
    }

    /**
     * Whether a property describes raw bytes the wire carries as a file part.
     *
     * OAS 3.0's `format: binary` and its documented OAS 3.1 replacement
     * `contentMediaType: application/octet-stream` qualify; an array of them
     * does too, since that is how a multi-file upload is described. A bare
     * `contentMediaType` does not: JSON Schema 2020-12 defines a string with
     * no `contentEncoding` as identity-encoded UTF-8 text, so
     * `contentMediaType: text/plain` is an ordinary field. `format: byte` and
     * `contentEncoding: base64` are likewise text on the wire.
     *
     * @param null|array<string, mixed> $propertySchema
     */
    private static function expectsFileParts(?array $propertySchema): bool
    {
        if ($propertySchema === null) {
            return false;
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

        return ($propertySchema['format'] ?? null) === 'binary' ||
            ($propertySchema['contentMediaType'] ?? null) === 'application/octet-stream';
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
            // OAS 3.1+ expresses a part's media type with `contentMediaType`;
            // 3.0's `format: binary` means the octet stream.
            $contentMediaType = $propertySchema['contentMediaType'] ?? null;
            if (is_string($contentMediaType)) {
                return [ContentTypeMatcher::normalizeMediaType($contentMediaType)];
            }

            return [($propertySchema['format'] ?? null) === 'binary' ? 'application/octet-stream' : 'text/plain'];
        }

        return $type === null ? [] : ['text/plain'];
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
