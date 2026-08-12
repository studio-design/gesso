# ADR 0005: v3 configuration and CLI naming

- Status: Accepted
- Date: 2026-08-06
- Issue: [#520](https://github.com/studio-design/gesso/issues/520)
- Related: [#501](https://github.com/studio-design/gesso/issues/501),
  [#502](https://github.com/studio-design/gesso/issues/502),
  [#504](https://github.com/studio-design/gesso/issues/504),
  [#507](https://github.com/studio-design/gesso/issues/507),
  [#508](https://github.com/studio-design/gesso/issues/508)
- Supersedes: none

## Context

[ADR 0004](0004-v3-consistency-policy-and-protected-core.md) fixed what may not
regress during v3 and what a reduction PR must prove. It deliberately decided no
name.

Four v3 issues then proposed concrete names. Each is coherent read alone, and
each was written without the others in view, so they disagree. Implementing them
in any order yields a v3 carrying the duplication v3 exists to remove — the
failure would land as shipped names, and renaming them again costs another major.

The disagreements, verbatim from the issue bodies:

**A. What holds a configuration value.** #501 makes a nested PHP array the source
of truth:

> `'coverage' => ['console_output' => 'default', 'output_file' => null, …, 'min_endpoint_coverage' => null, …]`

Issue #502 collapses the same settings into a string grammar:

> `min_coverage="endpoint=90,response=80,sdk-exercise=50,strict"`

Both landing leaves one setting reachable under two spellings.

**B. Report sinks on `coverage:merge`.** #502 keeps one list-valued flag:

> the CLI keeps `--min-coverage`, `--baseline`, `--baseline-stale`,
> `--report-output`, `--console-report`

Issue #507 splits format from destination:

> Format is `--format`. Destination is `--output-file`. Never the same flag.

`--report-output="markdown=a.md,json=b.json"` and `--format` + `--output-file`
answer the same question two ways.

**C. `console_output`.** #502 renames it:

> `console_output` → renamed `console_report` (it filters rows, it does not write
> a file); values unchanged

Issue #507 leaves the flag alone:

> `--console-output` stays; it is a verbosity mode.

Renaming the parameter but not the flag re-opens the parameter/flag split #501
closes.

**D. `enum_base_path`.** #501's `gesso.php` sketch carries
`'enum_base_path' => null` under `spec`. #508 deletes that setting outright —
`enum_spec_base_path`, `--enum-spec-base-path`, `OpenApiSpecLoader::configure()`'s
`$enumBasePath`, and `getEnumBasePath()`.

**E. Environment variables.** #501 states "the 3 environment variables are
unchanged" while defining the precedence chain. #504 renames all three to
`GESSO_*`.

## Decision

Four rules. Each resolution below is a consequence of one of them, not a
case-by-case preference.

1. **One setting has one name and one shape.** The shape is what PHP array syntax
   expresses. An input that can only carry a string — a `phpunit.xml` attribute, a
   CLI flag — carries an *encoding* of that shape, never a second name for it.
2. **A name describes the value, not the surface it arrives on.** `output` means a
   file is written. `format` selects a rendering. `report` names what is rendered.
   A key keeps its name across `gesso.php`, `phpunit.xml`, and the CLI.
3. **No key ships that another v3 change removes.** A setting on its way out is
   never introduced into a new schema, whatever the merge order.
4. **One issue owns each name.** Where two issues touch the same spelling, the one
   whose subject *is* that spelling decides it; the other names the role.

### A — the array is the value, the string is an encoding

`gesso.php` holds arrays. `phpunit.xml` and the CLI keep #502's collapsed
grammar, parsed by the single shared parser #502 already requires, producing the
same array.

```php
// gesso.php
'min_coverage' => ['endpoint' => 90, 'response' => 80, 'sdk_exercise' => 50, 'strict' => true],
```

```xml
<parameter name="min_coverage" value="endpoint=90,response=80,sdk_exercise=50,strict"/>
```

One grammar, not two: `name=value` pairs separated by commas, a bare token for a
flag-like sub-key, and sub-key names identical to the array keys. #502's draft
used `run:fail,per-call:warn` for the strict keys and `endpoint=90` for
thresholds; the colon form and the kebab-cased sub-key are dropped, so
`strict_required="run=fail,per_call=warn"` reads as the same grammar and
transcribes to `['run' => 'fail', 'per_call' => 'warn']` without a table.

Rule 1 also settles a question neither issue raised: `default_testsuite_as_full`
is genuinely PHPUnit-runner-specific — it interprets `<phpunit defaultTestSuite>`
(`src/PHPUnit/OpenApiCoverageExtension.php:699-708`). It gets a `phpunit` section,
mirroring #501's `laravel` section, so "the extension takes exactly one parameter,
`config`" stays true.

### B — `--format` and `--output-file`, never one flag

Issue #507 wins. `--report-output` is not added. The `report_output` config key
survives as the array form; on the CLI its entries are the repeatable
`--report=<format>:<path>`.

```sh
gesso coverage:merge --format=markdown --output-file=build/cov.md \
  --report=json:build/cov.json --report=junit:build/cov.xml
```

`--format` has no `gesso.php` counterpart, and that is not an inconsistency: it
selects what goes to **stdout** for one invocation, and a config file has no
stdout to configure. `report_output` maps to `--output-file` and `--report=`
only.

### C — `console_report` on both sides

Parameter and flag are both renamed: `console_report`, `--console-report`. Rule 2
decides it; #502 read the code correctly and #507's "it is a verbosity mode" is
the argument *for* the rename, not against it.

The same rule reaches one name neither issue listed. `validation_output` is a
format — its accepted values are `text|json` (`src/ValidationOutputFormat.php:17-18`)
and its own warning text already calls it one
(`src/PHPUnit/OpenApiCoverageExtension.php:362`, "Invalid validation_output
parameter … Falling back to the configured format"). It becomes
`validation.format`, and by rule 4 #504 owns the matching environment variable:
`GESSO_VALIDATION_FORMAT`, not `GESSO_VALIDATION_OUTPUT`. This adds one rename
to the three #504 already carries.

### D — `enum_base_path` is never introduced

Issue #501 drops it from the `gesso.php` schema. #508 then has nothing to remove
from a key set that never shipped, and the two issues can merge in either order.

### E — #504 owns the environment-variable spellings

The precedence chain #501 defines — explicit CLI flag → environment variable →
`gesso.php` → default — names the role. #504 names the variables. #501's "the 3
environment variables are unchanged" is scoped to *by #501*.

## The v3 `gesso.php` key set

29 extension parameters and 13 Laravel config keys — 42 declarations across two
files, 38 distinct settings once the four duplicated pairs are counted once —
become 26 keys in one file.

| v3 key | Replaces |
| --- | --- |
| `spec.base_path` | `spec_base_path` (extension + Laravel) |
| `spec.default` | Laravel `default_spec` |
| `spec.names` | `specs` |
| `spec.strip_prefixes` | `strip_prefixes` (extension + Laravel) |
| `validation.format` | `validation_output` |
| `validation.max_errors` | Laravel `max_errors` |
| `validation.enforce_discriminator` | `enforce_discriminator` (extension + Laravel) |
| `validation.acknowledged_unvalidatable_schemes` | `acknowledged_unvalidatable_schemes` (same name, both surfaces) |
| `validation.skip_response_codes` | Laravel `skip_response_codes` |
| `validation.skip_request_validation_response_codes` | Laravel `skip_request_validation_response_codes` |
| `strict.required` = `['run' => …, 'per_call' => …]` | `strict_required`, `strict_required_per_call` |
| `strict.additional_properties` = `['run' => …, 'per_call' => …]` | `strict_additional_properties`, `strict_additional_properties_per_call` |
| `coverage.min_coverage` = `['endpoint' => …, 'response' => …, 'sdk_exercise' => …, 'strict' => …]` | `min_endpoint_coverage`, `min_response_coverage`, `min_sdk_exercise_coverage`, `min_coverage_strict` |
| `coverage.report_output` = `['markdown' => …, 'json' => …, 'junit' => …, 'html' => …]` | `output_file`, `json_output`, `junit_output`, `html_output` |
| `coverage.console_report` | `console_output` |
| `coverage.sidecar_dir` | `sidecar_dir` |
| `baseline` = `['violations' => …, 'coverage' => …]` | `baseline_file`, `coverage_baseline_file` |
| `baseline_stale` = `['violations' => …, 'coverage' => …]` | `baseline_stale`, `coverage_baseline_stale` |
| `enum_drift.enabled` | `enum_drift_enabled` |
| `enum_drift.scan_namespaces` | `enum_drift_scan_namespaces` |
| `enum_drift.fail_on_drift` | `enum_drift_fail_on_drift` |
| `phpunit.default_testsuite_as_full` | `default_testsuite_as_full` |
| `laravel.auto_assert` | Laravel `auto_assert` |
| `laravel.auto_validate_request` | Laravel `auto_validate_request` |
| `laravel.auto_inject_dummy_credentials` | Laravel `auto_inject_dummy_credentials`, `auto_inject_dummy_bearer` (the legacy key's behaviour becomes the value `bearer`) |
| `laravel.route_parity` | Laravel `route_parity` |
| — removed — | `enum_spec_base_path` (#508) |

`baseline` and `baseline_stale` are top-level keys rather than a `baseline`
section with four leaves: under rule 1 the array must transcribe #502's two string
keys, and grouping by baseline instead of by attribute would make the array and
the string disagree about their own shape.

## The v3 CLI

`coverage:merge` goes from 22 flags to 17. No output format is removed and no
combination becomes unexpressible.

| v3 flag | Replaces |
| --- | --- |
| `--format=<name>` | (new on merge; hard-wired per sink today) |
| `--output-file=<path>` | `--output-file` (Markdown-only today) |
| `--report=<format>:<path>`, repeatable | `--json-output`, `--junit-output`, `--html-output` |
| `--console-report=<mode>` | `--console-output` |
| `--min-coverage="endpoint=…,response=…,sdk_exercise=…,strict"` | `--min-endpoint-coverage`, `--min-response-coverage`, `--min-sdk-exercise-coverage`, `--min-coverage-strict` |
| `--baseline="violations=…,coverage=…"` | `--baseline-file`, `--coverage-baseline-file` |
| `--baseline-stale="violations=…,coverage=…"` | `--coverage-baseline-stale` (the violations side has no flag today) |
| `--strict-required="run=…,per_call=…"` | `--strict-required` |
| `--strict-additional-properties="run=…,per_call=…"` | `--strict-additional-properties` |
| `--spec-name=<name>`, repeatable | `--specs=<a,b>` |
| `--strip-prefix=<p>`, repeatable | `--strip-prefixes=<a,b>` |
| `--config=<path>` | (new, #501) |
| `--spec-base-path`, `--sidecar-dir`, `--no-cleanup`, `--github-step-summary`, `--help` | unchanged |
| — removed — | `--enum-spec-base-path` (#508) |

`--format` and `--output-file` are accepted by all four subcommands per #507;
`stubs --output` becomes `--output-dir` because it names a directory. The shared
exit-code contract (`0` success, `1` the answer is "no", `2` usage or I/O)
belongs to #507 and is unchanged by this ADR.

## What each issue changes

- **#501** — key set above replaces the sketch: no `spec.enum_base_path`,
  `validation.format` not `validation.output`, a `phpunit` section, and the
  collapsed keys from #502 rather than one key per threshold and baseline.
- **#502** — the collapse survives; its target is the array, and the string
  grammar is normalised to `name=value` with snake_case sub-keys. `--report-output`
  is withdrawn in favour of #507's `--format` / `--output-file` / `--report`.
- **#504** — gains `GESSO_VALIDATION_FORMAT`, and owns all environment-variable
  spellings named anywhere in the v3 set.
- **#507** — `--console-output` is renamed to `--console-report`, not kept; it
  gains the collapsed merge flags from #502.
- **#508** — unchanged in substance; it no longer needs to remove a `gesso.php`
  key, only the extension parameter, the CLI flag, and the loader surface.

## What this ADR does not decide

Deprecation mechanics — which spelling warns, in which release, with what text —
belong to #499, which stages every v3 rename behind a v2 deprecation channel. This
ADR fixes the target names so #499 has something fixed to deprecate toward.

It also decides no PHP symbol name. The `Studio\Gesso\Contract\` move in #503
and the envelope constants in #505 are namespace and wire-format decisions with
no configuration key in common with the four issues above.

## Consequences

The four issues can now be implemented in any order without a rename landing
twice. Their bodies are edited in the same change that adds this ADR, so an
implementer reading one issue is not reading a superseded name.

Two settings gain a rename they did not have before: `validation_output` →
`validation.format` (with `GESSO_VALIDATION_FORMAT`) and the `--console-output`
flag. Both follow from rule 2, and both are cheaper now than after v3.0 ships
them, since each would otherwise need its own major.

The cost is a fifth document to keep current. It is bounded: this ADR names v3
targets only, and once #501, #502, #507 and #508 have merged, the names live in
`docs/setup.md` and `docs/cli.md` and this record is history rather than
specification.
