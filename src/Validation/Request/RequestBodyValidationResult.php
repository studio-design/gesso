<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use InvalidArgumentException;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Validation\Response\ResponseBodyValidationResult;
use Studio\Gesso\Validation\Support\SchemaViolation;
use Studio\Gesso\ValidationIssue;

/**
 * Outcome of {@see RequestBodyValidator::validate()}.
 *
 * `errors` carries the same string payload the validator previously returned
 * directly (empty list = body acceptable). `skipReason`, when non-null, marks
 * that the validator deliberately did NOT check the body even though a
 * media-type key matched and that key declared a `schema`: the request
 * Content-Type is a non-JSON media type this JSON-Schema engine cannot
 * evaluate (issue #254). `errors` stays empty in that case — it is a skip,
 * not a failure — and {@see OpenApiRequestValidator} turns it into an
 * `OpenApiValidationResult::skipped()` (when no sibling validator failed) so
 * the unvalidated body is not miscounted as a clean pass and the skip reason
 * reaches coverage tracking.
 *
 * If a sibling validator (path / query / header / security) failed, the
 * orchestrator builds a `failure()` instead and the `skipReason` is dropped
 * — a genuine failure takes precedence over a skip.
 *
 * This mirrors {@see ResponseBodyValidationResult} on the response side.
 * `matchedContentType` is the spec media-type key the body was resolved
 * against, or null when validation stopped before a media-type lookup
 * (missing/malformed `requestBody` nodes, Content-Type not defined).
 * Request-side coverage has no per-content-type dimension; the key is
 * carried so the orchestrator can attach it to `request.body`
 * {@see ValidationIssue}s (issue #282).
 *
 * `violations` carries the structured twin of `errors` on the schema-error
 * path only: index-aligned, with `errors[$i]` always equal to
 * `"[{$violations[$i]->displayPath()}] {$violations[$i]->message}"` (the
 * display path renders the RFC 6901 root pointer `''` as the legacy `/`).
 * Every non-schema error site (missing required body, unknown content type,
 * exception boundary) leaves it empty — consumers must check the counts
 * align before pairing the two lists.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class RequestBodyValidationResult
{
    /**
     * @param string[] $errors
     * @param list<SchemaViolation> $violations
     *
     * @throws InvalidArgumentException when `skipReason` is set alongside a
     *                                  non-empty `errors` list — a skip means the body was deliberately
     *                                  not checked, which is mutually exclusive with reporting errors.
     *                                  Mirrors the `failure([])` guard on {@see OpenApiValidationResult}.
     */
    public function __construct(
        public array $errors,
        public ?string $skipReason = null,
        public ?string $matchedContentType = null,
        public array $violations = [],
    ) {
        if ($skipReason !== null && $errors !== []) {
            throw new InvalidArgumentException(
                'A skipped RequestBodyValidationResult cannot also carry errors: '
                . 'a skip means the body was not checked.',
            );
        }
    }
}
