<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use Studio\Gesso\ValidationIssue;

use function explode;
use function implode;
use function preg_match;
use function strtoupper;

/**
 * Stable identity of one contract violation for the violation baseline
 * (issue #402).
 *
 * The tuple deliberately excludes the human-readable message — wording is
 * not a compatibility surface (docs/versioning.md) — so a baseline survives
 * validator prose changes. `instancePath` is canonicalized: numeric JSON
 * Pointer segments become `*`, so the same schema defect reported at
 * `/data/0/id` and `/data/3/id` shares one fingerprint regardless of test
 * data size or ordering. Consequence: non-body issues (null
 * `instancePath`/`keyword`) collapse per `(operation, category)`.
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
 *     instance_path: string|null,
 *     keyword: string|null,
 * }
 */
final readonly class ViolationFingerprint
{
    public function __construct(
        public string $spec,
        public string $method,
        public string $path,
        public ?string $statusCode,
        public ?string $contentType,
        public string $category,
        public ?string $instancePath,
        public ?string $keyword,
    ) {}

    /**
     * Build a fingerprint from one structured issue. The adapter's resolved
     * method and raw request path fill in when the issue carries no context
     * of its own (e.g. path-match failures never resolve a spec template).
     */
    public static function fromIssue(
        string $specName,
        ValidationIssue $issue,
        string $fallbackMethod,
        string $fallbackPath,
    ): self {
        return new self(
            spec: $specName,
            method: strtoupper($issue->method ?? $fallbackMethod),
            path: $issue->path ?? $fallbackPath,
            statusCode: $issue->statusCode,
            contentType: $issue->contentType,
            category: $issue->category,
            instancePath: $issue->instancePath === null
                ? null
                : self::canonicalizeInstancePath($issue->instancePath),
            keyword: $issue->keyword,
        );
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
            $this->instancePath,
            $this->keyword,
        ] as $field) {
            $parts[] = $field === null ? "\x00" : "\x01" . $field;
        }

        return implode("\x1f", $parts);
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
            'instance_path' => $this->instancePath,
            'keyword' => $this->keyword,
        ];
    }
}
