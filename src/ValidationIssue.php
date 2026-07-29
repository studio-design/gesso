<?php

declare(strict_types=1);

namespace Studio\Gesso;

/**
 * One structured validation finding, carried alongside the flat error strings
 * on {@see OpenApiValidationResult}.
 *
 * `category` is a stable slug and part of the documented compatibility
 * surface (see docs/versioning.md); `message` is the exact human-readable
 * error text and remains explicitly outside the compatibility contract.
 * `instancePath` (RFC 6901 JSON Pointer, `''` = document root) and `keyword`
 * (the failing JSON Schema keyword) are populated on schema violations: for
 * body issues the pointer is into the validated body (issue #282, stage 2);
 * for parameter / response-header issues it is into the named value.
 * `keyword` additionally carries synthetic violation kinds — `'required'`
 * when a required parameter, header, or security credential is missing and
 * `'format'` when a credential is present but unusable (issue #402). Both
 * stay null for structural and spec-malformation errors. `parameter` names the spec object a non-body issue is about — the
 * request parameter (`request.parameter.*`), response header
 * (`response.header`), or security scheme (`request.security`) — and is null
 * for body issues and for errors not attributable to a single named object.
 */
final readonly class ValidationIssue
{
    public function __construct(
        public string $category,
        public string $message,
        public ?string $instancePath = null,
        public ?string $keyword = null,
        public ?string $method = null,
        public ?string $path = null,
        public ?string $statusCode = null,
        public ?string $contentType = null,
        public ?string $parameter = null,
    ) {}
}
