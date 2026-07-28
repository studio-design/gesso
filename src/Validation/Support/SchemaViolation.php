<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

/**
 * One JSON Schema violation reported by {@see SchemaValidatorRunner}, keeping
 * the structured fields (instance pointer, failing keyword) that the flat
 * `[{pointer}] {message}` string rendering discards (issue #282, stage 2).
 *
 * `instancePath` is the JSON Pointer into the validated instance exactly as
 * opis's ErrorFormatter renders it (`/` = document root). `keyword` is the
 * JSON Schema keyword that failed (`type`, `required`, …); it is null only
 * for the runner's defensive "no error detail" synthetic entry. `message` is
 * the human-readable prose without the pointer prefix.
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
}
