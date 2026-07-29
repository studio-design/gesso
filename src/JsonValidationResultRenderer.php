<?php

declare(strict_types=1);

namespace Studio\Gesso;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Composer\InstalledVersions;
use RuntimeException;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Throwable;

use function json_encode;
use function json_last_error_msg;
use function sprintf;

/**
 * Render an {@see OpenApiValidationResult} as a versioned JSON document for
 * machine consumers (CI ingestion, IDE annotations, AI-assisted remediation)
 * that need more than the flat assertion text (issue #282).
 *
 * Top-level shape (see `docs/validation-json-schema.md` for the full field
 * reference — the document is a documented compatibility surface):
 *  - `schema_version`: int, bumped on incompatible structural changes
 *  - `tool`: `{ name, version }` for downstream consumers diagnosing drift
 *  - `outcome`: `success` | `failure` | `skipped`
 *  - `matched`: `{ path, status_code, content_type }` operation context
 *  - `skip_reason`: string reason for skipped outcomes, else null
 *  - `reproduce_command`: caller-supplied reproduction command, else null
 *  - `issues`: one entry per {@see ValidationIssue}, snake_case fields
 *
 * The document is deliberately timestamp-free so per-assertion output stays
 * deterministic for snapshot tests and CI diffing. `reproduce_command` is
 * embedded verbatim — callers are responsible for redacting secrets before
 * passing it in (the built-in curl formatter redacts by default, issue #404).
 */
final class JsonValidationResultRenderer
{
    public const SCHEMA_VERSION = 1;
    private const COMPOSER_PACKAGE_NAME = 'studio-design/gesso';
    private const TOOL_NAME = 'studio-design/gesso';

    /**
     * @return string A pretty-printed JSON document terminated by a single
     *                `"\n"`. Error messages may embed request/response
     *                fragments, so invalid UTF-8 byte sequences are replaced
     *                with U+FFFD instead of aborting the render — a diagnostic
     *                document that loses one byte beats an exception that
     *                masks the original validation failure.
     *
     * @throws RuntimeException when the payload cannot be encoded as JSON
     */
    public static function render(OpenApiValidationResult $result, ?string $reproduceCommand = null): string
    {
        $issues = [];
        foreach ($result->issues() as $issue) {
            $issues[] = [
                'category' => $issue->category,
                'message' => $issue->message,
                'instance_path' => $issue->instancePath,
                'keyword' => $issue->keyword,
                'parameter' => $issue->parameter,
                'method' => $issue->method,
                'path' => $issue->path,
                'status_code' => $issue->statusCode,
                'content_type' => $issue->contentType,
            ];
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'tool' => [
                'name' => self::TOOL_NAME,
                'version' => self::resolveToolVersion(),
            ],
            'outcome' => $result->outcome()->value,
            'matched' => [
                'path' => $result->matchedPath(),
                'status_code' => $result->matchedStatusCode(),
                'content_type' => $result->matchedContentType(),
            ],
            'skip_reason' => $result->skipReason(),
            'reproduce_command' => $reproduceCommand,
            'issues' => $issues,
        ];

        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($encoded === false) {
            // Practically unreachable (strings are UTF-8-substituted, no
            // resources, no NAN) but surface a clear error instead of an
            // empty document if the payload shape changes unexpectedly.
            throw new RuntimeException(sprintf(
                'Failed to encode the validation result as JSON: %s',
                json_last_error_msg(),
            ));
        }

        return $encoded . "\n";
    }

    /**
     * Resolve the running tool version. The field is cosmetic — any failure
     * here must never abort the document, so the catch is intentionally broad
     * (mirrors {@see JsonCoverageRenderer}): corrupted
     * Composer metadata or stripped vendor directories surface as `'unknown'`
     * rather than an exception.
     */
    private static function resolveToolVersion(): string
    {
        try {
            $version = InstalledVersions::getVersion(self::COMPOSER_PACKAGE_NAME);
        } catch (Throwable) {
            return 'unknown';
        }

        return $version ?? 'unknown';
    }
}
