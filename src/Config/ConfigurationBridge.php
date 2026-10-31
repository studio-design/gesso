<?php

declare(strict_types=1);

namespace Studio\Gesso\Config;

use Studio\Gesso\PHPUnit\ExtensionParameters;

/**
 * Maps declared `gesso.php` settings to the string-named inputs the PHPUnit
 * extension, `coverage:merge` and `doctor` parse. Defaults stay with each
 * consumer.
 *
 * @internal Compatibility plumbing, not a configuration API.
 */
final class ConfigurationBridge
{
    /**
     * Internal setting name => [`gesso.php` key, accessor]. The names are the
     * v2 phpunit.xml parameter spellings, which v3 removed as an input
     * ({@see ExtensionParameters} rejects them) but
     * keeps as the internal vocabulary of the extension and the CLI defaults.
     */
    public const PHPUNIT = [
        'spec_base_path' => ['spec.base_path', 'string'],
        'default_spec' => ['spec.default', 'string'],
        'specs' => ['spec.names', 'strings'],
        'strip_prefixes' => ['spec.strip_prefixes', 'strings'],
        'max_errors' => ['validation.max_errors', 'int'],
        'enforce_discriminator' => ['validation.enforce_discriminator', 'bool'],
        'acknowledged_unvalidatable_schemes' => ['validation.acknowledged_unvalidatable_schemes', 'strings'],
        'skip_response_codes' => ['validation.skip_response_codes', 'strings'],
        'skip_request_validation_response_codes' => ['validation.skip_request_validation_response_codes', 'strings'],
        'validation_output' => ['validation.format', 'string'],
        'strict_required' => ['strict.required.run', 'string'],
        'strict_required_per_call' => ['strict.required.per_call', 'string'],
        'strict_additional_properties' => ['strict.additional_properties.run', 'string'],
        'strict_additional_properties_per_call' => ['strict.additional_properties.per_call', 'string'],
        'min_endpoint_coverage' => ['coverage.min_coverage.endpoint', 'number'],
        'min_response_coverage' => ['coverage.min_coverage.response', 'number'],
        'min_sdk_exercise_coverage' => ['coverage.min_coverage.sdk_exercise', 'number'],
        'min_coverage_strict' => ['coverage.min_coverage.strict', 'bool'],
        'output_file' => ['coverage.report_output.markdown', 'string'],
        'json_output' => ['coverage.report_output.json', 'string'],
        'junit_output' => ['coverage.report_output.junit', 'string'],
        'html_output' => ['coverage.report_output.html', 'string'],
        'console_output' => ['coverage.console_report', 'string'],
        'sidecar_dir' => ['coverage.sidecar_dir', 'string'],
        'baseline_file' => ['baseline.violations', 'string'],
        'coverage_baseline_file' => ['baseline.coverage', 'string'],
        'baseline_stale' => ['baseline_stale.violations', 'string'],
        'coverage_baseline_stale' => ['baseline_stale.coverage', 'string'],
        'enum_drift_enabled' => ['enum_drift.enabled', 'bool'],
        'enum_drift_scan_namespaces' => ['enum_drift.scan_namespaces', 'strings'],
        'enum_drift_fail_on_drift' => ['enum_drift.fail_on_drift', 'bool'],
        'default_testsuite_as_full' => ['phpunit.default_testsuite_as_full', 'bool'],
    ];

    /**
     * The declared `gesso.php` settings, by their {@see self::PHPUNIT} name.
     *
     * @return array<string, bool|float|int|list<string>|string>
     */
    public static function phpunit(GessoConfig $config): array
    {
        $values = [];
        foreach (self::PHPUNIT as $name => [$key, $accessor]) {
            if (!$config->has($key)) {
                continue;
            }
            $value = match ($accessor) {
                'bool' => $config->bool($key),
                'int' => $config->int($key),
                'number' => $config->number($key),
                'strings' => $config->strings($key),
                default => $config->string($key),
            };
            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
