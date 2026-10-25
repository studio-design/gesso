<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const FILE_APPEND;
use const PHP_EOL;
use const STDERR;

use function fflush;
use function file_put_contents;
use function fwrite;

/**
 * Process-wide stderr and GitHub Step Summary sinks shared by the PHPUnit
 * adapter, the validators, and the merge CLI. Lives outside `PHPUnit\` so no
 * runtime path outside the adapter autoloads a PHPUnit-implementing class (#506).
 * Titles, intros and failure literals are the step-summary routing contract.
 *
 * @internal Implementation seam; not part of the public API.
 */
final class Diagnostics
{
    /** @var null|resource */
    private static $stderrOverride;

    private function __construct() {}

    /** @param null|resource $stream */
    public static function overrideStderrForTesting($stream): void
    {
        self::$stderrOverride = $stream;
    }

    public static function stderr(string $message): void
    {
        fwrite(self::$stderrOverride ?? STDERR, $message);
    }

    /** Flush the real STDERR before an `exit()`; a test override needs none. */
    public static function flushStderr(): void
    {
        if (self::$stderrOverride === null) {
            fflush(STDERR);
        }
    }

    public static function appendGithubStepSummaryStrictRequiredBlock(?string $path, string $body, bool $isFatal): void
    {
        self::appendGithubStepSummaryBlock(
            $path,
            $isFatal ? '## :rotating_light: FATAL OpenAPI strict_required drift' : '## :warning: OpenAPI strict_required drift',
            $isFatal
                ? 'strict_required detected schema under-description and the test run was aborted.'
                : 'strict_required detected schema under-description (warn-only).',
            $body,
            '[OpenAPI Strict Required] WARNING: Failed to append block',
        );
    }

    public static function appendGithubStepSummaryStrictAdditionalPropertiesBlock(?string $path, string $body, bool $isFatal): void
    {
        self::appendGithubStepSummaryBlock(
            $path,
            $isFatal
                ? '## :rotating_light: FATAL OpenAPI undocumented response properties'
                : '## :warning: OpenAPI undocumented response properties',
            $isFatal
                ? 'strict_additional_properties found undocumented response fields and the test run was aborted.'
                : 'strict_additional_properties found undocumented response fields (warn-only).',
            $body,
            '[OpenAPI Strict Additional Properties] WARNING: Failed to append block',
        );
    }

    public static function appendGithubStepSummaryBaselineStaleBlock(?string $path, string $body, bool $isFatal): void
    {
        self::appendGithubStepSummaryBlock(
            $path,
            $isFatal ? '## :rotating_light: FATAL OpenAPI baseline stale entries' : '## :warning: OpenAPI baseline stale entries',
            $isFatal
                ? 'Baseline entries no longer occurred and the test run was aborted (baseline_stale=fail). Remove them from the baseline file.'
                : 'Baseline entries no longer occurred and can be removed from the baseline file.',
            $body,
            '[Gesso] WARNING: Failed to append baseline block',
        );
    }

    public static function appendGithubStepSummaryEnumDriftBlock(?string $path, string $body, bool $isFatal): void
    {
        self::appendGithubStepSummaryBlock(
            $path,
            $isFatal ? '## :rotating_light: FATAL OpenAPI enum drift' : '## :warning: OpenAPI enum drift',
            $isFatal
                ? 'One or more `#[BoundToOpenApiEnum]` checks failed and the test run was aborted.'
                : 'One or more `#[BoundToOpenApiEnum]` checks reported drift.',
            $body,
            '[OpenAPI Enum Drift] WARNING: Failed to append block',
        );
    }

    public static function appendGithubStepSummaryFatalSpecBlock(?string $path, string $spec, string $reason): void
    {
        self::appendGithubStepSummaryBlock(
            $path,
            '## :rotating_light: FATAL OpenAPI spec error',
            "Spec `{$spec}` could not be loaded and the test run was aborted.",
            $reason,
            '[OpenAPI Coverage] WARNING: Failed to append FATAL block',
        );
    }

    /** Append one fenced block; a failed append is a WARNING on `$failureWarning`'s channel. */
    private static function appendGithubStepSummaryBlock(?string $path, string $title, string $intro, string $body, string $failureWarning): void
    {
        if ($path === null) {
            return;
        }

        $block = $title . PHP_EOL . PHP_EOL . $intro . PHP_EOL . PHP_EOL
            . '```' . PHP_EOL . $body . PHP_EOL . '```' . PHP_EOL . PHP_EOL;

        if (file_put_contents($path, $block, FILE_APPEND) === false) {
            self::stderr("{$failureWarning} to GITHUB_STEP_SUMMARY ({$path})\n");
        }
    }
}
