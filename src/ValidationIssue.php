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
 * `instancePath` (RFC 6901 JSON Pointer into the validated body, `''` =
 * document root) and `keyword` (the failing JSON Schema keyword) are
 * populated on body-schema violations and stay null for every other error
 * source (issue #282, stage 2). `parameter` names the spec object a
 * non-body issue is about — the request parameter (`request.parameter.*`),
 * response header (`response.header`), or security scheme
 * (`request.security`) — and is null for body issues and for errors not
 * attributable to a single named object (issue #402).
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
