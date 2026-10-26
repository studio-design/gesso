<?php

declare(strict_types=1);

namespace Studio\Gesso\Config;

use Studio\Gesso\Internal\Deprecations;

/**
 * The v2 flat configuration spellings that `gesso.php` replaces — the
 * phpunit.xml parameters (#502, #501) and the top-level keys of Laravel's
 * `config/gesso.php` (#501) — and the one-shot notice each emits. A spelling
 * both surfaces read carries one surface-neutral notice.
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
        'spec_base_path',
        'default_spec',
        'specs',
        'strip_prefixes',
        'validation_output',
        'max_errors',
        'enforce_discriminator',
        'acknowledged_unvalidatable_schemes',
        'skip_response_codes',
        'skip_request_validation_response_codes',
        'sidecar_dir',
        'enum_drift_enabled',
        'enum_drift_scan_namespaces',
        'enum_drift_fail_on_drift',
        'default_testsuite_as_full',
    ];

    /**
     * The deprecated top-level keys of Laravel's `config/gesso.php`, read by
     * `GessoServiceProvider` before the package defaults are merged in.
     */
    public const LARAVEL = [
        'spec_base_path',
        'default_spec',
        'specs',
        'strip_prefixes',
        'max_errors',
        'enforce_discriminator',
        'acknowledged_unvalidatable_schemes',
        'skip_response_codes',
        'skip_request_validation_response_codes',
        'auto_assert',
        'auto_validate_request',
        'auto_inject_dummy_credentials',
        'route_parity',
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
            case 'spec_base_path':
                Deprecations::notice(
                    id: 'config.spec_base_path',
                    subject: 'The config key \'spec_base_path\'',
                    replacement: 'spec.base_path (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'default_spec':
                Deprecations::notice(
                    id: 'config.default_spec',
                    subject: 'The config key \'default_spec\'',
                    replacement: 'spec.default (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'specs':
                Deprecations::notice(
                    id: 'config.specs',
                    subject: 'The config key \'specs\'',
                    replacement: 'spec.names (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'strip_prefixes':
                Deprecations::notice(
                    id: 'config.strip_prefixes',
                    subject: 'The config key \'strip_prefixes\'',
                    replacement: 'spec.strip_prefixes (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'validation_output':
                Deprecations::notice(
                    id: 'phpunit.validation_output',
                    subject: 'The PHPUnit config key \'validation_output\'',
                    replacement: 'validation.format (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'max_errors':
                Deprecations::notice(
                    id: 'config.max_errors',
                    subject: 'The config key \'max_errors\'',
                    replacement: 'validation.max_errors (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'enforce_discriminator':
                Deprecations::notice(
                    id: 'config.enforce_discriminator',
                    subject: 'The config key \'enforce_discriminator\'',
                    replacement: 'validation.enforce_discriminator (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'acknowledged_unvalidatable_schemes':
                Deprecations::notice(
                    id: 'config.acknowledged_unvalidatable_schemes',
                    subject: 'The config key \'acknowledged_unvalidatable_schemes\'',
                    replacement: 'validation.acknowledged_unvalidatable_schemes (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'skip_response_codes':
                Deprecations::notice(
                    id: 'config.skip_response_codes',
                    subject: 'The config key \'skip_response_codes\'',
                    replacement: 'validation.skip_response_codes (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'skip_request_validation_response_codes':
                Deprecations::notice(
                    id: 'config.skip_request_validation_response_codes',
                    subject: 'The config key \'skip_request_validation_response_codes\'',
                    replacement: 'validation.skip_request_validation_response_codes (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'sidecar_dir':
                Deprecations::notice(
                    id: 'phpunit.sidecar_dir',
                    subject: 'The PHPUnit config key \'sidecar_dir\'',
                    replacement: 'coverage.sidecar_dir (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'enum_drift_enabled':
                Deprecations::notice(
                    id: 'phpunit.enum_drift_enabled',
                    subject: 'The PHPUnit config key \'enum_drift_enabled\'',
                    replacement: 'enum_drift.enabled (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'enum_drift_scan_namespaces':
                Deprecations::notice(
                    id: 'phpunit.enum_drift_scan_namespaces',
                    subject: 'The PHPUnit config key \'enum_drift_scan_namespaces\'',
                    replacement: 'enum_drift.scan_namespaces (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'enum_drift_fail_on_drift':
                Deprecations::notice(
                    id: 'phpunit.enum_drift_fail_on_drift',
                    subject: 'The PHPUnit config key \'enum_drift_fail_on_drift\'',
                    replacement: 'enum_drift.fail_on_drift (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'default_testsuite_as_full':
                Deprecations::notice(
                    id: 'phpunit.default_testsuite_as_full',
                    subject: 'The PHPUnit config key \'default_testsuite_as_full\'',
                    replacement: 'phpunit.default_testsuite_as_full (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'auto_assert':
                Deprecations::notice(
                    id: 'laravel.config.auto_assert',
                    subject: 'The Laravel config key \'auto_assert\'',
                    replacement: 'laravel.auto_assert (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'auto_validate_request':
                Deprecations::notice(
                    id: 'laravel.config.auto_validate_request',
                    subject: 'The Laravel config key \'auto_validate_request\'',
                    replacement: 'laravel.auto_validate_request (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'auto_inject_dummy_credentials':
                Deprecations::notice(
                    id: 'laravel.config.auto_inject_dummy_credentials',
                    subject: 'The Laravel config key \'auto_inject_dummy_credentials\'',
                    replacement: 'laravel.auto_inject_dummy_credentials (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
            case 'route_parity':
                Deprecations::notice(
                    id: 'laravel.config.route_parity',
                    subject: 'The Laravel config key \'route_parity\'',
                    replacement: 'laravel.route_parity (in gesso.php)',
                    removedIn: '3.0',
                );

                return;
        }
    }
}
