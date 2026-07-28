<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use InvalidArgumentException;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Validation\Support\SchemaViolation;

/**
 * Outcome of {@see ResponseBodyValidator::validate()}.
 *
 * `errors` carries the same string payload the validator previously returned
 * (empty list = body acceptable). `matchedContentType` is the spec key (with
 * the spec author's original casing) that the body validated against, or
 * `null` when no content-type lookup was performed (204-style responses,
 * non-JSON specs we don't validate, content-type-not-in-spec failures).
 *
 * `skipReason`, when non-null, marks that the validator deliberately did NOT
 * check the body even though a media-type key matched and that key declared
 * a `schema`: the response Content-Type is a non-JSON media type this
 * JSON-Schema engine cannot evaluate (issue #254). `errors` stays empty in
 * that case — it is a skip, not a failure — and the orchestrator turns it
 * into an `OpenApiValidationResult::skipped()` so the unvalidated body is
 * not miscounted as a clean pass. A skip always names the matched key, so
 * `matchedContentType` is non-null whenever `skipReason` is set.
 *
 * Coverage tracking uses `matchedContentType` to record per-(status, media-type)
 * granularity instead of treating the whole endpoint as a single bucket.
 *
 * `violations` carries the structured twin of `errors` on the schema-error
 * path only: index-aligned, with `errors[$i]` always equal to
 * `"[{$violations[$i]->instancePath}] {$violations[$i]->message}"`. Every
 * non-schema error site (empty body, decode failure) leaves it empty —
 * consumers must check the counts align before pairing the two lists.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class ResponseBodyValidationResult
{
    /**
     * @param string[] $errors
     * @param list<SchemaViolation> $violations
     *
     * @throws InvalidArgumentException when `skipReason` is set alongside a
     *                                  non-empty `errors` list (a skip is mutually exclusive with
     *                                  reporting errors) or alongside a null `matchedContentType`
     *                                  (a skip is only reached after a media-type key matched).
     *                                  Mirrors the `failure([])` guard on {@see OpenApiValidationResult}.
     */
    public function __construct(
        public array $errors,
        public ?string $matchedContentType,
        public ?string $skipReason = null,
        public array $violations = [],
    ) {
        if ($skipReason !== null && $errors !== []) {
            throw new InvalidArgumentException(
                'A skipped ResponseBodyValidationResult cannot also carry errors: '
                . 'a skip means the body was not checked.',
            );
        }

        if ($skipReason !== null && $matchedContentType === null) {
            throw new InvalidArgumentException(
                'A skipped ResponseBodyValidationResult must name the matched '
                . 'media-type key so coverage records the skip against it.',
            );
        }
    }
}
