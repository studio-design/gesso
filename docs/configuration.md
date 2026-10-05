# Shared PHPUnit and Laravel configuration

> Available on `main`; not included in the published 2.6.0 release.

Put shared settings in `gesso.php` at the application root. The PHPUnit
extension and Laravel service provider read the same file, so spec paths,
request prefixes, and validation policy need only be written once.
The runnable [Scramble example](recipes/scramble.md#run-the-example) exercises
this setup with real HTTP tests and Artisan commands.

## Minimal setup

```php
<?php

declare(strict_types=1);

return [
    'spec' => [
        'base_path' => 'openapi',
        'default' => 'api',
        'names' => ['api'],
        'strip_prefixes' => ['/api'],
    ],
    'coverage' => ['report_output' => ['json' => 'build/coverage.json']],
];
```

Register the extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="config" value="gesso.php"/>
    </bootstrap>
</extensions>
```

Laravel discovers its application-root `gesso.php` automatically. Keep the
usual `ValidatesOpenApiSchema` trait and assertions in your tests. You do not
need to publish `config/gesso.php` for this setup.

`spec.names` lists the specs PHPUnit must load and include in coverage;
`spec.default` selects the fallback for assertions. Set both as above for a
single-spec project. `spec.strip_prefixes` controls matching, independently
of the `--request-prefix` option used to generate test requests.

## Discovery and relative paths

- PHPUnit resolves the `config` parameter relative to the selected PHPUnit XML
  file. Without the parameter, it looks for `gesso.php` beside that XML file.
  If no XML file is selected, it uses the working directory.
- Laravel looks in `Application::basePath()`, independently of the working
  directory. A custom PHPUnit config path should point to that same root file
  when sharing settings with Laravel.
- Paths **inside** `gesso.php` resolve against that file's directory: spec base
  path, report outputs, sidecar directory, and baseline files.
- An explicitly selected missing file, unknown key, invalid value, or PHP
  evaluation error aborts PHPUnit with exit 1 even without `failOnWarning`.
  An absent conventional file preserves existing behavior.

For example, `cd tests && ../vendor/bin/phpunit -c ../phpunit.xml` reads the
same specs and writes reports to the same paths as running from the root.
Use plain PHP values and `__DIR__` if needed; Laravel-only helpers such as
`base_path()` and `env()` may be unavailable when PHPUnit reads the file.

## Compatibility and precedence

This is an additive v2 bridge. Existing XML parameters and Laravel config keys
continue to work. Only keys explicitly declared in `gesso.php` contribute
values; omitted or nullable settings left at `null` retain existing consumer
defaults. In particular, an omitted `spec.names` retains PHPUnit's `front`
default; an omitted `spec.base_path` retains the programmatic loader setup.
In that case, an explicit `spec.strip_prefixes` still replaces the loader's
prefixes without changing its base path, enum path, remote-reference settings,
or cached specs. An empty list clears the prefixes; omitting the setting
preserves the prefixes configured in bootstrap.

- PHPUnit: existing format/console environment overrides, then explicit XML
  parameters, then declared shared settings, then existing defaults.
- Laravel: existing `config('gesso.…')` values, then declared shared settings,
  then package defaults. Arrays are replaced as whole values, not concatenated.
- Explicit per-test and per-call overrides keep their existing priority.

To migrate, copy values into the root file, remove their matching XML
parameters, and remove the copied keys from `config/gesso.php`. Once all values
have moved, delete `config/gesso.php` and clear any Laravel configuration cache.
Leaving copies behind means each adapter's old values still win, which can
keep the two adapters inconsistent. `vendor:publish --tag=gesso` still publishes
the existing v2 file; avoid republishing it after migration.

Laravel's `config:cache` captures the resolved shared settings in its normal
snapshot. While cached, the provider does not evaluate `gesso.php` again.
Run `php artisan config:clear` after changing shared settings before testing;
PHPUnit itself always reads the source file. This follows Laravel's
[configuration cache lifecycle](https://laravel.com/docs/12.x/configuration#configuration-caching).

Booleans in the shared file accept PHP booleans and the strings `true/false`,
`1/0`, `yes/no`, `on/off`, or an empty string (false). Invalid strings fail.
Lists stay arrays, including patterns containing commas:

```php
'validation' => [
    'max_errors' => 10,
    'enforce_discriminator' => 'off',
    'skip_response_codes' => ['5[0-9]{2,2}'],
    'skip_request_validation_response_codes' => [],
],
```

For dummy request credentials, use `laravel.auto_inject_dummy_credentials`:
`false` disables injection, `true` fills missing Bearer and API-key credentials,
and `'bearer'` fills only missing HTTP Bearer credentials. All modes require
`laravel.auto_validate_request => true`. Injection changes only the validator's
view, never the dispatched request, and never replaces a populated credential.
A missing API key still fails in `'bearer'` mode, including operations that
require both Bearer and an API key. The named mode does not use the deprecated
`auto_inject_dummy_bearer` setting or emit its deprecation.

## Settings connected in this release

| Shared setting | PHPUnit parameter | Laravel config key (`gesso.` prefix) |
| --- | --- | --- |
| `spec.base_path` | `spec_base_path` | `spec_base_path` |
| `spec.default` | `default_spec` | `default_spec` |
| `spec.names` | `specs` | — |
| `spec.strip_prefixes` | `strip_prefixes` | `strip_prefixes` |
| `validation.max_errors` | `max_errors` | `max_errors` |
| `validation.enforce_discriminator` | `enforce_discriminator` | `enforce_discriminator` |
| `validation.acknowledged_unvalidatable_schemes` | `acknowledged_unvalidatable_schemes` | `acknowledged_unvalidatable_schemes` |
| `validation.skip_response_codes` | `skip_response_codes` | `skip_response_codes` |
| `validation.skip_request_validation_response_codes` | `skip_request_validation_response_codes` | `skip_request_validation_response_codes` |
| `validation.format` | `validation_output` | — (run-wide PHPUnit selection) |
| `strict.required.run` / `strict.required.per_call` | `strict_required` / `strict_required_per_call` | — |
| `strict.additional_properties.run` / `strict.additional_properties.per_call` | `strict_additional_properties` / `strict_additional_properties_per_call` | — |
| `coverage.min_coverage.endpoint` / `.response` / `.sdk_exercise` | `min_endpoint_coverage` / `min_response_coverage` / `min_sdk_exercise_coverage` | — |
| `coverage.min_coverage.strict` | `min_coverage_strict` | — |
| `coverage.report_output.markdown` / `.json` / `.junit` / `.html` | `output_file` / `json_output` / `junit_output` / `html_output` | — |
| `coverage.console_report` | `console_output` | — |
| `coverage.sidecar_dir` | `sidecar_dir` | — |
| `baseline.violations` / `baseline.coverage` | `baseline_file` / `coverage_baseline_file` | — |
| `baseline_stale.violations` / `baseline_stale.coverage` | `baseline_stale` / `coverage_baseline_stale` | — |
| `enum_drift.enabled` / `.scan_namespaces` / `.fail_on_drift` | `enum_drift_enabled` / `enum_drift_scan_namespaces` / `enum_drift_fail_on_drift` | — |
| `phpunit.default_testsuite_as_full` | `default_testsuite_as_full` | — |
| `laravel.auto_assert` | — | `auto_assert` |
| `laravel.auto_validate_request` | — | `auto_validate_request` |
| `laravel.auto_inject_dummy_credentials` | — | `auto_inject_dummy_credentials` |
| `laravel.route_parity.external_operation_ids` / `.external_openapi_paths` | — | `route_parity.external_operation_ids` / `.external_openapi_paths` |

Use the existing [setup](setup.md) and [coverage](coverage.md) guides for each
setting's behavior. `enum_spec_base_path` remains an XML-only v2 compatibility
setting; it has no shared successor.

This step connects PHPUnit and Laravel, including `gesso:routes` and
`gesso:stubs`. Standalone `vendor/bin/gesso` commands still require their existing
flags. PSR-7 and Symfony adapter-specific policy hooks are unchanged; this does
not yet migrate all adapters or implement the complete
[v3 configuration plan](adr/0005-v3-configuration-and-cli-naming.md).
Legacy-input deprecations and removals are separate follow-up work.
