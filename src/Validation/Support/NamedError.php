<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

/**
 * One sub-validator error message together with the name of the spec object
 * it is about (a parameter, response header, or security scheme).
 *
 * The name is what makes violation-baseline fingerprints (issue #402)
 * distinguish "known `limit` violation" from "new `page` violation" on the
 * same operation without depending on message wording. `null` means the
 * error is not attributable to a single named object (structural spec
 * errors, error-boundary captures, whole-querystring checks without a
 * usable name).
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class NamedError
{
    public function __construct(
        public ?string $name,
        public string $message,
    ) {}
}
