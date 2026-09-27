<?php

declare(strict_types=1);

namespace Studio\Gesso\Config;

/**
 * Maps declared shared settings to the existing v2 adapter inputs. Defaults
 * stay with each consumer until the major-version migration is complete.
 *
 * @internal Compatibility plumbing, not a configuration API.
 */
final class ConfigurationBridge
{
    private const COMMON = [
        'spec_base_path' => ['spec.base_path', 'string'],
        'default_spec' => ['spec.default', 'string'],
        'strip_prefixes' => ['spec.strip_prefixes', 'strings'],
        'max_errors' => ['validation.max_errors', 'int'],
        'enforce_discriminator' => ['validation.enforce_discriminator', 'bool'],
        'acknowledged_unvalidatable_schemes' => ['validation.acknowledged_unvalidatable_schemes', 'strings'],
        'skip_response_codes' => ['validation.skip_response_codes', 'strings'],
        'skip_request_validation_response_codes' => ['validation.skip_request_validation_response_codes', 'strings'],
    ];

    /** @return array<string, bool|float|int|list<string>|string> */
    public static function phpunit(GessoConfig $config): array
    {
        return self::map($config, self::COMMON + [
            'specs' => ['spec.names', 'strings'],
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
        ]);
    }

    /** @return array<string, bool|float|int|list<string>|string> */
    public static function laravel(GessoConfig $config): array
    {
        return self::map($config, self::COMMON + [
            'auto_assert' => ['laravel.auto_assert', 'bool'],
            'auto_validate_request' => ['laravel.auto_validate_request', 'bool'],
            'auto_inject_dummy_credentials' => ['laravel.auto_inject_dummy_credentials', 'boolOrString'],
            'route_parity.external_operation_ids' => ['laravel.route_parity.external_operation_ids', 'strings'],
            'route_parity.external_openapi_paths' => ['laravel.route_parity.external_openapi_paths', 'strings'],
        ]);
    }

    /**
     * @param array<string, array{string, string}> $mapping
     *
     * @return array<string, bool|float|int|list<string>|string>
     */
    private static function map(GessoConfig $config, array $mapping): array
    {
        $values = [];
        foreach ($mapping as $name => [$key, $accessor]) {
            if (!$config->has($key)) {
                continue;
            }
            $value = match ($accessor) {
                'bool' => $config->bool($key),
                'int' => $config->int($key),
                'number' => $config->number($key),
                'strings' => $config->strings($key),
                'boolOrString' => $config->boolOrString($key),
                default => $config->string($key),
            };
            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
