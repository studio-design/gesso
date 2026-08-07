<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\ValidationIssue;

use function explode;
use function implode;
use function preg_match;
use function sprintf;

/**
 * Stable identity of one contract violation for the violation baseline
 * (issue #402).
 *
 * The tuple deliberately excludes the human-readable message — wording is
 * not a compatibility surface (docs/versioning.md) — so a baseline survives
 * validator prose changes. `instancePath` is canonicalized: numeric JSON
 * Pointer segments become `*`, so the same schema defect reported at
 * `/data/0/id` and `/data/3/id` shares one fingerprint regardless of test
 * data size or ordering. Non-body issues are distinguished by `parameter`
 * — the request parameter, response header, or security scheme name — so a
 * known `limit` violation does not absorb a future `page` violation on the
 * same operation. Parameter / response-header schema violations further
 * carry the failing `keyword` and the pointer into the named value, and
 * synthetic keywords cover checks that never reach a schema run (`required`
 * for missing parameters / headers / credentials, `format` for an unusable
 * credential), so two different violation kinds on one parameter or scheme
 * stay distinct. Issues that carry neither a name nor a keyword (structural
 * spec errors, error-boundary captures) still collapse per
 * `(operation, category[, name])`.
 *
 * The serialized shape ({@see toArray()}) is the versioned baseline-file
 * entry format documented in docs/versioning.md.
 *
 * @internal Implementation detail of the violation baseline; the committed
 *           baseline file format is the supported surface, not this class.
 *
 * @phpstan-type FingerprintEntry array{
 *     spec: string,
 *     method: string,
 *     path: string,
 *     status_code: string|null,
 *     content_type: string|null,
 *     category: string,
 *     parameter: string|null,
 *     instance_path: string|null,
 *     keyword: string|null,
 * }
 */
final readonly class ViolationFingerprint
{
    /**
     * Synthetic keyword marking an adapter body-decode failure (unparseable
     * JSON, unreadable/non-seekable stream). It keeps the failure distinct
     * from a genuinely empty body reported on the same operation — both
     * otherwise collapse to a body-category entry with no pointer context —
     * and marks same-category sibling issues as placeholder artifacts
     * ({@see decodeFailureCategories()}).
     */
    public const KEYWORD_PARSE = 'parse';

    public function __construct(
        public string $spec,
        public string $method,
        public string $path,
        public ?string $statusCode,
        public ?string $contentType,
        public string $category,
        public ?string $instancePath,
        public ?string $keyword,
        public ?string $parameter = null,
    ) {}

    /**
     * Build a fingerprint from one structured issue. The adapter's resolved
     * method and raw request path fill in when the issue carries no context
     * of its own (e.g. path-match failures never resolve a spec template).
     *
     * Fixed HTTP methods normalize to their canonical uppercase key; OpenAPI
     * 3.2 custom `additionalOperations` methods keep their exact spelling
     * because they are case-sensitive — a baselined `COPY` violation must
     * not absorb a new `copy` violation.
     */
    public static function fromIssue(
        string $specName,
        ValidationIssue $issue,
        string $fallbackMethod,
        string $fallbackPath,
    ): self {
        return new self(
            spec: $specName,
            method: OpenApiOperationResolver::normalizeMethodForKey($issue->method ?? $fallbackMethod),
            path: $issue->path ?? $fallbackPath,
            statusCode: $issue->statusCode,
            contentType: $issue->contentType,
            category: $issue->category,
            instancePath: $issue->instancePath === null
                ? null
                : self::canonicalizeInstancePath($issue->instancePath),
            keyword: $issue->keyword,
            parameter: $issue->parameter,
        );
    }

    /**
     * Fingerprint of a body-decode failure the Laravel / Symfony adapters
     * record before validation runs. It deliberately carries no matched
     * status / content-type / pointer context — the failure happens before
     * path matching — so enforcement can rebuild it from the raw request
     * context alone. The PSR-7 adapter instead folds the failure into the
     * result as a `parse`-keyword issue and goes through {@see fromIssue()}.
     */
    public static function forDecodeFailure(
        string $specName,
        string $method,
        string $path,
        string $category,
    ): self {
        return new self(
            spec: $specName,
            method: OpenApiOperationResolver::normalizeMethodForKey($method),
            path: $path,
            statusCode: null,
            contentType: null,
            category: $category,
            instancePath: null,
            keyword: self::KEYWORD_PARSE,
        );
    }

    /**
     * Categories whose non-`parse` issues are artifacts of an adapter
     * body-decode failure in the same result: the validator ran against a
     * placeholder (absent or raw-string) body, so its same-side body
     * verdicts describe the placeholder, not the real payload. Recording
     * them would let the baseline absorb a future genuine violation —
     * e.g. a truly empty body.
     *
     * @param list<ValidationIssue> $issues
     *
     * @return array<string, true>
     */
    public static function decodeFailureCategories(array $issues): array
    {
        $categories = [];
        foreach ($issues as $issue) {
            if ($issue->keyword === self::KEYWORD_PARSE) {
                $categories[$issue->category] = true;
            }
        }

        return $categories;
    }

    /**
     * Whether an issue explains a failure rather than being one. The
     * undeclared-Content-Type note (issue #435) rides along with the body
     * errors it accounts for, so fingerprinting it would make a baseline that
     * already covers those errors stop suppressing them the moment the note
     * appeared — a green suite turning red on an upgrade that changed no
     * verdict. Context issues still reach `issues()` and the JSON document.
     */
    public static function isContextOnly(ValidationIssue $issue): bool
    {
        return $issue->category === 'response.content_type';
    }

    /**
     * Replace purely numeric RFC 6901 segments with `*`. A property whose
     * name is itself a digit string collapses too — a documented trade-off
     * for baseline stability across test-data changes.
     */
    public static function canonicalizeInstancePath(string $instancePath): string
    {
        if ($instancePath === '') {
            return '';
        }

        $segments = explode('/', $instancePath);
        foreach ($segments as $index => $segment) {
            if ($index === 0) {
                continue;
            }
            if (preg_match('/^\d+$/', $segment) === 1) {
                $segments[$index] = '*';
            }
        }

        return implode('/', $segments);
    }

    /**
     * Binary-safe identity/sort key. Null fields are encoded distinctly from
     * empty strings (`instancePath === ''` is the document root, not
     * "absent"), and null sorts before any string value.
     */
    public function key(): string
    {
        $parts = [];
        foreach ([
            $this->spec,
            $this->method,
            $this->path,
            $this->statusCode,
            $this->contentType,
            $this->category,
            $this->parameter,
            $this->instancePath,
            $this->keyword,
        ] as $field) {
            $parts[] = $field === null ? "\x00" : "\x01" . $field;
        }

        return implode("\x1f", $parts);
    }

    /**
     * One-line human-readable rendering for stale-entry listings. Null
     * fields are omitted so the line stays compact; the empty-string
     * instance path (document root) renders as `""` to stay visible.
     */
    public function describe(): string
    {
        $line = sprintf('[%s] %s %s', $this->spec, $this->method, $this->path);
        if ($this->statusCode !== null) {
            $line .= ' status=' . $this->statusCode;
        }
        if ($this->contentType !== null) {
            $line .= ' content-type=' . $this->contentType;
        }
        $line .= ' ' . $this->category;
        if ($this->parameter !== null) {
            $line .= ' parameter=' . $this->parameter;
        }
        if ($this->instancePath !== null) {
            $line .= ' instance_path=' . ($this->instancePath === '' ? '""' : $this->instancePath);
        }
        if ($this->keyword !== null) {
            $line .= ' keyword=' . $this->keyword;
        }

        return $line;
    }

    /** @return FingerprintEntry */
    public function toArray(): array
    {
        return [
            'spec' => $this->spec,
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->statusCode,
            'content_type' => $this->contentType,
            'category' => $this->category,
            'parameter' => $this->parameter,
            'instance_path' => $this->instancePath,
            'keyword' => $this->keyword,
        ];
    }
}
