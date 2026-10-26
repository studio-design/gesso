<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const E_USER_DEPRECATED;

use function restore_error_handler;
use function set_error_handler;

/**
 * The v2 `gesso` CLI flag spellings that v3 renames (#507, ADR 0005), and the
 * one-shot notice each emits.
 *
 * @internal Deprecation table for the v2 CLI spellings; one literal
 *           `Deprecations::notice()` per spelling because
 *           `DeprecationRegistryTest` reads the arguments off the call site.
 */
final class LegacyCliFlags
{
    /** Static-only table — no instances. */
    private function __construct() {}

    /**
     * Emit the notice for each legacy flag the user typed, with the
     * `E_USER_DEPRECATED` line written through `$stderr`: a CLI process has no
     * PHPUnit to collect it, and PHP's default handler prints it on stdout,
     * into a `--format=json` report.
     *
     * @param list<string> $flags spellings as typed, e.g. `--specs`
     * @param callable(string): void $stderr
     */
    public static function deprecate(array $flags, callable $stderr): void
    {
        if ($flags === []) {
            return;
        }

        set_error_handler(static function (int $severity, string $message) use ($stderr): bool {
            $stderr($message . "\n");

            return true;
        }, E_USER_DEPRECATED);

        try {
            foreach ($flags as $flag) {
                self::notice($flag);
            }
        } finally {
            restore_error_handler();
        }
    }

    private static function notice(string $flag): void
    {
        switch ($flag) {
            case '--specs':
                Deprecations::notice(
                    id: 'cli.coverage_merge.specs',
                    subject: 'The CLI flag \'--specs\'',
                    replacement: '--spec-name (repeatable, comma-accepting)',
                    removedIn: '3.0',
                );

                return;
            case '--strip-prefixes':
                Deprecations::notice(
                    id: 'cli.coverage_merge.strip_prefixes',
                    subject: 'The CLI flag \'--strip-prefixes\'',
                    replacement: '--strip-prefix (repeatable, comma-accepting)',
                    removedIn: '3.0',
                );

                return;
            case '--json-output':
                Deprecations::notice(
                    id: 'cli.coverage_merge.json_output',
                    subject: 'The CLI flag \'--json-output\'',
                    replacement: '--report=json:<path>',
                    removedIn: '3.0',
                );

                return;
            case '--junit-output':
                Deprecations::notice(
                    id: 'cli.coverage_merge.junit_output',
                    subject: 'The CLI flag \'--junit-output\'',
                    replacement: '--report=junit:<path>',
                    removedIn: '3.0',
                );

                return;
            case '--html-output':
                Deprecations::notice(
                    id: 'cli.coverage_merge.html_output',
                    subject: 'The CLI flag \'--html-output\'',
                    replacement: '--report=html:<path>',
                    removedIn: '3.0',
                );

                return;
            case '--console-output':
                Deprecations::notice(
                    id: 'cli.coverage_merge.console_output',
                    subject: 'The CLI flag \'--console-output\'',
                    replacement: '--console-report',
                    removedIn: '3.0',
                );

                return;
            case '--min-endpoint-coverage':
                Deprecations::notice(
                    id: 'cli.coverage_merge.min_endpoint_coverage',
                    subject: 'The CLI flag \'--min-endpoint-coverage\'',
                    replacement: '--min-coverage="endpoint=…"',
                    removedIn: '3.0',
                );

                return;
            case '--min-response-coverage':
                Deprecations::notice(
                    id: 'cli.coverage_merge.min_response_coverage',
                    subject: 'The CLI flag \'--min-response-coverage\'',
                    replacement: '--min-coverage="response=…"',
                    removedIn: '3.0',
                );

                return;
            case '--min-sdk-exercise-coverage':
                Deprecations::notice(
                    id: 'cli.coverage_merge.min_sdk_exercise_coverage',
                    subject: 'The CLI flag \'--min-sdk-exercise-coverage\'',
                    replacement: '--min-coverage="sdk_exercise=…"',
                    removedIn: '3.0',
                );

                return;
            case '--min-coverage-strict':
                Deprecations::notice(
                    id: 'cli.coverage_merge.min_coverage_strict',
                    subject: 'The CLI flag \'--min-coverage-strict\'',
                    replacement: '--min-coverage="strict"',
                    removedIn: '3.0',
                );

                return;
            case '--baseline-file':
                Deprecations::notice(
                    id: 'cli.coverage_merge.baseline_file',
                    subject: 'The CLI flag \'--baseline-file\'',
                    replacement: '--baseline="violations=…"',
                    removedIn: '3.0',
                );

                return;
            case '--coverage-baseline-file':
                Deprecations::notice(
                    id: 'cli.coverage_merge.coverage_baseline_file',
                    subject: 'The CLI flag \'--coverage-baseline-file\'',
                    replacement: '--baseline="coverage=…"',
                    removedIn: '3.0',
                );

                return;
            case '--coverage-baseline-stale':
                Deprecations::notice(
                    id: 'cli.coverage_merge.coverage_baseline_stale',
                    subject: 'The CLI flag \'--coverage-baseline-stale\'',
                    replacement: '--baseline-stale="coverage=…"',
                    removedIn: '3.0',
                );

                return;
            case '--strict-required':
                Deprecations::notice(
                    id: 'cli.coverage_merge.strict_required',
                    subject: 'The CLI flag \'--strict-required\'',
                    replacement: '--strict-required="run=…,per_call=…" (the bare-mode value is what is deprecated)',
                    removedIn: '3.0',
                );

                return;
            case '--strict-additional-properties':
                Deprecations::notice(
                    id: 'cli.coverage_merge.strict_additional_properties',
                    subject: 'The CLI flag \'--strict-additional-properties\'',
                    replacement: '--strict-additional-properties="run=…,per_call=…" (the bare-mode value is what is deprecated)',
                    removedIn: '3.0',
                );

                return;
            case '--output':
                Deprecations::notice(
                    id: 'cli.stubs.output',
                    subject: 'The CLI flag \'--output\'',
                    replacement: '--output-dir (on gesso stubs and gesso:stubs)',
                    removedIn: '3.0',
                );

                return;
        }
    }
}
