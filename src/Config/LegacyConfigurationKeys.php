<?php

declare(strict_types=1);

namespace Studio\Gesso\Config;

use Studio\Gesso\Internal\Deprecations;

/**
 * The v2 phpunit.xml parameter spellings that `gesso.php` collapses (#502),
 * and the one-shot notice each emits.
 *
 * @internal Deprecation table for the v2 configuration spellings; one literal
 *           `Deprecations::notice()` per spelling because
 *           `DeprecationRegistryTest` reads the arguments off the call site.
 */
final class LegacyConfigurationKeys
{
    /** The deprecated phpunit.xml parameter names this table knows. */
    public const PHPUNIT = [
        'strict_required',
        'strict_required_per_call',
        'strict_additional_properties',
        'strict_additional_properties_per_call',
        'min_endpoint_coverage',
        'min_response_coverage',
        'min_sdk_exercise_coverage',
        'min_coverage_strict',
        'output_file',
        'json_output',
        'junit_output',
        'html_output',
        'console_output',
        'baseline_file',
        'coverage_baseline_file',
        'baseline_stale',
        'coverage_baseline_stale',
    ];

    /** Static-only table — no instances. */
    private function __construct() {}

    /** Emit the one-shot notice for a legacy spelling the user set. Unknown names are a no-op. */
    public static function deprecate(string $spelling): void
    {
        switch ($spelling) {
            case 'strict_required':
                Deprecations::notice(
                    id: 'phpunit.strict_required',
                    subject: 'The PHPUnit config key \'strict_required\'',
                    replacement: 'strict.required[\'run\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'strict_required_per_call':
                Deprecations::notice(
                    id: 'phpunit.strict_required_per_call',
                    subject: 'The PHPUnit config key \'strict_required_per_call\'',
                    replacement: 'strict.required[\'per_call\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'strict_additional_properties':
                Deprecations::notice(
                    id: 'phpunit.strict_additional_properties',
                    subject: 'The PHPUnit config key \'strict_additional_properties\'',
                    replacement: 'strict.additional_properties[\'run\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'strict_additional_properties_per_call':
                Deprecations::notice(
                    id: 'phpunit.strict_additional_properties_per_call',
                    subject: 'The PHPUnit config key \'strict_additional_properties_per_call\'',
                    replacement: 'strict.additional_properties[\'per_call\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'min_endpoint_coverage':
                Deprecations::notice(
                    id: 'phpunit.min_endpoint_coverage',
                    subject: 'The PHPUnit config key \'min_endpoint_coverage\'',
                    replacement: 'coverage.min_coverage[\'endpoint\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'min_response_coverage':
                Deprecations::notice(
                    id: 'phpunit.min_response_coverage',
                    subject: 'The PHPUnit config key \'min_response_coverage\'',
                    replacement: 'coverage.min_coverage[\'response\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'min_sdk_exercise_coverage':
                Deprecations::notice(
                    id: 'phpunit.min_sdk_exercise_coverage',
                    subject: 'The PHPUnit config key \'min_sdk_exercise_coverage\'',
                    replacement: 'coverage.min_coverage[\'sdk_exercise\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'min_coverage_strict':
                Deprecations::notice(
                    id: 'phpunit.min_coverage_strict',
                    subject: 'The PHPUnit config key \'min_coverage_strict\'',
                    replacement: 'coverage.min_coverage[\'strict\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'output_file':
                Deprecations::notice(
                    id: 'phpunit.output_file',
                    subject: 'The PHPUnit config key \'output_file\'',
                    replacement: 'coverage.report_output[\'markdown\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'json_output':
                Deprecations::notice(
                    id: 'phpunit.json_output',
                    subject: 'The PHPUnit config key \'json_output\'',
                    replacement: 'coverage.report_output[\'json\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'junit_output':
                Deprecations::notice(
                    id: 'phpunit.junit_output',
                    subject: 'The PHPUnit config key \'junit_output\'',
                    replacement: 'coverage.report_output[\'junit\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'html_output':
                Deprecations::notice(
                    id: 'phpunit.html_output',
                    subject: 'The PHPUnit config key \'html_output\'',
                    replacement: 'coverage.report_output[\'html\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'console_output':
                Deprecations::notice(
                    id: 'phpunit.console_output',
                    subject: 'The PHPUnit config key \'console_output\'',
                    replacement: 'coverage.console_report (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'baseline_file':
                Deprecations::notice(
                    id: 'phpunit.baseline_file',
                    subject: 'The PHPUnit config key \'baseline_file\'',
                    replacement: 'baseline[\'violations\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'coverage_baseline_file':
                Deprecations::notice(
                    id: 'phpunit.coverage_baseline_file',
                    subject: 'The PHPUnit config key \'coverage_baseline_file\'',
                    replacement: 'baseline[\'coverage\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'baseline_stale':
                Deprecations::notice(
                    id: 'phpunit.baseline_stale',
                    subject: 'The PHPUnit config key \'baseline_stale\'',
                    replacement: 'baseline_stale[\'violations\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'coverage_baseline_stale':
                Deprecations::notice(
                    id: 'phpunit.coverage_baseline_stale',
                    subject: 'The PHPUnit config key \'coverage_baseline_stale\'',
                    replacement: 'baseline_stale[\'coverage\'] (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
        }
    }
}
