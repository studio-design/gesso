<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const JSON_ERROR_DEPTH;
use const JSON_THROW_ON_ERROR;

use JsonException;
use stdClass;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

use function get_debug_type;
use function get_object_vars;
use function is_array;
use function json_decode;
use function sprintf;

/**
 * Pure decoder for in-memory spec documents (JSON / YAML strings).
 * Both `ExternalRefLoader` (filesystem-based) and `HttpRefLoader`
 * (network-based) call into this once they have the raw body in hand,
 * so the parse + mapping-root checks live in one place.
 *
 * `$context` is a free-form string that surfaces in error messages so
 * the caller can pinpoint which ref / file / URL produced the failure
 * without the decoder having to know the source.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SpecDocumentDecoder
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidOpenApiSpecException on malformed JSON or non-mapping root
     */
    public static function decodeJson(string $content, string $context): array
    {
        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // The depth-limit case has a different ergonomic story than a
            // syntax error — surface it explicitly so the user knows the
            // fix is "increase nesting tolerance" rather than "fix your JSON".
            $message = $e->getCode() === JSON_ERROR_DEPTH
                ? sprintf(
                    'JSON document %s exceeds the 512-level nesting limit (likely a deeply '
                    . 'recursive schema). %s',
                    $context,
                    $e->getMessage(),
                )
                : sprintf('Failed to parse JSON document %s: %s', $context, $e->getMessage());

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedJson,
                $message,
                ref: $context,
                previous: $e,
            );
        }

        return self::ensureMappingRoot(self::normalizeObjectMaps($decoded), $context);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidOpenApiSpecException on missing YAML library, parse failure, or non-mapping root
     */
    public static function decodeYaml(string $content, string $context): array
    {
        if (!YamlAvailability::isAvailable()) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::YamlLibraryMissing,
                'Loading YAML documents requires symfony/yaml. '
                . 'Install it via: composer require --dev symfony/yaml',
                ref: $context,
            );
        }

        try {
            $decoded = Yaml::parse($content, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedYaml,
                sprintf('Failed to parse YAML document %s: %s', $context, $e->getMessage()),
                ref: $context,
                previous: $e,
            );
        } catch (Throwable $e) {
            // Symfony's parser raises non-ParseException classes for some
            // inputs (e.g. \InvalidArgumentException on malformed Unicode,
            // \Error on memory exhaustion mid-parse). Without this catch
            // those propagate as raw stack traces and never receive an
            // InvalidOpenApiSpecReason tag. Re-wrap so consumers can
            // branch on `$reason` consistently.
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::MalformedYaml,
                sprintf('YAML document %s failed to decode: %s', $context, $e->getMessage()),
                ref: $context,
                previous: $e,
            );
        }

        return self::ensureMappingRoot(self::normalizeObjectMaps($decoded), $context);
    }

    /**
     * Convert non-empty parsed object maps back to the array representation
     * used by the validator, while temporarily retaining every nested empty
     * object. PHP associative decoding otherwise collapses both `{}` and `[]`
     * to `[]` before the document structure is known.
     *
     * @internal Shared with the root spec loader so root and external `$ref`
     *           documents retain identical collection-shape semantics.
     */
    public static function normalizeObjectMaps(mixed $decoded): mixed
    {
        return self::normalizeValue($decoded, true);
    }

    private static function normalizeValue(mixed $value, bool $isRoot): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            if (!$isRoot && $properties === []) {
                return $value;
            }

            $normalized = [];
            foreach ($properties as $key => $child) {
                $normalized[$key] = self::normalizeValue($child, false);
            }

            return $normalized;
        }

        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = self::normalizeValue($child, false);
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private static function ensureMappingRoot(mixed $decoded, string $context): array
    {
        if (!is_array($decoded)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonMappingRoot,
                sprintf('Document %s must decode to a mapping (got %s)', $context, get_debug_type($decoded)),
                ref: $context,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
