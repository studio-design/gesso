# Configuration (`gesso.php`)

Gesso 3.0 has one configuration entry point: `gesso.php` at the application
root. The PHPUnit extension, the Laravel service provider and Artisan
commands, and the CLI read the same file, so spec paths, request prefixes, and
validation policy are written once.
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
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension"/>
</extensions>
```

The extension reads `gesso.php` beside the PHPUnit XML file by default; add
`<parameter name="config" value="path/to/gesso.php"/>` only for another
location.

Laravel discovers its application-root `gesso.php` automatically and exposes
it as the `gesso` config namespace under the same key paths
(`config('gesso.spec.default')`, `config('gesso.laravel.auto_assert')`). Keep
the usual `ValidatesOpenApiSchema` trait and assertions in your tests;
override a setting per test with, for example,
`config()->set('gesso.laravel.auto_assert', true)`.
`php artisan vendor:publish --tag=gesso` writes a starter root `gesso.php`.

`spec.names` lists the specs PHPUnit must load and include in coverage (and
that `gesso:routes` compares when `--spec` is absent); `spec.default` selects
the fallback for assertions. Set both as above for a
single-spec project. `spec.strip_prefixes` controls matching, independently
of the `--request-prefix` option used to generate test requests.

## Discovery and relative paths

- PHPUnit resolves the `config` parameter relative to the selected PHPUnit XML
  file. Without the parameter, it looks for `gesso.php` beside that XML file.
  If no XML file is selected, it uses the working directory.
- `vendor/bin/gesso coverage:merge`, `coverage:gate` and `doctor` read
  `--config` (relative to the working directory), or `gesso.php` in the
  working directory when the flag is absent.
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

## Removed v2 spellings and precedence

The v2 phpunit.xml parameters (other than `config`) and the flat keys of
Laravel's `config/gesso.php` were removed in 3.0. They are not ignored: a
leftover parameter aborts PHPUnit, and a leftover flat key — in a published
`config/gesso.php` or set by `config()->set('gesso.max_errors', …)` in a test —
throws, each naming its `gesso.php` key. The full mapping is in
[`UPGRADING.md`](https://github.com/studio-design/gesso/blob/main/UPGRADING.md#configuration).
`vendor/bin/gesso doctor --spec=… --emit-config > gesso.php`
([doctor](doctor.md#generate-a-gessophp)) generates a starting file.

Omitted keys keep their documented defaults. In particular, an omitted
`spec.names` keeps PHPUnit's `front` default; an omitted `spec.base_path`
keeps the programmatic loader setup (and `openapi/` for the Artisan commands).
In that case, an explicit `spec.strip_prefixes` still replaces the loader's
prefixes without changing its base path, remote-reference settings, or cached
specs. An empty list clears the prefixes; omitting the setting preserves the
prefixes configured in bootstrap.

- PHPUnit: format/console environment overrides, then `gesso.php`, then
  defaults.
- Laravel: values set in the `gesso` config namespace (for example by a test),
  then `gesso.php`, then defaults. Lists are replaced as whole values, not
  concatenated.
- CLI: explicit flag, then `gesso.php`, then the built-in default;
  environment variables keep their existing priority.
- Explicit per-test and per-call overrides keep their existing priority.

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
require both Bearer and an API key.

## Settings

| Key | What it sets |
| --- | --- |
| `spec.base_path` | Directory of the named spec files |
| `spec.default` | Fallback spec for assertions |
| `spec.names` | Specs PHPUnit loads and reports coverage for; `gesso:routes` default |
| `spec.strip_prefixes` | Request-path prefixes stripped before matching |
| `validation.format` | `text` or `json` failure output |
| `validation.max_errors` | Errors reported per request/response (`0` = unlimited) |
| `validation.enforce_discriminator` | Discriminator mapping enforcement |
| `validation.acknowledged_unvalidatable_schemes` | Security schemes acknowledged as unvalidatable |
| `validation.skip_response_codes` | Status patterns whose bodies are not validated |
| `validation.skip_request_validation_response_codes` | Documented statuses that downgrade request failures |
| `strict.required.run` / `.per_call` | [Strict required](strict-required.md) gates |
| `strict.additional_properties.run` / `.per_call` | [Strict additional properties](strict-additional-properties.md) gates |
| `coverage.min_coverage.endpoint` / `.response` / `.sdk_exercise` / `.strict` | [Coverage thresholds](coverage.md#coverage-threshold-gate) |
| `coverage.report_output.markdown` / `.json` / `.junit` / `.html` | Report files |
| `coverage.console_report` | Console report rows |
| `coverage.sidecar_dir` | [Parallel](parallel.md) sidecar directory |
| `baseline.violations` / `baseline.coverage` | [Baseline](baseline.md) files |
| `baseline_stale.violations` / `baseline_stale.coverage` | Stale-entry handling |
| `enum_drift.enabled` / `.scan_namespaces` / `.fail_on_drift` | [Enum drift](enum-drift.md) |
| `phpunit.default_testsuite_as_full` | [Partial-run opt-in](ci.md#default_testsuite_as_full-opt-in) |
| `laravel.auto_assert` / `laravel.auto_validate_request` | Laravel automatic validation |
| `laravel.auto_inject_dummy_credentials` | `false`, `true`, or `'bearer'` |
| `laravel.route_parity.external_operation_ids` / `.external_openapi_paths` / `.excluded_route_names` | [Route parity](laravel-route-parity.md) filters |

Enum-valued keys are matched after trimming and lower-casing, exactly like the
equivalent PHPUnit parameters; the canonical lower-case value is what the
surfaces receive.

Use the existing [setup](setup.md) and [coverage](coverage.md) guides for each
setting's behavior. v2's `enum_spec_base_path` parameter has no successor:
enum bindings resolve by component name.

The `coverage:merge` / `coverage:gate` defaults also come from this file (see
[parallel runners](parallel.md) and [coverage gate](coverage-gate.md)). The
PSR-7 and Symfony policy hooks (`openApiSpec()`, `openApiMaxErrors()`,
`openApiSkipResponseCodes()`, `openApiSkipRequestValidationResponseCodes()`)
default to `spec.default` and the `validation.*` values; an override still
wins. Key names follow
[ADR 0005](adr/0005-v3-configuration-and-cli-naming.md).
