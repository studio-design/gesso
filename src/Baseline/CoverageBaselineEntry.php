<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiOperationResolver;

use function implode;
use function sprintf;

/**
 * Stable identity of one response the suite does not cover, for the coverage
 * baseline (issue #481).
 *
 * The tuple is exactly the granularity coverage is measured at —
 * `(spec, method, path template, status key, content type)` — so an entry
 * names a row of the coverage report rather than a percentage. `status` is
 * the spec's response key (`200`, `5XX`, `default`), not an observed HTTP
 * status, and `contentType` is `*` for responses declared without a
 * `content` block, matching {@see OpenApiCoverageTracker::ANY_CONTENT_TYPE}.
 *
 * Fixed HTTP methods normalize to their canonical uppercase key; OpenAPI 3.2
 * custom `additionalOperations` methods keep their exact spelling because
 * they are case-sensitive, mirroring {@see ViolationFingerprint}.
 *
 * @internal Implementation detail of the coverage baseline; the committed
 *           baseline file format is the supported surface, not this class.
 *
 * @phpstan-type CoverageBaselineEntryArray array{
 *     spec: string,
 *     method: string,
 *     path: string,
 *     status: string,
 *     content_type: string,
 * }
 */
final readonly class CoverageBaselineEntry
{
    public function __construct(
        public string $spec,
        public string $method,
        public string $path,
        public string $status,
        public string $contentType,
    ) {}

    /**
     * Build an entry from one coverage-report row, normalizing the method so
     * a hand-edited `get` still matches the runtime `GET`.
     */
    public static function create(
        string $spec,
        string $method,
        string $path,
        string $status,
        string $contentType,
    ): self {
        return new self(
            spec: $spec,
            method: OpenApiOperationResolver::normalizeMethodForKey($method),
            path: $path,
            status: $status,
            contentType: $contentType,
        );
    }

    /**
     * Binary-safe identity/sort key. Every field is a non-empty string here
     * (the tracker uses the `*` sentinel rather than null for "no content
     * type"), so a plain separator join is unambiguous.
     */
    public function key(): string
    {
        return implode("\x1f", [$this->spec, $this->method, $this->path, $this->status, $this->contentType]);
    }

    /** One-line human-readable rendering for gate listings. */
    public function describe(): string
    {
        return sprintf(
            '[%s] %s %s status=%s content-type=%s',
            $this->spec,
            $this->method,
            $this->path,
            $this->status,
            $this->contentType,
        );
    }

    /** @return CoverageBaselineEntryArray */
    public function toArray(): array
    {
        return [
            'spec' => $this->spec,
            'method' => $this->method,
            'path' => $this->path,
            'status' => $this->status,
            'content_type' => $this->contentType,
        ];
    }
}
