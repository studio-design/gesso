# Parallel test runners (paratest / Pest `--parallel`)

Coverage state is per-process. Under parallel runners — `brianium/paratest` or
`pest --parallel` (which delegates to paratest) — each worker boots its own
PHPUnit, runs a slice of the suite, and would otherwise emit its own slice
report. Without coordination the `output_file` ends up containing whichever
worker finished last, and the `GITHUB_STEP_SUMMARY` ends up with N partial
reports stacked on top of each other.

The coverage extension solves this with a two-step workflow that mirrors
`phpunit/php-code-coverage`:

1. **Workers** drop a JSON sidecar per process. The extension auto-detects
   paratest by looking at `TEST_TOKEN` (set in every paratest child) and
   short-circuits rendering — no console output, no `output_file` write,
   no `GITHUB_STEP_SUMMARY` append from the worker.
2. **A single merge step** reads the sidecars, union-merges them via the
   same rules `OpenApiCoverageTracker::recordResponse()` applies, and emits
   the combined report.

## Workflow

```bash
# 1. Run tests in parallel — workers write sidecars only.
vendor/bin/pest --parallel --processes=4
# (or `vendor/bin/paratest --processes=4`)

# 2. Merge sidecars into a single coverage report.
vendor/bin/gesso coverage:merge \
    --spec-base-path=openapi/bundled \
    --specs=front,admin \
    --output-file=coverage-report.md
```

`vendor/bin/gesso coverage:merge` flags:

| Flag | Default | Description |
|---|---|---|
| `--spec-base-path=<path>` | — (required) | Path to bundled spec directory |
| `--specs=<a,b>` | `front` | Comma-separated spec names |
| `--strip-prefixes=<a,b>` | — | Comma-separated request-path prefixes to strip |
| `--sidecar-dir=<path>` | `sys_get_temp_dir()/openapi-coverage-sidecars` | Where workers wrote sidecars |
| `--output-file=<path>` | — | Markdown report output path |
| `--junit-output=<path>` | — | JUnit XML report output path (CI dashboards). See [Coverage output formats](ci.md#coverage-output-formats) |
| `--json-output=<path>` | — | Machine-readable JSON report output path. Schema: [`coverage-json-schema.md`](coverage-json-schema.md) |
| `--html-output=<path>` | — | Self-contained HTML report output path. See [`coverage-html-output.md`](coverage-html-output.md) |
| `--github-step-summary=<path>` | `$GITHUB_STEP_SUMMARY` | Append Markdown report to this file |
| `--console-output=<mode>` | `default` | `default` / `all` / `uncovered_only` |
| `--min-endpoint-coverage=<pct>` | — | Threshold gate (see [Coverage threshold gate](coverage.md#coverage-threshold-gate)) |
| `--min-response-coverage=<pct>` | — | Threshold gate at `(method, path, status, content-type)` granularity |
| `--min-sdk-exercise-coverage=<pct>` | — | Threshold gate for eligible response schemas attempted by SDK decoder callbacks |
| `--min-coverage-strict` | `false` (warn-only) | Treat threshold misses as exit non-zero |
| `--strict-required=<mode>` | `off` | `off` / `warn` / `fail`. Assert no schema under-description drift across worker observations. See [`strict-required.md`](strict-required.md#paratest) |
| `--strict-additional-properties=<mode>` | `off` | `off` / `warn` / `fail`. Report returned response properties absent from schema declarations. See [`strict-additional-properties.md`](strict-additional-properties.md#parallel-test-runners) |
| `--baseline-file=<path>` | — | Union the violation-baseline halves staged by an `OPENAPI_BASELINE_GENERATE=1` parallel run and write the merged baseline. See [`baseline.md`](baseline.md#generating-under-parallel-runners) |
| `--no-cleanup` | (cleanup is on by default) | Keep sidecar files after merge |

Sidecar dir defaults are deliberately stable — workers and the merge CLI
use the same `sys_get_temp_dir()/openapi-coverage-sidecars` path, so a
trivial CI step has no extra config to keep in sync. Set `sidecar_dir` (in
`phpunit.xml`) and `--sidecar-dir=` (on the merge CLI) to the same custom
path if `sys_get_temp_dir()` is unavailable in your runner.

On POSIX systems, Gesso creates a missing sidecar directory with mode `0700`
and publishes sidecars and worker-failure markers with mode `0600`. For
local-user safety, both workers and the merge command reject a `sidecar_dir`
that is itself a symbolic link or is writable by group or other users. The
merge command also rejects symbolic-link sidecar files instead of following
them, and requires each sidecar to be non-group/world-writable. The
permission-bit checks are not applied on Windows, whose ACL model is not
represented by POSIX mode bits. Use a dedicated directory rather than `/tmp`
itself; the default already does this.

## Sidecar compatibility

Sidecars are a versioned worker-to-merge protocol, separate from the coverage
report produced by `json_output`. The current writer emits an
`envelopeVersion: 6` envelope containing coverage state `version: 1`,
strict-required state `version: 2`, and strict-additional-properties state
`version: 1`, plus SDK exercise state `version: 1`. A baseline-generation run
(`OPENAPI_BASELINE_GENERATE=1`) emits `envelopeVersion: 7` and adds the violation-baseline document
(`baseline_version: 1`).

The merge reader also accepts envelopes 2–5 and the older bare coverage state
`version: 1`, so HTTP coverage can still be combined while a worker fleet is
being upgraded. Envelopes 2/3 have no strict-additional-properties state, and
envelopes 2–5 have no SDK exercise state. A strict SDK exercise threshold
therefore requires every worker on v6/v7; warn-only mode reports missing
worker state and evaluates the available SDK observations. Likewise, plain
envelopes carry no baseline data, so `--baseline-file` requires every worker
to use a corresponding baseline envelope.

Unknown envelope or tracker versions fail the merge rather than being guessed.
Strict-required state `version: 1` is also rejected because merging it with the
current nested-pointer shape would silently lose information. Keep workers on
one version when using the strict-required gate. See the complete
[versioning policy](versioning.md#versioned-sidecar-compatibility) before
changing a sidecar shape or filename pattern.

## Notes

- **Sequential runs are unchanged.** Without `TEST_TOKEN` the extension
  renders inline as before. There is no need to wire the merge CLI into
  non-parallel CI jobs.
- **Pest plugin works under `--parallel`.** The expectations registered
  by the [Pest plugin](pest-plugin.md) record coverage through the
  same `OpenApiCoverageTracker` static, so each Pest worker drops a
  sidecar exactly like a paratest worker would. No additional wiring
  needed beyond the merge step shown above.
- **`strict_required` aggregates across workers.** Workers always export
  observations via the versioned sidecar envelope. The merge CLI's
  `--strict-required` flag decides whether to assert the gate; the
  `strict_required` parameter on the PHPUnit extension does not propagate
  to the merge step. See [`strict-required.md`](strict-required.md#paratest).
- **Baseline generation aggregates across workers too.** An
  `OPENAPI_BASELINE_GENERATE=1` parallel run stages fingerprints in the
  sidecars; pass `--baseline-file=<path>` to the merge to write the union.
  See [`baseline.md`](baseline.md#generating-under-parallel-runners).
- **Worker counts are not exposed by paratest.** A child cannot reliably
  tell how many siblings it has, so the merge has to run as a separate
  step rather than auto-firing from "the last worker." This matches how
  PHPUnit's own coverage merging works (`phpcov merge`).
- **Sidecars are cleaned up by default.** Run with `--no-cleanup` if you
  want to inspect the per-worker JSON for debugging.
- **A failed sidecar write does not fail the test run.** Workers log a
  warning to `STDERR` and let the suite finish — your contract assertions
  already passed; sidecar I/O is a CI artifact concern. The exception is a
  baseline-generation run (`OPENAPI_BASELINE_GENERATE=1`): the worker
  demoted its failures on the promise that the merge unions its sidecar, so
  losing the sidecar fails the worker — otherwise the merge could write an
  incomplete baseline from the remaining workers.
- **Stale sidecars across runs.** Cleanup-on-success removes sidecars after
  every successful merge. If a previous run crashed before the merge step,
  any leftover sidecars in the dir will be picked up by the next merge —
  delete the sidecar dir at the start of CI if you can't trust the previous
  run's exit code.
- **Worker write failures fail the merge loudly.** When a worker can't
  persist its sidecar, it drops a `failed-<token>.json` marker. The merge
  CLI exits non-zero (`FATAL`) when any markers are present, since a missing
  worker would silently under-count coverage.
- **HTTP `$ref` auto-resolution from the merge CLI.** The CLI calls
  `OpenApiSpecLoader::configure()` with only `spec_base_path` and
  `strip_prefixes` — `allowRemoteRefs` cannot be set via CLI flags. If your
  spec uses HTTP(S) `$ref`, run the merge step from a process that calls
  `OpenApiSpecLoader::configure(..., allowRemoteRefs: true,
  allowedRemoteRefHosts: ['specs.example.com'], ...)` first
  (e.g. a Composer script), or pre-bundle remote refs offline.
