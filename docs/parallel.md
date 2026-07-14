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
vendor/bin/openapi-coverage-merge \
    --spec-base-path=openapi/bundled \
    --specs=front,admin \
    --output-file=coverage-report.md
```

`vendor/bin/openapi-coverage-merge` flags:

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
| `--min-coverage-strict` | `false` (warn-only) | Treat threshold misses as exit non-zero |
| `--strict-required=<mode>` | `off` | `off` / `warn` / `fail`. Assert no schema under-description drift across worker observations. See [`strict-required.md`](strict-required.md#paratest) |
| `--no-cleanup` | (cleanup is on by default) | Keep sidecar files after merge |

Sidecar dir defaults are deliberately stable — workers and the merge CLI
use the same `sys_get_temp_dir()/openapi-coverage-sidecars` path, so a
trivial CI step has no extra config to keep in sync. Set `sidecar_dir` (in
`phpunit.xml`) and `--sidecar-dir=` (on the merge CLI) to the same custom
path if `sys_get_temp_dir()` is unavailable in your runner.

## Sidecar compatibility

Sidecars are a versioned worker-to-merge protocol, separate from the coverage
report produced by `json_output`. The current writer emits an
`envelopeVersion: 2` envelope containing coverage state `version: 1` and
strict-required state `version: 2`. The merge reader also accepts the older
bare coverage state `version: 1`, so coverage can still be combined while a
worker fleet is being upgraded. That legacy payload has no strict-required
observations, so a strict-required gate cannot be evaluated from it.

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
  observations via the sidecar envelope (v2). The merge CLI's
  `--strict-required` flag decides whether to assert the gate; the
  `strict_required` parameter on the PHPUnit extension does not propagate
  to the merge step. See [`strict-required.md`](strict-required.md#paratest).
- **Worker counts are not exposed by paratest.** A child cannot reliably
  tell how many siblings it has, so the merge has to run as a separate
  step rather than auto-firing from "the last worker." This matches how
  PHPUnit's own coverage merging works (`phpcov merge`).
- **Sidecars are cleaned up by default.** Run with `--no-cleanup` if you
  want to inspect the per-worker JSON for debugging.
- **A failed sidecar write does not fail the test run.** Workers log a
  warning to `STDERR` and let the suite finish — your contract assertions
  already passed; sidecar I/O is a CI artifact concern.
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
  `OpenApiSpecLoader::configure(..., allowRemoteRefs: true, ...)` first
  (e.g. a Composer script), or pre-bundle remote refs offline.
