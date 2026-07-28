<?php

declare(strict_types=1);

namespace Studio\Gesso;

use InvalidArgumentException;

use function array_values;
use function count;
use function implode;

final readonly class OpenApiValidationResult
{
    /**
     * Private so the three factories (success / failure / skipped) are the
     * only way to construct a result. The outcome enum narrows the legal
     * state space to exactly those three cases — errors are only attached
     * to Failure, and skipReason is only attached to Skipped.
     *
     * `matchedStatusCode` is the spec response key (e.g. `"200"`, `"5XX"`,
     * `"default"`) that the validator selected, or null when no spec response
     * was matched (path/method-not-found failures, or skipped responses where
     * the lookup happens by literal status before any spec key is consulted —
     * in that case the literal status string is reported instead so coverage
     * can still pin the actually-exercised status).
     *
     * `matchedContentType` is the spec media-type key (with the spec author's
     * original casing) the body was checked against, or null when no body
     * lookup occurred (204, non-JSON-only specs, content-type-not-in-spec
     * failures, and most skipped responses). A Skipped result carries it
     * only for the issue #254 case — a non-JSON media type whose declared
     * `schema` this JSON-Schema engine cannot evaluate.
     *
     * `issues` mirrors `errors` one-to-one when provided (guarded in
     * {@see self::failure()}); an empty list means the caller predates the
     * structured API and {@see self::issues()} derives untagged issues.
     *
     * @param string[] $errors
     * @param list<ValidationIssue> $issues
     */
    private function __construct(
        private OpenApiValidationOutcome $outcome,
        private array $errors = [],
        private ?string $matchedPath = null,
        private ?string $skipReason = null,
        private ?string $matchedStatusCode = null,
        private ?string $matchedContentType = null,
        private array $issues = [],
    ) {}

    public static function success(
        ?string $matchedPath = null,
        ?string $matchedStatusCode = null,
        ?string $matchedContentType = null,
    ): self {
        return new self(
            OpenApiValidationOutcome::Success,
            [],
            $matchedPath,
            null,
            $matchedStatusCode,
            $matchedContentType,
        );
    }

    /**
     * Reject `failure([])` so a Failure always carries at least one error
     * message. Without this guard, `errorMessage()` would return an empty
     * string and the Failure would surface as a silent assertion failure.
     * `non-empty-array` surfaces empty-literal callers in PHPStan; the
     * runtime guard covers consumers without static analysis.
     *
     * Contract note: only literal emptiness (`$errors === []`) is rejected.
     * Vacuous string entries such as `['']`, `['   ']`, or `['', '']` are
     * NOT rejected — the caller is responsible for emitting meaningful,
     * non-empty error messages. This keeps the guard cheap and avoids
     * `trim()`-based heuristics whose correctness depends on validator
     * output conventions (e.g. whether multi-line error messages may
     * legitimately begin with whitespace). If a future validator is
     * observed to emit whitespace-only errors in practice, tightening
     * this guard (e.g. rejecting all-blank arrays) can be reconsidered.
     *
     * When `$issues` is provided it must mirror `$errors` exactly — same
     * count, and `issues[$i]->message === errors[$i]` — so the two views can
     * never drift apart. Callers either tag every error or none.
     *
     * @param non-empty-array<string> $errors
     * @param list<ValidationIssue> $issues
     *
     * @throws InvalidArgumentException when $errors is empty or $issues does not mirror $errors
     */
    public static function failure(
        array $errors,
        ?string $matchedPath = null,
        ?string $matchedStatusCode = null,
        ?string $matchedContentType = null,
        array $issues = [],
    ): self {
        // @phpstan-ignore-next-line identical.alwaysFalse — PHPDoc bound is not enforced at runtime; keep guard for consumers without static analysis
        if ($errors === []) {
            throw new InvalidArgumentException(
                'OpenApiValidationResult::failure() requires at least one error message.',
            );
        }

        if ($issues !== []) {
            $errorList = array_values($errors);
            if (count($issues) !== count($errorList)) {
                throw new InvalidArgumentException(
                    'OpenApiValidationResult::failure() issues must mirror errors one-to-one (count mismatch).',
                );
            }
            foreach ($issues as $index => $issue) {
                if ($issue->message !== $errorList[$index]) {
                    throw new InvalidArgumentException(
                        'OpenApiValidationResult::failure() issues must mirror errors one-to-one (message mismatch at index ' . $index . ').',
                    );
                }
            }
        }

        return new self(
            OpenApiValidationOutcome::Failure,
            $errors,
            $matchedPath,
            null,
            $matchedStatusCode,
            $matchedContentType,
            $issues,
        );
    }

    /**
     * Represents a response whose body was intentionally not validated (e.g. a
     * 5xx production error that the spec does not document). isValid() stays
     * true so callers that gate on it (e.g. PHPUnit assertions) treat the
     * result as non-failing; isSkipped() / outcome() distinguish it from a
     * genuine successful schema match.
     *
     * `matchedStatusCode` for a skipped result is the literal HTTP status
     * string (e.g. `"503"`), not a spec range key — skipping happens before
     * the spec response map is consulted. Coverage tracking reconciles the
     * literal status against any spec range keys (`5XX`/`5xx`/`default`) at
     * compute time, marking the spec-declared response as `skipped`.
     *
     * `matchedContentType` is null for most skip cases (status-code skip,
     * non-JSON-only specs with no Content-Type header — no spec media-type
     * key was resolved). It carries the spec media-type key only when the
     * skip happened *after* a content-type lookup matched a declared key:
     * the "non-JSON media type with an unvalidatable `schema`" response case
     * (issue #254), where it lets coverage record the skip against that exact
     * media-type row instead of the wildcard bucket, and the documented-4xx
     * request downgrade (issue #179), where it preserves the request
     * media-type key the body validator resolved before the downgrade so
     * adapters can still tag their `request.body` issues with it.
     */
    public static function skipped(
        ?string $matchedPath = null,
        ?string $reason = null,
        ?string $matchedStatusCode = null,
        ?string $matchedContentType = null,
    ): self {
        return new self(
            OpenApiValidationOutcome::Skipped,
            [],
            $matchedPath,
            $reason,
            $matchedStatusCode,
            $matchedContentType,
        );
    }

    public function outcome(): OpenApiValidationOutcome
    {
        return $this->outcome;
    }

    public function isValid(): bool
    {
        return match ($this->outcome) {
            OpenApiValidationOutcome::Success, OpenApiValidationOutcome::Skipped => true,
            OpenApiValidationOutcome::Failure => false,
        };
    }

    public function isSkipped(): bool
    {
        return $this->outcome === OpenApiValidationOutcome::Skipped;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    public function errorMessage(): string
    {
        return implode("\n", $this->errors);
    }

    /**
     * Structured view of {@see self::errors()}. Results built by the current
     * orchestrators carry tagged issues; results built by legacy callers of
     * `failure()` derive one issue per error string with category `unknown`
     * and this result's matched operation context, so consumers can rely on
     * `count(issues()) === count(errors())` either way.
     *
     * @return list<ValidationIssue>
     */
    public function issues(): array
    {
        if ($this->issues !== []) {
            return $this->issues;
        }

        $derived = [];
        foreach (array_values($this->errors) as $message) {
            $derived[] = new ValidationIssue(
                'unknown',
                $message,
                path: $this->matchedPath,
                statusCode: $this->matchedStatusCode,
                contentType: $this->matchedContentType,
            );
        }

        return $derived;
    }

    public function matchedPath(): ?string
    {
        return $this->matchedPath;
    }

    public function skipReason(): ?string
    {
        return $this->skipReason;
    }

    public function matchedStatusCode(): ?string
    {
        return $this->matchedStatusCode;
    }

    public function matchedContentType(): ?string
    {
        return $this->matchedContentType;
    }
}
