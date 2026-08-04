<?php

declare(strict_types=1);

namespace Studio\Gesso;

/**
 * A file part of a `multipart/form-data` request body, as the validators see it.
 *
 * Framework adapters map their own uploaded-file objects (Laravel / Symfony
 * `UploadedFile`, PSR-7 `UploadedFileInterface`) onto this envelope so the
 * framework-independent body validator can reason about a binary part without
 * reading its bytes: `contentType` is the part's declared `Content-Type`, which
 * an `encoding.<part>.contentType` entry is checked against, and `filename` is
 * the client-supplied name.
 *
 * The part's *content* is deliberately not carried. OpenAPI describes binary
 * parts with `type: string, format: binary` (3.0) or `contentMediaType` (3.1+),
 * none of which this JSON Schema engine can check against real bytes, so a
 * binary part is validated for presence and declared content type only — see
 * `docs/supported-features.md`.
 */
final readonly class UploadedPart
{
    public function __construct(
        public ?string $contentType = null,
        public ?string $filename = null,
    ) {}
}
