# Upgrade Guide

This file documents non-trivial upgrades between releases. Patches with
no behaviour change are not listed here — see `CHANGELOG.md` for the
full record.

Sections are ordered newest-first. If you are jumping multiple minors,
read each intermediate section in order — behavioural changes compose.

## From v2.9.x to v3.0.0

v3.0 removes the surfaces deprecated during v2. Upgrade to v2.9 first and run
your suite with deprecations visible: every removal below emitted a
`[Gesso deprecation]` notice there, except rows marked *static only*. See
[`docs/migration/v3.md`](docs/migration/v3.md) for the step-by-step guide.

### Configuration

*Pending.*

### CLI flags and exit codes

*Pending.*

### Coverage sidecars

*Pending.*

### Enum binding

*Pending.*

### Contract-check aliases

*Pending.*

### PHPUnit dependency

*Pending.*

### Deprecated helper methods

*Pending.*

## Body-validation reliability (2.7.0)

Adapters now preserve the distinction between top-level JSON `{}` and `[]`.
If an endpoint declares `type: object` but returns `[]`, its test now fails;
return `{}` or correct the schema to describe an array. This closes a silent
pass. Existing direct callers passing bare PHP arrays or
`DecodedBody::present([])` keep the legacy empty-object compatibility behavior.
The additive [`DecodedBody::fromJsonValue()`](docs/api-reference.md#decodedbody)
factory lets direct callers opt into unambiguous JSON types.

The same correction applies to **requests**. Laravel's `postJson($uri, [])`
and Symfony's `jsonRequest('POST', $uri, [])` send `[]`, not `{}`, and now fail
an object request schema. Their array-only payload arguments cannot express
an empty object. Send raw JSON instead; `{}` is the canonical empty-object body:

```php
// Laravel (ValidatesOpenApiSchema, 2.9.0+): sent verbatim, auto-assert / auto-validate still apply.
$this->jsonRaw('POST', '/object', '{}');

// Symfony KernelBrowser / HttpKernelBrowser:
$client->request('POST', '/object', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
```

For generated request cases, send the additive `$case->bodyAsJson()` accessor as
the raw content; see the [fuzzing dispatch examples](docs/fuzzing.md).
Do not convert the body to an array or use `JSON_FORCE_OBJECT`: those can
change nested empty objects or genuine arrays too. Literal JSON `null` is
sent as the string `'null'`; omitting content means no body.

Symfony and PSR-7 now retain the presence of opaque non-JSON request bodies,
matching Laravel. A present XML body no longer fails a required-body check as
if it were empty. This does not add XML schema validation: unsupported schemas
still produce `Skipped`. PSR-7 does not consume opaque streams: it uses a known
size, or restores the cursor after inspecting a seekable stream of unknown
size. Inspection is deferred until the resolved contract requires the body;
optional, undeclared, or unmatched bodies are not read. If required presence
cannot be determined safely, it reports only a body-read failure, never a
second "empty body" error. The same rule applies to JSON and form decoding
failures: only the parse/read error is reported for the body, and other
parameter/security errors remain visible even with a documented 4xx response.

Laravel, Symfony, and PSR-7 now share the JSON-or-unspecified decoding rule on
both sides. A parameter-only header such as `; charset=utf-8` has no media type
and follows the missing-type JSON fallback: malformed JSON fails on requests
and responses alike.

`gesso stubs` retains empty objects in media-type examples (including nested
objects) and emits `{}` placeholders for object schemas instead of `[]`.
Placeholders are still TODOs, not guaranteed schema-valid generated cases;
required properties and other constraints may need application-specific values.

`gesso doctor` now reports malformed request-body content nodes using the same
structural rules as runtime validation. Previously clean doctor runs may now
fail with `structure` errors; repair the reported nodes before running tests.
No flags, exit codes, diagnostic categories, or versioned wire formats change.

The Symfony and PSR-7 assertion traits now default `openApiSpec()`,
`openApiMaxErrors()`, `openApiSkipResponseCodes()`, and
`openApiSkipRequestValidationResponseCodes()` to the shared configuration
(`gesso.php`, or the `default_spec` / `max_errors` / `skip_response_codes` /
`skip_request_validation_response_codes` extension parameters). Suites that
set none of these see no change. A suite that sets them for the
framework-agnostic path now applies them to Symfony and PSR-7 assertions too —
for example `skip_response_codes=""` now validates 5xx bodies there. Override
the hook to keep a different per-suite value. The Symfony trait gains the two
skip-code hooks PSR-7 already had; per-call `$extraSkipResponseCodes` add to
`openApiSkipResponseCodes()` instead of to the built-in 5xx default.

## Deprecations

Every surface v3 removes or renames is deprecated in a v2 minor first, per the
[deprecation policy](docs/versioning.md#deprecation-policy). Using one emits a
one-shot `E_USER_DEPRECATED` naming the replacement and the removal version,
and the PHPUnit extension prints a single end-of-run STDERR line:

```text
[Gesso deprecation] 2 deprecated surface(s) still in use, 45 call(s): laravel.config.auto_inject_dummy_bearer (31), phpunit.enum_spec_base_path (14). All are removed in Gesso 3.0.
```

**If your suite prints no such line on the final v2 minor, upgrading to v3.0
needs no changes for any surface that announces its deprecation at runtime**
(configuration keys, CLI flags, environment variables, Artisan commands, PHP
symbols that raise a notice, and wire formats). Work through the table below
until it does.

Rows whose Surface starts with *static only* are outside that guarantee: enum
cases and Composer constraints cannot raise a notice, because referencing an
enum case runs no Gesso code. Code that still names a removed case (for
example an arm of a `match` over `EnumBindingReason`) breaks in v3 even on a
silent run. Search your code for the names those rows list, or run a static
analyser that reports `@deprecated` symbols, and compare your platform with
the listed Composer floors. See the
[deprecation policy](docs/versioning.md#deprecation-policy).

The summary line is the signal to trust: it is rendered from recorded
per-process counts by the PHPUnit extension (and by `gesso coverage:merge` for
parallel runs), so it survives environments that mute the raw notice — inside
a booted Laravel app the framework's test error handler absorbs
`E_USER_DEPRECATED`, so `failOnDeprecation` does not trip on these and a
custom error handler sees nothing. A suite that registers neither the
extension nor the merge CLI never prints the line, so its absence proves
nothing there.

| Deprecated in | Surface | Replacement | Removed in |
| --- | --- | --- | --- |
| 2.6.0 | `auto_inject_dummy_bearer` (Laravel config) | `laravel.auto_inject_dummy_credentials` = `'bearer'` (2.7.0+) | 3.0 |
| 2.7.0 | `EnumBindingException::forBinding()` | `new EnumBindingException($reason, $message, $enumFqcn, $specPath, $previous)` | 3.0 |
| 2.7.0 | `shouldSkipOpenApi()` (private, via `ValidatesOpenApiSchema`) | `$this->findSkipOpenApiAttribute() !== null` | 3.0 |
| 2.7.0 | `OpenApiCoverageTracker::hasAnyCoverage()` (static) | `OpenApiCoverageTracker::current()->hasAnyCoverageOn()` | 3.0 |
| 2.8.0 | `strict_required` (PHPUnit parameter) | `strict.required['run']` in `gesso.php` | 3.0 |
| 2.8.0 | `strict_required_per_call` (PHPUnit parameter) | `strict.required['per_call']` in `gesso.php` | 3.0 |
| 2.8.0 | `strict_additional_properties` (PHPUnit parameter) | `strict.additional_properties['run']` in `gesso.php` | 3.0 |
| 2.8.0 | `strict_additional_properties_per_call` (PHPUnit parameter) | `strict.additional_properties['per_call']` in `gesso.php` | 3.0 |
| 2.8.0 | `min_endpoint_coverage` (PHPUnit parameter) | `coverage.min_coverage['endpoint']` in `gesso.php` | 3.0 |
| 2.8.0 | `min_response_coverage` (PHPUnit parameter) | `coverage.min_coverage['response']` in `gesso.php` | 3.0 |
| 2.8.0 | `min_sdk_exercise_coverage` (PHPUnit parameter) | `coverage.min_coverage['sdk_exercise']` in `gesso.php` | 3.0 |
| 2.8.0 | `min_coverage_strict` (PHPUnit parameter) | `coverage.min_coverage['strict']` in `gesso.php` | 3.0 |
| 2.8.0 | `output_file` (PHPUnit parameter) | `coverage.report_output['markdown']` in `gesso.php` | 3.0 |
| 2.8.0 | `json_output` (PHPUnit parameter) | `coverage.report_output['json']` in `gesso.php` | 3.0 |
| 2.8.0 | `junit_output` (PHPUnit parameter) | `coverage.report_output['junit']` in `gesso.php` | 3.0 |
| 2.8.0 | `html_output` (PHPUnit parameter) | `coverage.report_output['html']` in `gesso.php` | 3.0 |
| 2.8.0 | `console_output` (PHPUnit parameter) | `coverage.console_report` in `gesso.php` | 3.0 |
| 2.8.0 | `baseline_file` (PHPUnit parameter) | `baseline['violations']` in `gesso.php` | 3.0 |
| 2.8.0 | `coverage_baseline_file` (PHPUnit parameter) | `baseline['coverage']` in `gesso.php` | 3.0 |
| 2.8.0 | `baseline_stale` (PHPUnit parameter) | `baseline_stale['violations']` in `gesso.php` | 3.0 |
| 2.8.0 | `coverage_baseline_stale` (PHPUnit parameter) | `baseline_stale['coverage']` in `gesso.php` | 3.0 |
| 2.7.0 | `Studio\Gesso\Fuzz\ContractCheck` | `Studio\Gesso\Contract\ContractCheck` | 3.0 |
| 2.7.0 | `Studio\Gesso\Fuzz\ContractCheckPlan` | `Studio\Gesso\Contract\ContractCheckPlan` | 3.0 |
| 2.7.0 | `Studio\Gesso\Fuzz\ContractCheckProbe` (`@internal`) | `Studio\Gesso\Contract\ContractCheckProbe` | 3.0 |
| 2.7.0 | `Studio\Gesso\Fuzz\ContractCheckFailure` | `Studio\Gesso\Contract\ContractCheckFailure` | 3.0 |
| 2.7.0 | `Studio\Gesso\Fuzz\ContractCheckSkip` | `Studio\Gesso\Contract\ContractCheckSkip` | 3.0 |
| 2.7.0 | `Studio\Gesso\Fuzz\ContractCheckSummary` | `Studio\Gesso\Contract\ContractCheckSummary` | 3.0 |
| 2.7.0 | `Studio\Gesso\Fuzz\OpenApiContractChecks` | `Studio\Gesso\Contract\OpenApiContractChecks` | 3.0 |
| 2.8.0 | `enum_spec_base_path` (PHPUnit parameter) | none — delete it; enum bindings resolve by `components.schemas` name | 3.0 |
| 2.8.0 | `OpenApiSpecLoader::configure(enumBasePath:)` / `getEnumBasePath()` | `configure()` without the argument | 3.0 |
| 2.8.0 | `#[BoundToOpenApiEnum('path/to/file.json')]` (file-path binding) | `#[BoundToOpenApiEnum(component: 'Name')]`, or no attribute when the component is named after the class | 3.0 |
| 2.9.0 | `--specs` (`coverage:merge` flag) | `--spec-name` | 3.0 |
| 2.9.0 | `--strip-prefixes` (`coverage:merge` flag) | `--strip-prefix` | 3.0 |
| 2.9.0 | `--json-output` (`coverage:merge` flag) | `--report=json:<path>` | 3.0 |
| 2.9.0 | `--junit-output` (`coverage:merge` flag) | `--report=junit:<path>` | 3.0 |
| 2.9.0 | `--html-output` (`coverage:merge` flag) | `--report=html:<path>` | 3.0 |
| 2.9.0 | `--console-output` (`coverage:merge` flag) | `--console-report` | 3.0 |
| 2.9.0 | `--min-endpoint-coverage` (`coverage:merge` flag) | `--min-coverage="endpoint=…"` | 3.0 |
| 2.9.0 | `--min-response-coverage` (`coverage:merge` flag) | `--min-coverage="response=…"` | 3.0 |
| 2.9.0 | `--min-sdk-exercise-coverage` (`coverage:merge` flag) | `--min-coverage="sdk_exercise=…"` | 3.0 |
| 2.9.0 | `--min-coverage-strict` (`coverage:merge` flag) | `--min-coverage="strict"` | 3.0 |
| 2.9.0 | `--baseline-file` (`coverage:merge` flag) | `--baseline="violations=…"` | 3.0 |
| 2.9.0 | `--coverage-baseline-file` (`coverage:merge` flag) | `--baseline="coverage=…"` | 3.0 |
| 2.9.0 | `--coverage-baseline-stale` (`coverage:merge` flag) | `--baseline-stale="coverage=…"` | 3.0 |
| 2.9.0 | `--strict-required=<mode>` (`coverage:merge` flag) | `--strict-required="run=…,per_call=…"` | 3.0 |
| 2.9.0 | `--strict-additional-properties=<mode>` (`coverage:merge` flag) | `--strict-additional-properties="run=…,per_call=…"` | 3.0 |
| 2.9.0 | `--output` (`gesso stubs` / `gesso:stubs` flag) | `--output-dir` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::BasePathNotConfigured` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::EnumBasePathNotFound` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::EnumSpecBasePathOrphaned` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::SpecFileNotFound` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::MalformedJson` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::MalformedYaml` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::YamlLibraryMissing` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::NonMappingRoot` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | *static only* `EnumBindingReason::EnumKeyMissing` (enum case) | none — only file-path bindings raise it; component-name bindings report a missing component as `ComponentNotFound` | 3.0 |
| 2.9.0 | `failOpenApi()` (private, via `ValidatesOpenApiSchema`, Symfony and PSR-7 `OpenApiAssertions`) | PHPUnit's `$this->fail()` | 3.0 |
| 2.9.0 | `assertOpenApi()` (private, via `ValidatesOpenApiSchema`, Symfony and PSR-7 `OpenApiAssertions`) | PHPUnit's `$this->assertTrue()` | 3.0 |
| 2.9.0 | `failPsr7()` (private, via PSR-7 `OpenApiAssertions`) | PHPUnit's `$this->fail()` | 3.0 |
| 2.9.0 | `assertPsr7()` (private, via PSR-7 `OpenApiAssertions`) | PHPUnit's `$this->assertTrue()` | 3.0 |
| 2.9.0 | `failExplore()` (private, via `ExploresOpenApiEndpoint`) | PHPUnit's `$this->fail()` | 3.0 |
| 2.9.0 | *static only* `phpunit/phpunit` in Gesso's `require` (Composer constraint) | declare it yourself: `composer require --dev "phpunit/phpunit:^12.0 \|\| ^13.0"`; v3 moves it to `require-dev` and no longer installs a runner for you | 3.0 |
| 2.9.0 | Coverage sidecars with `envelopeVersion` 2–9 (read by `coverage:merge`) | `envelopeVersion: 10`, written by the current workers; re-run the suite | 3.0 |
| 2.9.0 | Coverage sidecars holding a bare v1 coverage state (read by `coverage:merge`) | `envelopeVersion: 10`, written by the current workers; re-run the suite | 3.0 |
| 2.9.0 | `coverage:merge` exit code `1` on an I/O failure (unreadable sidecar, unwritable report or baseline) | exit code `2`; a check for any non-zero exit needs no change | 3.0 |
| 2.9.0 | `spec_base_path` (PHPUnit parameter / Laravel config key) | `spec.base_path` in `gesso.php` | 3.0 |
| 2.9.0 | `default_spec` (PHPUnit parameter / Laravel config key) | `spec.default` in `gesso.php` | 3.0 |
| 2.9.0 | `specs` (PHPUnit parameter / Laravel config key) | `spec.names` in `gesso.php` | 3.0 |
| 2.9.0 | `strip_prefixes` (PHPUnit parameter / Laravel config key) | `spec.strip_prefixes` in `gesso.php` | 3.0 |
| 2.9.0 | `validation_output` (PHPUnit parameter) | `validation.format` in `gesso.php` | 3.0 |
| 2.9.0 | `max_errors` (PHPUnit parameter / Laravel config key) | `validation.max_errors` in `gesso.php` | 3.0 |
| 2.9.0 | `enforce_discriminator` (PHPUnit parameter / Laravel config key) | `validation.enforce_discriminator` in `gesso.php` | 3.0 |
| 2.9.0 | `acknowledged_unvalidatable_schemes` (PHPUnit parameter / Laravel config key) | `validation.acknowledged_unvalidatable_schemes` in `gesso.php` | 3.0 |
| 2.9.0 | `skip_response_codes` (PHPUnit parameter / Laravel config key) | `validation.skip_response_codes` in `gesso.php` | 3.0 |
| 2.9.0 | `skip_request_validation_response_codes` (PHPUnit parameter / Laravel config key) | `validation.skip_request_validation_response_codes` in `gesso.php` | 3.0 |
| 2.9.0 | `sidecar_dir` (PHPUnit parameter) | `coverage.sidecar_dir` in `gesso.php` | 3.0 |
| 2.9.0 | `enum_drift_enabled` (PHPUnit parameter) | `enum_drift.enabled` in `gesso.php` | 3.0 |
| 2.9.0 | `enum_drift_scan_namespaces` (PHPUnit parameter) | `enum_drift.scan_namespaces` in `gesso.php` | 3.0 |
| 2.9.0 | `enum_drift_fail_on_drift` (PHPUnit parameter) | `enum_drift.fail_on_drift` in `gesso.php` | 3.0 |
| 2.9.0 | `default_testsuite_as_full` (PHPUnit parameter) | `phpunit.default_testsuite_as_full` in `gesso.php` | 3.0 |
| 2.9.0 | `auto_assert` (Laravel config key) | `laravel.auto_assert` in `gesso.php` | 3.0 |
| 2.9.0 | `auto_validate_request` (Laravel config key) | `laravel.auto_validate_request` in `gesso.php` | 3.0 |
| 2.9.0 | `auto_inject_dummy_credentials` (Laravel config key) | `laravel.auto_inject_dummy_credentials` in `gesso.php` | 3.0 |
| 2.9.0 | `route_parity` (Laravel config key) | `laravel.route_parity` in `gesso.php` | 3.0 |

Enum drift bindings: a backed enum now matches the `components.schemas` entry
named after its class (an `Enum` suffix on either side is ignored) in the
configured specs, so most file-path bindings can simply delete the attribute.
Keep `#[BoundToOpenApiEnum(component: 'Name')]` for enums whose component has
another name, then drop `enum_spec_base_path`. See
[enum drift detection](docs/enum-drift.md#binding-by-component-name).

The behaviour-equivalent replacement is
`laravel.auto_inject_dummy_credentials = 'bearer'`, which
[ADR 0005](docs/adr/0005-v3-configuration-and-cli-naming.md) defines. Since 2.7.0 the shared
configuration integration connects this mode to Laravel request validation.
Laravel-only keys live under the `laravel` section of the root `gesso.php`:

```php
// gesso.php (2.7.0+)
return [
    'laravel' => [
        'auto_inject_dummy_credentials' => 'bearer',
    ],
];
```

**2.6.0 does not accept this value** — keep the legacy flag until upgrading to
2.7.0 or later. Then `'bearer'` preserves the same injection scope without using the deprecated
key. Remove the old key after copying its setting into `gesso.php`.

Migrating early to `'auto_inject_dummy_credentials' => true` changes
behaviour: `true` also fills dummy values for every `apiKey` scheme (header /
cookie / query) the operation declares, so an endpoint secured by bearer
*and* apiKey passes the security check instead of failing on the missing key.
A missing-`apiKey` failure cannot be kept visible under `true`: a non-empty
test-set value satisfies the presence check, and an empty one counts as
absent and is injected over, matching the validator's own missing-credential
rule. Keep the flag off (it can be set per test) where that failure is the
behavior under test.

The old CLI flag spellings keep working until 3.0; each writes one
`[Gesso deprecation]` line to stderr, and `coverage:merge` counts its own in
the summary line. Passing an old and a new spelling of the same setting is a
usage error. See [Renamed flags](docs/cli.md#renamed-flags).

The PHPUnit parameters keep working, and an XML value still wins over
`gesso.php`; move each one to its key in a root `gesso.php` (see
[Shared configuration](docs/configuration.md)) and drop the parameter. The
notice fires at extension bootstrap, before PHPUnit's error handler is active,
so it is printed by PHP itself (subject to `display_errors`) rather than
counted by `failOnDeprecation`; the end-of-run summary line lists it either way.

The Laravel config keys (2.9.0) work the same way: a key set in the published
`config/gesso.php` (or any other source that fills `gesso.*` before the service
provider registers) keeps working and still wins over the root `gesso.php`,
and emits one notice per key. Move each key to its section of the root
`gesso.php` — `spec`, `validation`, or `laravel` — and delete
`config/gesso.php` once it is empty. The check runs when the service provider
registers, so a cached configuration (`php artisan config:cache`), which cannot
tell a published key from a package default, raises no notice; run the suite
uncached to see them. Values set later with `config()->set()` are not reported.

The table mirrors `tests/fixtures/compatibility/v2-deprecations.json`, which a
test keeps in sync with the emitters in `src/`. The `Deprecated in` column names
the release that started warning, not the release that introduced the
replacement.

The named contract checks moved from `Studio\Gesso\Fuzz\` to
`Studio\Gesso\Contract\` ([#503](https://github.com/studio-design/gesso/issues/503)).
Migration is an import rewrite. The old names keep resolving through a lazy
`class_alias()` loader (`src/Contract/Autoload.php`), removed in v3.0. The
notice fires when an old name is what loads the class; an old name first
referenced after Gesso already loaded the class through its new name resolves
silently, so a run without notices does not prove the migration is complete —
search for `Studio\Gesso\Fuzz\ContractCheck` and
`Studio\Gesso\Fuzz\OpenApiContractChecks` instead. An alias is source
compatibility, not a second identity: `get_class()`, reflection, and
`serialize()` report the `Studio\Gesso\Contract\` name, so code comparing
literal FQCN strings must switch now. Generator types (`ExploredCase`,
`ExploredOperation`, `OpenApiSpecExplorer`, …) stay in `Studio\Gesso\Fuzz\`
and are not aliased.

### Renamed environment variables and Artisan commands

The three environment variables and two Artisan commands Gesso owns moved to the
`gesso` brand. **The old spellings keep working through all of v3** and are
removed in v4.0, so this is not an upgrade blocker — but a run that uses one
prints a line like:

```text
[Gesso] WARNING: OPENAPI_BASELINE_GENERATE is deprecated and will be removed in Gesso 4.0.0. Use GESSO_BASELINE_GENERATE.
```

These do **not** raise `E_USER_DEPRECATED`, so `failOnDeprecation` suites are
unaffected. They are absent from the deprecation table above for the same
reason.

| Old | New |
| --- | --- |
| `OPENAPI_VALIDATION_OUTPUT` | `GESSO_VALIDATION_FORMAT` |
| `OPENAPI_CONSOLE_OUTPUT` | `GESSO_CONSOLE_OUTPUT` |
| `OPENAPI_BASELINE_GENERATE` | `GESSO_BASELINE_GENERATE` |
| `openapi:routes` | `gesso:routes` |
| `openapi:stubs` | `gesso:stubs` |

`OPENAPI_VALIDATION_OUTPUT` becomes `GESSO_VALIDATION_FORMAT`, not
`GESSO_VALIDATION_OUTPUT`: its values are formats (`text` | `json`), and v3
renames the matching extension parameter to `validation.format` — see
[ADR 0005](docs/adr/0005-v3-configuration-and-cli-naming.md). The
`validation_output` and `console_output` **extension parameters** are untouched
by this change.

To migrate CI env blocks and Artisan calls:

```sh
rg -l 'OPENAPI_(VALIDATION_OUTPUT|CONSOLE_OUTPUT|BASELINE_GENERATE)|openapi:(routes|stubs)' \
  | xargs sed -i '' \
    -e 's/OPENAPI_VALIDATION_OUTPUT/GESSO_VALIDATION_FORMAT/g' \
    -e 's/OPENAPI_CONSOLE_OUTPUT/GESSO_CONSOLE_OUTPUT/g' \
    -e 's/OPENAPI_BASELINE_GENERATE/GESSO_BASELINE_GENERATE/g' \
    -e 's/openapi:routes/gesso:routes/g' \
    -e 's/openapi:stubs/gesso:stubs/g'
```

(Drop the `''` after `-i` on GNU sed.) No config file, `phpunit.xml` parameter,
or PHP code change is required.

## Within v2.x

The v2.x line is covered end-to-end by SemVer (see `docs/versioning.md` for the
surface contract). Minor releases are additive by default, but "additive" does
not mean "no behaviour changes" — a fix that closes a silent-pass gap changes
what your suite reports.

Besides the sidecar note below, only v2.4.0 has a section. Earlier v2 minors carry behavioural fixes that
were never written up here: v2.2.0 stopped applying the coverage threshold gate
to partial runs (#453) and v2.3.0 began rejecting `null` response content
(#466), among others. If you are jumping from v2.0.x, v2.1.x, or v2.2.x, read
the `### Bug Fixes` entries for each intermediate release in `CHANGELOG.md`;
this file cannot be relied on to list them.

### Path templates with literals inside a segment (2.8.0, #652)

Path templates that mix literals and placeholders in one segment
(`/report.{format}`, `/v/{major}-{minor}`, `/files/{name}.{ext}`) now match
requests, and their placeholder values reach path-parameter validation.
Before this, they never matched. A value ends at the first occurrence of the
literal that follows it, so `{name}.{ext}` splits `archive.tar.gz` into
`archive` and `tar.gz`.

Two template shapes that used to compile silently are now rejected, both when
validation builds its path matcher and as a `gesso doctor` `structure` error:

- **Adjacent placeholders** (`/v/{a}{b}`): no rule says where one value ends
  and the next begins. Before this, the segment compiled as one placeholder
  literally named `a}{b`, which matched anything and validated nothing. Put a
  literal between them (`/v/{a}-{b}`) or merge them into one placeholder
  (`/v/{ab}`).
- **A placeholder name repeated inside one segment** (`/v/{id}-{id}`): OpenAPI
  requires unique names within a template. Rename one of them
  (`/v/{from}-{to}`).

Because the matcher compiles every `paths` key, one such template makes
validation fail for every request against that spec. Run `gesso doctor` to
find them.

### Form-encoded explored cases (#498)

`ExploredCase` gains an additive `$contentType` (default `application/json`, also a
new optional trailing constructor parameter), `bodyAsString()`, and
`bodyAsFormFields()`. The explorer now synthesizes an
`application/x-www-form-urlencoded` body when that is an operation's only
schema-carrying media type, instead of skipping the operation. Existing
dispatchers keep working for JSON operations, but one that hardcodes
`Content-Type: application/json` and `bodyAsJson()` will mislabel the new form
cases — send `$case->contentType` and `$case->bodyAsString()` instead.

### Laravel validator cache per test case (2.7.0)

The Laravel `ValidatesOpenApiSchema` trait now caches its validators on the
test-case instance instead of in process-wide statics, as the Symfony adapter
already did. PHPUnit builds a fresh test case per test method, so each test
reads its own `gesso.max_errors` / `gesso.skip_*` config and
`self::resetValidatorCache()` (`@internal`) is no longer needed; delete it from
`setUp()` / `tearDown()` (the Pest example did). It stays callable, but now
only forgets which responses were already validated.

One consequence: the validators are built on the first request or assertion
of a test, so change those settings before then (in `setUp()` or at the top of
the test). A change made after that point no longer rebuilds the validators
within the same test method.

### Sidecar envelope version 10

Paratest workers on this release write sidecar `envelopeVersion: 10`, where
each tracker half is an optional key instead of a new version number. A
`gesso coverage:merge` from an older 2.x release rejects it as an unknown
version — as every previous envelope bump did — so upgrade the merge step
together with the workers. The 2.x merge keeps reading envelopes 2–9 and the
bare v1 coverage state, with a `[Gesso deprecation]` notice since 2.9.0;
dropping them is a v3.0 change (see [Deprecations](#deprecations) and
[versioned sidecar compatibility](docs/versioning.md#versioned-sidecar-compatibility)).

### From v2.3.0 → v2.4.0

There are no backward-incompatible changes to the public API. The additions —
new `coverage:gate` / `stubs` CLI commands, new `coverage_baseline_*` extension
parameters and `coverage:merge` flags, the new `Studio\Gesso\UploadedPart`
class, new methods such as `ContractCheckPlan::dispatchIsolatedUsing()` and
`ExploredCase::withCookies()`, and a new optional trailing `$cookies` parameter
on `ExploredCase::__construct()` — leave every existing
signature, flag, exit code, and wire format meaning what it meant in v2.3.0. The
new commands, the coverage baseline, and the `ignored_auth` /
`missing_required_header` contract checks are all opt-in. Four existing
behaviours change:

- **Form request bodies are now validated against their schema** (#486). A
  `multipart/form-data` or `application/x-www-form-urlencoded` request body that
  matched a spec media type declaring a `schema` previously came back `Skipped`
  — and `OpenApiValidationResult::isValid()` reports `Skipped` as valid, so it
  passed silently. It is now checked.
  - **Behaviour change**: contract tests posting form bodies may begin failing.
    Values arrive as strings and are coerced to the declared property types
    exactly as query parameters are, so `age=3` satisfies `type: integer` while
    `age=three` fails at `/age`. Fix the payload or the schema; these failures
    expose drift the skip only hid.
  - Applies to the Laravel trait, the Symfony trait, and the PSR-7 validator.
    Response-side form bodies remain presence-only.
  - There is no opt-out flag. A part whose media type the wire did not preserve
    is still reported as unchecked rather than failed, and a raw
    `multipart/form-data` payload with no parsed parts stays `Skipped` with a
    reason. See
    [`docs/supported-features.md`](docs/supported-features.md#body-validation).
- **An empty Schema Object `{}` is read as "any value"** (#483). It previously
  reached opis as a JSON array and was rejected with
  `InvalidKeywordException: … must be a json schema`, and
  `{items: {}, additionalItems: false}` was silently read as the empty tuple
  form, failing compliant arrays. Specs that could not load, or that failed
  valid payloads for this reason, now work. Nothing that previously passed
  starts failing.
- **Specification extensions on a Responses Object are no longer counted**
  (#495). An `x-`-prefixed key under `responses` was previously declared as a
  coverage tuple nothing could ever cover, and reported by `doctor` as a
  structure error.
  - **Behaviour change**: the coverage denominator drops for specs using them,
    so reported percentages rise and a `min_response_coverage` gate becomes
    easier to satisfy. Regenerate any committed coverage baseline. `doctor`
    stops emitting the false structure error, which can flip its exit code from
    1 to 0.
- **`doctor` accepts OpenAPI 3.1+ documents that omit `paths` or `responses`**
  (#479). Both are required in 3.0 but optional from 3.1 on — a document may
  describe only `webhooks`, and an operation may describe only its request. The
  runtime validators already treated an absent key as "nothing to match"; only
  `doctor` disagreed.
  - **Behaviour change**: a CI job gating on `gesso doctor`'s exit code may go
    from 1 to 0 for such documents. A node that is *present* with the wrong type
    is still an error.

Users of the fuzz `ContractCheckPlan` with a custom dispatcher should also note
that `ExploredCase` now carries a `cookies` map. Forward it to your client's
cookie bag — Laravel and Symfony test clients take cookies as a separate
`SymfonyRequest::create()` argument, so a `Cookie:` request header alone never
reaches `$request->cookie(...)`. A dispatcher that drops them makes a
cookie-credential `ignored_auth` probe pass for the wrong reason. Existing
checks are unaffected.

## From v1.10.x to v2.0.0

Gesso v2 uses `Studio\Gesso\` as its only PHP namespace and is installed from
`studio-design/gesso`. Complete the staged namespace and CLI migration described
in [`docs/migration/v2.md`](docs/migration/v2.md) before replacing the Composer
requirement.

Laravel applications must clear their configuration cache while v1.10 is still
installed, rename `config/openapi-contract-testing.php` to `config/gesso.php`,
and update direct lookups from `openapi-contract-testing.*` to `gesso.*`. Gesso
v2 discovers `Studio\Gesso\Laravel\GessoServiceProvider`, publishes with the
`gesso` tag, and rejects both legacy-only and dual-key configurations instead of
guessing precedence. Rebuild the configuration cache only after the application
boots successfully on v2.

Coverage JSON consumers must accept `schema_version: 2` and the fixed
`tool.name` value `studio-design/gesso`. The remaining coverage fields retain
their v1 meanings. Laravel route parity JSON consumers must also accept
`schema_version: 2`, including the new `external_operations` result and summary
count. Doctor JSON remains at `schemaVersion: 1`; sidecar envelope and
tracker-state versions are unchanged. See the
[v2 migration guide](docs/migration/v2.md#update-coverage-json-consumers).

Update log routing for the optional-Faker warning and Laravel contradictory-
intent deprecation from `[openapi-contract-testing]` to `[Gesso]`. Feature-
oriented prefixes such as `[OpenAPI Coverage]` and `[OpenAPI Schema]` are
unchanged.

If tooling persists arrays returned by the spec loader, reload the original
OpenAPI Description after upgrading. V2 replaces the resolver-owned
`x-studio-openapi-contract-testing-implicit-schema-name` provenance extension
with `x-studio-gesso-implicit-schema-name`; it deliberately does not trust or
dual-read either marker when authored in input.

Direct `OpenApiResponseValidator` construction now requires a
`StrictRequiredTracker` as its first argument. Create one tracker per test run
and reuse it across validators; framework adapters inject their run-level
tracker automatically:

```php
$tracker = new StrictRequiredTracker();
$validator = new OpenApiResponseValidator($tracker, maxErrors: 20);
```

This removes the validator's fallback to the process-global tracker. Named
`maxErrors` and `skipResponseCodes` arguments remain unchanged after the new
required first argument.

`InvalidOpenApiSpecReason::ExternalRef` and
`InvalidOpenApiSpecReason::RemoteRefNotImplemented` have been removed. Neither
case was emitted by production code by the end of v1. Use the specific resolver
reason instead: local-reference failures use `LocalRefNotFound`,
`LocalRefOutsideAllowedRoot`, `LocalRefUnreadable`, or
`LocalRefRequiresSourceFile`; remote-reference failures
use `RemoteRefDisallowed`, `HttpClientNotConfigured`,
`RemoteRefHostDisallowed`, or `RemoteRefFetchFailed`; unsupported `file:` references use
`FileSchemeNotSupported`.

Local external `$ref` targets are now confined to `spec_base_path` after
canonicalization. An absolute path, `../` traversal, or symlink is rejected
when its canonical target resolves outside the configured directory. If entry
documents and shared schemas are siblings, move `spec_base_path` to their narrowest
trusted common parent and include the entry subdirectory in `specs` (for
example, `spec_base_path=openapi` with `specs=bundled/front`). Doctor uses the
same policy; pass `--local-ref-root=openapi` for that layout. Code branching on
resolver reasons should handle the new `LocalRefOutsideAllowedRoot` case.

Entry spec lookup is confined to the same canonical `spec_base_path`. Nested
names such as `bundled/front` remain supported, while names containing a `..`
segment, absolute paths, and symlinks that resolve outside the root are reported
as `SpecFileNotFoundException`. This intentionally uses the same diagnostic for
existing and missing outside targets so lookup cannot reveal their existence.

`#[BoundToOpenApiEnum]` paths are likewise confined to the canonical
`enum_spec_base_path`, or to `spec_base_path` when the enum-specific root is
unset. Nested relative paths remain supported; `..`, absolute paths, and
symlinks resolving outside the selected root now fail with
`EnumBindingReason::SpecFileNotFound`. Move the enum base to the narrowest
trusted common parent instead of traversing out of it.

Parallel coverage merge now applies the same sidecar trust boundary as worker
writes. On POSIX, a `sidecar_dir` that is group/world writable is rejected;
all platforms reject a symbolic-link sidecar directory, and individual
sidecars must be regular non-symlink files that are not group/world writable on
POSIX. If a custom shared directory was previously used, replace it with a
dedicated directory owned by the test user.

HTTP(S) `$ref` resolution now requires an explicit destination allowlist.
Pass `allowedRemoteRefHosts: ['specs.example.com']` together with
`allowRemoteRefs: true`; list every trusted host used by nested references.
The Doctor equivalent is repeatable `--remote-ref-host=<host>`. Unlisted hosts
are rejected before a request is sent. Keep PSR-18 redirect following disabled
and use canonical URLs so a redirect cannot move below this policy boundary.
Remote documents are also limited to 10 MiB each by default. `OpenApiSpecLoader`
configuration can raise the positive `maxRemoteRefBytes` value, and Doctor uses
`--remote-ref-max-bytes=<bytes>`. Configure transport-level timeouts and body
limits too because a PSR-18 implementation may buffer the response before
returning its PSR-7 stream.

Several orchestration types that were unintentionally part of the v1 PHP API
are marked `@internal` in v2. Do not call coverage renderer, sidecar I/O,
threshold evaluator, or extension-configuration exception classes directly;
use the PHPUnit extension or `gesso coverage:merge` options and consume their
documented output formats. The same applies to `SchemaContext`,
`SkipOpenApiResolver`, `Laravel\Commands\OpenApiRoutesCommand`,
`PHPUnit\ConsoleOutput`, `PHPUnit\InvalidStrictRequiredConfigurationException`,
and `Pest\Expectations`. Use the public validators and framework traits, the
`openapi:routes` Artisan command, PHPUnit configuration values, and registered
Pest expectations instead. These classes still exist as implementation details
in v2.0.0, but their PHP signatures are no longer covered by SemVer.

## Within v1.x

The v1.x line is covered end-to-end by SemVer (see "v0.x → v1.0.0"
below for the surface contract). Minor releases are additive by default.
Three behavioural changes exist so far: v1.3.0 (gated on an already-opt-in
flag), v1.8.0 (`discriminator.mapping` enforcement, default-on with an opt-out
flag), and v1.9.0 (native JSON Schema dialect enforcement for OpenAPI 3.1/3.2).

### From v1.8.0 → v1.9.0

- OpenAPI 3.0 continues to use the existing Draft 07 compatibility conversion.
- OpenAPI 3.1/3.2 now preserve and enforce native JSON Schema keywords,
  including `prefixItems`, `const`, `$dynamicRef`, `$dynamicAnchor`,
  `unevaluatedProperties`, `unevaluatedItems`, `dependentSchemas`, and
  `dependentRequired`.
- The root `jsonSchemaDialect` is used as the document default and a Schema
  Object's `$schema` overrides it. Unsupported custom dialects now fail with
  `InvalidOpenApiSpecException` instead of being interpreted silently.
- **Behaviour change:** tests may begin failing where one of the constraints
  above was previously dropped or only warned. Fix the payload or the schema;
  these failures expose contract drift that the older Draft 07 lowering could
  not detect.
- No public method signatures or dependencies change. This ships as a minor
  release because the affected 3.1 features were documented as unsupported;
  the new failures close documented silent-pass gaps.
- See `docs/benchmarks/native-json-schema-2020-12.md` for the measured pipeline
  impact and a reproducible benchmark command.

### From v1.7.0 → v1.8.0

- **`discriminator.mapping` is now enforced** (#262). Previously the
  converter stripped `discriminator` and emitted a one-shot
  `E_USER_WARNING`; the underlying `oneOf` / `anyOf` was validated only as
  a plain union, so a polymorphic body that lied about its type passed as
  long as it matched any branch. The converter now lowers `discriminator` +
  `mapping` into Draft-07 `if`/`then` conditionals so the discriminator
  value steers validation toward a single branch.
  - **Behaviour change**: a body whose discriminator value routes to a
    branch it does not satisfy (e.g. `kty: RSA` carrying EC-only fields, or
    an unknown discriminator value) now **fails** where it previously
    passed. This is the contract bug the warning only narrated.
  - **The `discriminator.mapping` `E_USER_WARNING` is removed.** This also
    fixes Laravel consumers, whose `HandleExceptions` turned that advisory
    warning into a fatal `ErrorException` on the first polymorphic contract
    test. No per-consumer `set_error_handler` boilerplate is needed any
    more.
  - **Opt out**: set `enforce_discriminator: false` (Laravel
    `config/openapi-contract-testing.php`) or
    `<parameter name="enforce_discriminator" value="false"/>` (the PHPUnit
    `OpenApiCoverageExtension`; `0` / `no` also work) to keep the old
    strip-without-enforce behaviour (now also warning-free).
  - **Malformed `discriminator`** blocks (missing/non-string
    `propertyName`, non-array `mapping`, non-string mapping value,
    unresolvable mapping pointer, non-object target) now surface as a loud
    validation failure under enforcement, instead of being silently
    dropped.
  - **Known limitation**: self-referential discriminator chains (a subtype
    that re-contains the same base discriminator via `allOf` + `$ref`) are
    enforced at the first recursion level; the inner re-appearance is
    stripped without re-lowering (the outer branch already enforces it).
    See `docs/supported-features.md` → "Schema features" → `discriminator`.

### From v1.3.0 → v1.4.0

- No source-code changes required.
- **New `AssertsNoEnumDrift` PHPUnit trait** (#186). Wraps
  `EnumDriftAsserter::assertNoDrift()` so drift tests increment PHPUnit's
  assertion counter and stop being flagged risky under PHPUnit 13's
  `beStrictAboutTestsThatDoNotTestAnything=true` default. Drop-in for
  existing drift tests:

  ```php
  use Studio\OpenApiContractTesting\PHPUnit\AssertsNoEnumDrift;

  final class EnumDriftTest extends TestCase
  {
      use AssertsNoEnumDrift;

      #[Test]
      public function no_drift(): void
      {
          $this->assertNoEnumDrift([StatusEnum::class, RoleEnum::class]);
      }
  }
  ```

  The static `EnumDriftAsserter::assertNoDrift()` API is unchanged —
  non-PHPUnit drift CI scripts keep working as-is.
- **Internal move**: `StackTraceFilter` moved from
  `Studio\OpenApiContractTesting\Laravel\Internal\` to
  `Studio\OpenApiContractTesting\Internal\`. The class is `@internal` and
  outside the SemVer surface; the move is mentioned only for the rare
  consumer who imported it directly (which the `@internal` marker said
  not to do).

### From v1.2.0 → v1.3.0

- No source-code changes required.
- **`auto_validate_request: true` now downgrades documented 4xx failures
  to `Skipped`** (#182). When request validation is enabled AND the
  response status matches `skip_request_validation_response_codes`
  (default `['422', '400']`) AND the spec documents that status for the
  operation, the request-validation `Failure` becomes `Skipped`. This
  removes false-fails for dataProvider tests that intentionally send
  invalid input to verify documented 4xx behaviour.

  - Undocumented 4xx responses still fail loudly (real spec gap).
  - Successful responses are never demoted.
  - Tests asserting that the request validator returns `Failure` for
    invalid input + documented 422 will need updating — assert
    `Skipped` (or `isSkipped() === true`) instead.
  - To restore strict pre-v1.3 behaviour, set
    `skip_request_validation_response_codes => []` in your Laravel
    config. The downgrade is gated on `auto_validate_request: true`, so
    suites that never enabled auto-validation are unaffected.
- **New `auto_inject_dummy_credentials` flag** (#180). Superset of the
  existing `auto_inject_dummy_bearer`: also fills dummy values for
  `apiKey` (header / cookie / query) schemes in the validator's view
  when the test did not supply a real credential. Off by default; the
  legacy bearer-only flag still works and is bypassed when the new flag
  is on. No migration required unless you opt in.

### From v1.1.0 → v1.2.0

- No source-code changes required.
- **New `enum_spec_base_path` PHPUnit extension parameter** (#171).
  Optional secondary root used only for `#[BoundToOpenApiEnum]` path
  resolution. Set it when per-enum JSON sources live outside
  `spec_base_path` (e.g. `openapi/_shared/...` while bundles live in
  `openapi/bundled/`). Single-root projects: omit it — behaviour is
  bit-for-bit identical to v1.1.x. See README → "Enum drift detection"
  for the bundled-external layout recipe.
- **Markdown coverage output formatting** (#176). The Markdown renderer
  now emits a blank line between each endpoint heading and its response
  table. Visual-only fix; only relevant if a downstream consumer parses
  the Markdown by line offsets.

### From v1.0.0 → v1.1.0

- No source-code changes required.
- **New enum drift detection surface** (#166). Static set-membership
  comparison between PHP backed enums and their bound OpenAPI `enum:`
  arrays — catches PHP-only cases the runtime never observes AND
  spec-only values the implementation cannot produce. New public symbols
  (all `final`, additive, covered by v1.x SemVer):

  - `Attribute\BoundToOpenApiEnum(string $specPath)` — bind a PHP enum
    to its spec file. Path resolves relative to
    `OpenApiSpecLoader::getBasePath()`.
  - `Schema\EnumDriftAsserter::assertNoDrift(array $enumFqcns, bool $failOnDrift = true)`
    — fatal by default; `failOnDrift: false` demotes to a single
    `E_USER_WARNING` per drifting binding.
  - `Schema\EnumDriftAsserter::detectAll()` — non-throwing inspection
    seam returning `EnumDriftReport[]`.
  - `Schema\EnumDriftReport`, `Exception\EnumDriftException`,
    `Exception\EnumBindingException` + `EnumBindingReason`.

  Adopting is opt-in — without `#[BoundToOpenApiEnum]` on any enum,
  nothing runs.
- **Opt-in auto-discovery via the PHPUnit extension** (#168). Three new
  parameters scan PSR-4 namespaces at bootstrap and run drift checks
  before any test executes:

  ```xml
  <parameter name="enum_drift_enabled" value="true"/>
  <parameter name="enum_drift_scan_namespaces" value="App\Enums,App\Domain\Enums"/>
  <parameter name="enum_drift_fail_on_drift" value="true"/>
  ```

  Defaults: `enum_drift_enabled=false` (master opt-in),
  `enum_drift_fail_on_drift=true` (FATAL + `exit(1)` on drift).
  Misconfiguration (unresolvable namespace, missing Composer
  `ClassLoader`, `EnumBindingException`) always FATALs regardless of
  `enum_drift_fail_on_drift` — setup errors must not hide drift signals.

## v0.x → v1.0.0

v1.0.0 is the API stability commitment. Anything not marked `@internal`
in v1.0.0 is covered by SemVer for the v1.x line.

### What's frozen by SemVer in v1.x

- Public class names and namespaces
- Public method signatures
- Public constants and their values
- Enum cases (additions are SemVer-minor; removals are SemVer-major)
- The `OpenApiValidationResult` shape
- The CLI surface of `bin/openapi-coverage-merge`
- The `OpenApiCoverageExtension` PHPUnit configuration parameters
- The Laravel `ValidatesOpenApiSchema` trait's public methods

### What's NOT frozen (will not break SemVer when changed)

- Anything marked `@internal`. This includes
  `OpenApiSpecLoader::clearCache/evict/reset`,
  `OpenApiCoverageTracker::reset/exportState/importState`,
  `ValidatesOpenApiSchema::resetValidatorCache`, and
  `OpenApiSchemaConverter::resetWarningStateForTesting`.
- The `@internal` PHP methods used to import and export paratest state are not
  frozen as PHP API. The versioned payloads accepted by the released merge CLI
  remain a compatibility surface; see
  [`docs/versioning.md`](docs/versioning.md#versioned-sidecar-compatibility).
- Internal helper classes under `Internal/`, `Validation/Support/` (the
  classes themselves are not user-callable).
- Error messages from validators (we may improve them).
- The set of `format` keywords delegated to opis (we follow opis).

### What's NOT covered by SemVer at all

- OpenAPI features explicitly marked "not supported" or "presence-only"
  in README "Supported features and known limitations"
- Behaviour of bug-fix releases that close a documented silent-pass case
  (a test that passed only because of the silent pass may start failing —
  that's the bug fix doing its job, not a SemVer break)

### Migration notes by source version

There are **no breaking source-code changes** from any v0.16+ release to
v1.0.0. v1.0.0 is a stability promotion of v0.19.0 — every fix from the
v0.16 → v0.19 dogfood cycle is in v1.0.0 unchanged. The behavioural
changes listed below ship as part of those minors and may surface when
upgrading; review the section that matches your starting version and
forward.

If you are on v0.14.0 or older, apply the v0.14.0 → v0.15.0 namespace
migration first (see the table below), then read this section top-to-bottom.

#### Common to all v0.x → v1.0 upgrades — new `E_USER_WARNING`s

The library uses `trigger_error(..., E_USER_WARNING)` as its v1.0 official
silent-pass channel (see README → "Warning channel"). Tests configured
with PHPUnit's `failOnWarning="true"` (the default in this repo's
`phpunit.xml.dist`) will fail on first encounter. The categories that
ship between v0.16.0 and v1.0.0 are:

| Category prefix | Source | Warns on |
|---|---|---|
| `[security]` | `SecurityValidator` | `oauth2`, `openIdConnect`, `mutualTLS`, `http-basic`, `http-digest` schemes (silently passed before) |
| `[OpenAPI Schema]` | `OpenApiSchemaConverter` | `unevaluatedProperties` / `unevaluatedItems` (Draft 07 has no equivalent), `discriminator.mapping` (stripped — mapping does not steer validation), unknown / malformed `format` values |

Each warning is dedup'd per-process and prefixed with the category tag so
you can filter them mechanically. To suppress one category, install a
`set_error_handler` that matches the prefix; do NOT blanket-suppress
`E_USER_WARNING`. To stay green without filtering, set
`failOnWarning="false"` in your project's `phpunit.xml.dist`.

#### From v0.15.0 → v1.0.0

- No source-code changes required.
- All warnings in the table above apply; previously these were silent passes.
- v0.15.0 already required updating `use Studio\OpenApiContractTesting\X`
  imports per the v0.14.0 → v0.15.0 migration table — that is unchanged.

#### From v0.16.0 → v1.0.0

- No source-code changes required.
- **`discriminator.mapping`** now warns on first encounter (#147). Specs
  with non-empty `mapping` previously silently dropped the keyword;
  validation behaviour is unchanged (the underlying `oneOf` / `anyOf` is
  still validated as a union).
- **Unknown / malformed `format` keywords** now warn (#151). A typo like
  `format: emial` previously silently passed every string; the converter
  now emits a one-shot warning per unknown format value.

#### From v0.17.0 → v1.0.0

- No source-code changes required.
- **Multi-JSON-per-status schema selection** behaves differently (#152).
  When a spec declares multiple JSON keys for the same status (e.g.
  `application/json` AND `application/problem+json`) and the response
  carries a JSON-flavoured `Content-Type`, the validator now prefers the
  spec key that exactly matches the actual `Content-Type` before falling
  back to the first JSON key. Pre-v0.17 behaviour was first-JSON-wins —
  problem-details bodies served as `application/problem+json` were judged
  against the success-shape `application/json` schema. Single-JSON specs
  and vendor `+json` suffixes the spec doesn't enumerate are unaffected.

#### From v0.18.0 → v1.0.0

- No source-code changes required.
- **`additionalProperties: false` cascade dedup** strips the pseudo-error
  that named declared properties as not-allowed when any sub-property
  failed (#159). A 1-error failure that previously inflated to 2 errors
  collapses back to 1. If a test was asserting the exact error count or
  the cascade message, update the assertion.

#### From v0.19.0 → v1.0.0

- No source-code changes required.
- **Cascade dedup now walks across array boundaries** (#161). Cascades
  through `{ data: [Item] }`-shaped envelopes — including the shape
  `OpenApiSchemaConverter` lowers OAS 3.1 `prefixItems` to — collapse the
  same way as the root-level dedup did in v0.18.0. Same assertion-update
  caveat as v0.18.0.

## v0.14.0 → v0.15.0

The "v1.0 prep" release. Twenty-two classes moved into focused
sub-namespaces. No compat shim — pre-1.0 breaking changes are the
contract for this release line.

See the v0.15.0 entry in `CHANGELOG.md` for the full migration table.
The mechanical fix is updating `use Studio\OpenApiContractTesting\X`
imports per the table.
