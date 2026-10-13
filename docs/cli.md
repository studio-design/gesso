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
- On `doctor`, `coverage:gate` and `stubs`, an `--output-file` that cannot be
  written is a usage error: exit `2`, the message
  `Cannot write --output-file: <path>`, then the usage text. On
  `coverage:merge` the failure is reported as
  `[OpenAPI Coverage] FATAL: Failed to write … report to …` and exits `1`, the
  same as its other report files.
- `text` is the command's normal stdout rendering. On `coverage:merge` that
  is the console summary, and `--console-output` controls it.

### Merge output

| `--format` | `--output-file` | stdout | file |
|---|---|---|---|
| absent | absent | console summary | — |
| absent | given | console summary | Markdown |
| `text` | absent | console summary | — |
| `markdown` / `json` / `junit` / `html` | absent | that rendering instead of the console summary | — |
| any | given | console summary | that rendering (`text` writes the console summary) |

`--junit-output`, `--json-output`, `--html-output` and `--github-step-summary`
still work and can be used together with the primary report. When the merge
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

## Exit codes

All four commands use `0` for success, `1` for a "no" answer, and `2` for a
usage error. What counts as `1` differs by command:

| Command | `0` | `1` | `2` |
|---|---|---|---|
| `doctor` | Every spec is compatible. Warnings may be present. | A spec cannot be loaded or enforced. | Invalid usage, or an unwritable `--output-file`. |
| `coverage:merge` | The merge completed. Warnings may be on stderr. | A gate failed (threshold, strict mode, baseline, or expected shard count), a sidecar could not be read, or a report could not be written. | Invalid usage. |
| `coverage:gate` | Every changed operation is covered. | A changed operation has a response that no test validated. | Invalid usage, an unreadable spec or coverage file, or an unwritable `--output-file`. |
| `stubs` | Stubs were written, or there was nothing left to stub. | — | Invalid usage, an unreadable input, or an output file that cannot be written. |

The v3 milestone moves the I/O failures of `coverage:merge` from `1` to `2`, so
all four commands handle them the same way. See
[ADR 0005](adr/0005-v3-configuration-and-cli-naming.md).
