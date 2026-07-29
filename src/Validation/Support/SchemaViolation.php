<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

/**
 * One JSON Schema violation reported by {@see SchemaValidatorRunner}, keeping
 * the structured fields (instance pointer, failing keyword) that the flat
 * `[{pointer}] {message}` string rendering discards (issue #282, stage 2).
 *
 * `instancePath` is an RFC 6901 JSON Pointer into the validated instance:
 * `''` (empty string) is the document root and `'/'` is the property whose
 * name is the empty string. This deliberately diverges from opis's
 * `JsonPointer::pathToString()`, which renders both as `'/'` and cannot
 * distinguish them — {@see self::displayPath()} reproduces that legacy
 * rendering for the human-readable views. `keyword` is the JSON Schema
 * keyword that failed (`type`, `required`, …); it is null only for the
 * runner's defensive "no error detail" synthetic entry. `message` is the
 * human-readable prose without the pointer prefix.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class SchemaViolation
{
    public function __construct(
        public string $instancePath,
        public ?string $keyword,
        public string $message,
    ) {}

    /**
     * The pointer as historically rendered in error strings and in
     * {@see SchemaValidatorRunner::validate()} map keys: the document root
     * shows as `'/'` (collapsing the RFC distinction with the empty-string
     * key, which also renders `'/'`). Kept so the flat error prose stays
     * byte-identical while `instancePath` itself is unambiguous.
     */
    public function displayPath(): string
    {
        return $this->instancePath === '' ? '/' : $this->instancePath;
    }
}
