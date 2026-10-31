# CLI reference

`vendor/bin/gesso` is installed by Composer. This page covers the rules its
subcommands share. Each command's own page has its full option list.

## Commands

| Command | Purpose | Guide |
|---|---|---|
| `gesso doctor` | Check whether Gesso can load and enforce a spec. | [Doctor](doctor.md) |
| `gesso coverage:merge` | Combine parallel-runner sidecars into one coverage report. | [Parallel runners](parallel.md) |
| `gesso coverage:gate` | Fail when a spec change touches an operation that no test covers. | [Coverage gate](coverage-gate.md) |
| `gesso stubs` | Write test stubs for the responses that no test covers. | [Stubs](stubs.md) |

`gesso --help` lists the commands, and `gesso --version` prints the installed
version. `--help` on a subcommand prints that command's usage, including its exit codes.

## Output: --format and --output-file

Every subcommand takes `--format` to choose how its primary report is rendered.
It also takes `--output-file` to write that report to a file instead of stdout.

| Subcommand | `--format` values | Default | What `--output-file` holds |
|---|---|---|---|
| `doctor` | `text`, `json` | `text` | the doctor report |
| `coverage:gate` | `text`, `markdown`, `json` | `text` | the gate report |
| `stubs` | `text`, `json` | `text` | the run summary (written, skipped, and not-stubbed files) |
| `coverage:merge` | `text`, `markdown`, `json`, `junit`, `html` | `text`; `markdown` when `--output-file` is given | the primary report |

These rules apply to all four:

- Without `--output-file`, the primary report goes to stdout. Its bytes are
  the same as the file would get.
- Any other `--format` value is a usage error: exit `2`, the message
  `Unsupported --format=<value>.`, then the usage text.
- The parent directory of `--output-file` must already exist. Gesso does not
  create it, and the other report sinks work the same way.
- An `--output-file` that cannot be written exits `2`. On `doctor`,
  `coverage:gate` and `stubs` the message is
  `Cannot write --output-file: <path>`, then the usage text. On
  `coverage:merge` the failure is reported as
  `[OpenAPI Coverage] FATAL: Failed to write … report to …`, the same as its
  other report files.
- `text` is the command's normal stdout rendering. On `coverage:merge` that
  is the console summary, and `--console-report` controls it.

### Merge output

| `--format` | `--output-file` | stdout | file |
|---|---|---|---|
| absent | absent | console summary | — |
| absent | given | console summary | Markdown |
| `text` | absent | console summary | — |
| `markdown` / `json` / `junit` / `html` | absent | that rendering instead of the console summary | — |
| any | given | console summary | that rendering (`text` writes the console summary) |

`--report=<format>:<path>` (repeatable) and `--github-step-summary` write
further reports alongside the primary one. When the merge
ends early with nothing to report, stdout stays empty under every `--format`
and the stderr line is the whole report. That happens when there are no
sidecars, or when the sidecars hold no observations.

### Gate JSON

```json
{
    "schemaVersion": 1,
    "tool": { "name": "studio-design/gesso", "version": "2.4.0" },
    "summary": { "operations": 1, "uncovered": 1 },
    "operations": [
        {
            "endpoint": "DELETE /pets/{id}",
            "change": "added",
            "responses": [
                { "status": "204", "content_type": "*", "change": "added", "state": "uncovered", "covered": false }
            ]
        }
    ]
}
```

`change` is `added`, `changed`, or `removed`. `state` is `validated`,
`skipped`, `uncovered`, or `removed`.

### stubs JSON

```json
{
    "schemaVersion": 1,
    "tool": { "name": "studio-design/gesso", "version": "2.4.0" },
    "dry_run": false,
    "output_dir": "tests/Contract",
    "written": ["tests/Contract/GetPetsTest.php"],
    "skipped": [],
    "not_stubbed": [
        { "reason": "unreachable", "detail": "GET /things  200 application/*" }
    ]
}
```

`written` and `skipped` hold the same paths that the text summary prints.
`not_stubbed[].reason` is `unreachable`, `malformed`, or `unsupported`.

In both documents, `tool` follows the same `unknown` rule as the
[doctor](doctor.md#machine-readable-output) and
[coverage](coverage-json-schema.md) JSON. Additive changes keep
`schemaVersion` at `1`. Removals, renames and type changes increase it.

## Defaults from gesso.php: --config

`doctor`, `coverage:merge` and `coverage:gate` take `--config=<path>`: a
[`gesso.php`](configuration.md) to default their options from. Without the
flag they read `./gesso.php` when it exists. An explicit flag wins over the
file. A `--config` that names a missing or invalid file, or `--config=` with no
path, is a usage error (exit `2`); an absent `./gesso.php` changes nothing.

| Command | Options defaulted from the file | Guide |
|---|---|---|
| `doctor` | `--spec` (from `spec.base_path` + `spec.names`), `--strip-prefix`, `--acknowledge-unvalidatable-scheme` | [Doctor](doctor.md#read-settings-from-gessophp) |
| `coverage:merge` | the `spec.*`, `coverage.*`, `strict.*.run`, `baseline.*` and `baseline_stale.coverage` options | [Parallel runners](parallel.md) |
| `coverage:gate` | `--coverage` (from `coverage.report_output.json`) | [Coverage gate](coverage-gate.md) |

## Removed flags

Gesso 3.0 removed the v2 spellings below ([ADR 0005](adr/0005-v3-configuration-and-cli-naming.md)).
Gesso 2.9 accepted them with a `[Gesso deprecation]` line on stderr. Gesso 3.0
rejects them as a usage error (exit `2`), and the message names the
replacement, for example
`Unknown argument(s): --specs (removed in Gesso 3.0; use --spec-name)`.

| Command | Removed v2 spelling | v3 spelling |
|---|---|---|
| `coverage:merge` | `--specs=<a,b>` | `--spec-name=<name>`, repeatable or comma-separated |
| `coverage:merge` | `--strip-prefixes=<a,b>` | `--strip-prefix=<prefix>`, repeatable or comma-separated |
| `coverage:merge` | `--json-output=<path>`, `--junit-output=<path>`, `--html-output=<path>` | `--report=json:<path>`, `--report=junit:<path>`, `--report=html:<path>`; repeatable |
| `coverage:merge` | `--console-output=<mode>` | `--console-report=<mode>` |
| `coverage:merge` | `--min-endpoint-coverage`, `--min-response-coverage`, `--min-sdk-exercise-coverage`, `--min-coverage-strict` | `--min-coverage="endpoint=…,response=…,sdk_exercise=…,strict"` |
| `coverage:merge` | `--baseline-file=<path>`, `--coverage-baseline-file=<path>` | `--baseline="violations=…,coverage=…"` |
| `coverage:merge` | `--coverage-baseline-stale=<mode>` | `--baseline-stale="coverage=…"` |
| `coverage:merge` | `--strict-required=<mode>`, `--strict-additional-properties=<mode>` | `--strict-required="run=…"`, `--strict-additional-properties="run=…"` |
| `stubs`, `gesso:stubs` | `--output=<dir>` | `--output-dir=<dir>` |

In the `name=value` flags, members are separated by commas; a comma that is not followed by a member name stays part of the value, so paths may contain one. A blank value is a usage error. Name only the
members you need. `strict` alone means `strict=true`. An unknown member, or a
member without a value, is a usage error. `--strict-*` also accepts
`per_call=off|warn`, which the merge validates but does not use, because the
workers check per-call findings. The PHPUnit extension does not accept this
`name=value` string: configure the workers with `strict.required` /
`strict.additional_properties` (`run`, `per_call`) in
[`gesso.php`](configuration.md), or with the deprecated individual parameters
(`strict_required`, `strict_required_per_call`, and the
`strict_additional_properties*` pair). `--baseline-stale` takes only `coverage`, because the
merge does not evaluate the staleness of the violation baseline.

Every command rejects an argument it does not recognise with exit `2`. Before
Gesso 3.0, `coverage:merge` ignored one with a warning.

## Exit codes

All four commands use `0` for success, `1` for a "no" answer, and `2` for a
usage error. What counts as `1` differs by command:

| Command | `0` | `1` | `2` |
|---|---|---|---|
| `doctor` | Every spec is compatible. Warnings may be present. | A spec cannot be loaded or enforced. | Invalid usage, or an unwritable `--output-file`. |
| `coverage:merge` | The merge completed. Warnings may be on stderr. | A gate failed (threshold, strict mode, baseline, or expected shard count), or a worker wrote a failure marker. | Invalid usage, or an I/O failure (see below). |
| `coverage:gate` | Every changed operation is covered. | A changed operation has a response that no test validated. | Invalid usage, an unreadable spec or coverage file, or an unwritable `--output-file`. |
| `stubs` | Stubs were written, or there was nothing left to stub. | Reserved; not returned. | Invalid usage, an unreadable input, or an output file that cannot be written. |

An I/O failure of `coverage:merge` is a sidecar that cannot be read, a report
or baseline file that cannot be written, a coverage baseline that cannot be
loaded, or a sidecar directory that cannot be listed or inspected for cleanup.
It exits `2`, as in the other commands, even when the failed step also feeds a
gate. Gesso 2.x exited `1` for these. A failed gate, a worker failure marker,
and a shard-count mismatch exit `1`.
