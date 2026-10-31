<?php

declare(strict_types=1);

namespace Studio\Gesso;

use Studio\Gesso\Internal\FailureCatalogue;

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
 *
 * `schemaPointer`, `actual`, and `expected` are set on schema violations
 * only: an RFC 6901 pointer into the schema the value was validated against
 * (the operation's resolved schema for the body, parameter, or header, after
 * `$ref` inlining), ending at the failing keyword, and short JSON excerpts of
 * the failing value and of that keyword's value. `actual` is null for
 * objects and arrays and `<redacted>` for credential-like names; `expected`
 * is null when the keyword's value is a subschema. All three are null for
 * structural, spec-malformation, and synthetic (`required` parameter,
 * security) issues.
 *
 * {@see self::code()} derives the stable `GSO` failure code from `category`
 * and `keyword`; see docs/failure-codes.md.
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
        public ?string $schemaPointer = null,
        public ?string $actual = null,
        public ?string $expected = null,
    ) {}

    /**
     * The stable failure code (`GSO212`, ...) for this issue, or null for a
     * category the catalogue does not know. Run `gesso explain <code>` or see
     * docs/failure-codes.md for what it means and how to fix it.
     */
    public function code(): ?string
    {
        return FailureCatalogue::forIssue($this->category, $this->keyword);
    }
}
