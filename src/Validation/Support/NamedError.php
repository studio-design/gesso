<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

/**
 * One sub-validator error message together with the name of the spec object
 * it is about (a parameter, response header, or security scheme) and, when
 * the error came from a JSON Schema run against the named value, the failing
 * keyword and the RFC 6901 pointer into that value.
 *
 * Name, keyword, and pointer are what make violation-baseline fingerprints
 * (issue #402) distinguish "known `limit` violation" from "new `page`
 * violation" — and a known missing-`limit` from a future type-mismatched
 * `limit` — on the same operation without depending on message wording.
 * `name === null` means the error is not attributable to a single named
 * object (structural spec errors, error-boundary captures). `keyword` is
 * also set to `required` for missing required parameters / headers, which
 * never reach a schema run; it stays null for spec-malformation and other
 * structural errors, which therefore still collapse per
 * `(operation, category, name)` in the baseline.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class NamedError
{
    public function __construct(
        public ?string $name,
        public string $message,
        public ?string $instancePath = null,
        public ?string $keyword = null,
    ) {}
}
