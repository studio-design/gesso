<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Studio\Gesso\UploadedPart;

use function explode;
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
     *
     * @return array{array<string, mixed>, list<string>} the prepared data and any
     *                                                   encoding-level errors (JSON parts that do not parse, part
     *                                                   content types the encoding object forbids)
     */
    public static function prepare(array $fields, array $schema, array $encoding): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $errors = [];

        foreach ($fields as $name => $value) {
            $partEncoding = is_array($encoding[$name] ?? null) ? $encoding[$name] : [];
            $declaredContentType = is_string($partEncoding['contentType'] ?? null)
                ? $partEncoding['contentType']
                : null;

            if ($value instanceof UploadedPart || self::containsPart($value)) {
                $errors = [...$errors, ...self::checkPartContentTypes((string) $name, $value, $declaredContentType)];
                $fields[$name] = self::substituteParts($value);

                continue;
            }

            // league/openapi-psr7-validator#234: a part declared as JSON by the
            // encoding object arrives as a string and must be decoded before
            // its subschema means anything.
            if ($declaredContentType !== null && is_string($value) && ContentTypeMatcher::isJsonContentType(
                ContentTypeMatcher::normalizeMediaType($declaredContentType),
            )) {
                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                    $fields[$name] = $decoded;
                } catch (JsonException $e) {
                    $errors[] = sprintf(
                        "[/%s] part declares encoding contentType '%s' but its content is not valid JSON: %s",
                        $name,
                        $declaredContentType,
                        $e->getMessage(),
                    );
                }

                continue;
            }

            $propertySchema = $properties[$name] ?? null;
            if (is_array($propertySchema)) {
                /** @var array<string, mixed> $propertySchema */
                $fields[$name] = TypeCoercer::coerceQuery($value, $propertySchema);
            }
        }

        return [$fields, $errors];
    }

    /**
     * Whether `encoding.<part>.contentType` accepts the part's own content type.
     * The declared value may be a single media type, a `<type>/*` range, or a
     * comma-separated list of either.
     */
    private static function contentTypeAccepted(string $partContentType, string $declared): bool
    {
        $candidates = [];
        foreach (explode(',', $declared) as $entry) {
            $entry = ContentTypeMatcher::normalizeMediaType(trim($entry));
            if ($entry !== '') {
                $candidates[$entry] = true;
            }
        }

        if ($candidates === []) {
            return true;
        }

        return ContentTypeMatcher::findContentTypeKey(
            ContentTypeMatcher::normalizeMediaType($partContentType),
            $candidates,
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
            if ($part->contentType === null || self::contentTypeAccepted($part->contentType, $declaredContentType)) {
                continue;
            }

            $errors[] = sprintf(
                "[/%s] part Content-Type '%s' does not match the declared encoding contentType '%s'.",
                $name,
                $part->contentType,
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
