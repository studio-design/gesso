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
   no `GITHUB_STEP_SUMMARY` append from the worker. `GESSO_SIDECAR_TOKEN`
   requests the same thing explicitly, for runners nothing detects; see
   [Sharded CI jobs](#sharded-ci-jobs).
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
    --spec-name=front,admin \
    --output-file=coverage-report.md
```

`vendor/bin/gesso coverage:merge` flags:

| Flag | Default | Description |
|---|---|---|
| `--spec-base-path=<path>` | — (required) | Path to bundled spec directory |
| `--spec-name=<name>` | `front` | Spec name; repeatable or comma-separated |
| `--strip-prefix=<prefix>` | — | Request-path prefix to strip; repeatable or comma-separated |
| `--sidecar-dir=<path>` | `sys_get_temp_dir()/openapi-coverage-sidecars` | Where workers wrote sidecars |
| `--format=<name>` | `text`; `markdown` with `--output-file` | `text` / `markdown` / `json` / `junit` / `html`. Rendering of the primary report. See [CLI reference](cli.md#merge-output) |
| `--output-file=<path>` | — | Primary report output path: the `--format` rendering, Markdown by default |
| `--report=<format>:<path>` | — | Also write a `json`, `junit` or `html` report to `<path>`; repeatable. JSON schema: [`coverage-json-schema.md`](coverage-json-schema.md); JUnit: [Coverage output formats](ci.md#coverage-output-formats); HTML: [`coverage-html-output.md`](coverage-html-output.md) |
| `--github-step-summary=<path>` | `$GITHUB_STEP_SUMMARY` | Append Markdown report to this file |
| `--console-report=<mode>` | `default` | `default` / `all` / `uncovered_only` |
| `--min-coverage="endpoint=<pct>,response=<pct>,sdk_exercise=<pct>,strict"` | — (warn-only) | Threshold gates (see [Coverage threshold gate](coverage.md#coverage-threshold-gate)): fully-covered endpoints, `(method, path, status, content-type)` responses, and eligible response schemas attempted by SDK decoder callbacks. `strict` (or `strict=true`) makes a miss exit non-zero. Name only the members you need |
| `--strict-required="run=<mode>"` | `off` | `off` / `warn` / `fail`. Assert no schema under-description drift across worker observations. A `per_call=off\|warn` member is accepted and left to the workers. See [`strict-required.md`](strict-required.md#paratest) |
| `--strict-additional-properties="run=<mode>"` | `off` | `off` / `warn` / `fail`. Report returned response properties absent from schema declarations. See [`strict-additional-properties.md`](strict-additional-properties.md#parallel-test-runners) |
| `--baseline="violations=<path>,coverage=<path>"` | — | `violations`: union the violation-baseline halves staged by a `GESSO_BASELINE_GENERATE=1` parallel run and write the merged baseline ([`baseline.md`](baseline.md#generating-under-parallel-runners)). `coverage`: gate the merged coverage against a committed set of known-uncovered responses, or write the file when `GESSO_BASELINE_GENERATE=1` is set ([`coverage-baseline.md`](coverage-baseline.md#parallel-runners)) |
| `--baseline-stale="coverage=<mode>"` | `note` | `off` / `note` / `fail`. How coverage-baseline entries that are covered now are reported |
| `--expect-sidecars=<n>` | — | Fail unless exactly `n` sidecars are present. See [Sharded CI jobs](#sharded-ci-jobs) |
| `--run-id=<id>` | `$GESSO_RUN_ID`, else the run that wrote the newest sidecar | Merge only this run's sidecars; other runs' files are listed in a NOTE and left in place. See [Stale sidecars](#stale-sidecars-from-earlier-runs) |
| `--no-cleanup` | (cleanup is on by default) | Keep sidecar files after merge |
| `--config=<path>` | `./gesso.php` when present | [`gesso.php`](configuration.md) to default the options above from |

Without flags, the merge reads `./gesso.php` (or `--config`) and takes the
same keys the PHPUnit extension does: `spec.*`, `coverage.*`, `strict.*.run`,
`baseline.coverage` and `baseline_stale.coverage`, plus `baseline.violations`
on a `GESSO_BASELINE_GENERATE=1` run (the only time the merge writes it). Paths
in the file resolve against its directory. `coverage.report_output.markdown`
defaults `--output-file` only when `--format` is not given, so `--format=json`
still prints to stdout. An explicit flag wins over the file; `GESSO_CONSOLE_OUTPUT`
still wins over both.

Sidecar dir defaults are deliberately stable — workers and the merge CLI
use the same `sys_get_temp_dir()/openapi-coverage-sidecars` path, so a
trivial CI step has no extra config to keep in sync. Set `sidecar_dir` (in
`phpunit.xml`) and `--sidecar-dir=` (on the merge CLI) to the same custom
path if `sys_get_temp_dir()` is unavailable in your runner.

On POSIX systems, Gesso creates a missing sidecar directory with mode `0700`
and publishes sidecars and worker-failure markers with mode `0600`. For
local-user safety, both workers and the merge command reject a `sidecar_dir`
that is itself a symbolic link, is writable by group or other users, or is
owned by a different user than the one running them. The merge command also
rejects symbolic-link sidecar files instead of following them, and requires
each sidecar to be non-group/world-writable and owned by the current user. The
permission-bit and ownership checks are not applied on Windows, whose ACL model
is not represented by POSIX mode bits; the ownership check also needs the
`posix` extension. Use a dedicated directory rather than `/tmp` itself; the
default already does this.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Merge completed; warnings may have been written to stderr. |
| `1` | A gate failed (threshold, strict, baseline, expected shard count), a sidecar could not be read, or a report could not be written. |
| `2` | Command-line usage is invalid. |

Shared conventions: [CLI reference](cli.md#exit-codes).

## Sharded CI jobs

Splitting a suite across CI *jobs* — one runner per testsuite, to keep each
job inside a time budget — puts every runner in the same position as a
paratest worker: it holds one slice of the state and nothing else can see it.
Nothing in the environment says so, though, so each job has to name itself:

```yaml
strategy:
  matrix:
    suite: [Unit, Feature, E2E]
steps:
  - run: vendor/bin/phpunit --testsuite=${{ matrix.suite }}
    env:
      GESSO_SIDECAR_TOKEN: ${{ matrix.suite }}
  - uses: actions/upload-artifact@v4
    with:
      name: gesso-sidecars-${{ matrix.suite }}
      path: /tmp/openapi-coverage-sidecars
```

`GESSO_SIDECAR_TOKEN` does exactly what `TEST_TOKEN` does for paratest — the
process writes its sidecar and renders nothing — and its value names the
sidecar (`part-<token>-<pid>[.<run-id>].json`), so artifacts downloaded from
several runners into one directory cannot collide. It takes priority over
`TEST_TOKEN`, so a sharded job may still run paratest inside it: the shard
name namespaces the files and the pid keeps that job's workers apart. A blank
or unset value leaves the run rendering in process, unchanged.

Optionally set `GESSO_RUN_ID: ${{ github.run_id }}-${{ github.run_attempt }}`
on the shard jobs *and* the merge job. It scopes every sidecar to this workflow
run, so a re-run that downloads into a directory with older artifacts merges
only its own. Without it all shards form one unscoped group, as before; setting
it on only some shards splits the fleet, so set it everywhere or nowhere.

The merge job then downloads every artifact into one directory and produces
the single report and the single gate evaluation for the whole suite:

```bash
vendor/bin/gesso coverage:merge \
    --spec-base-path=openapi/bundled \
    --sidecar-dir=sidecars \
    --expect-sidecars=3 \
    --strict-required=fail \
    --min-coverage="response=80"
```

`--expect-sidecars=<n>` is worth setting whenever the shard count is known up
front. A shard that was cancelled — or that died before PHPUnit finished —
writes neither a sidecar nor a `failed-*.json` marker, because the marker only
covers "ran but could not write". The declared count is the only evidence that
absence leaves, and without it the merge reports the surviving shards' coverage
as if it were the whole suite. A mismatch in either direction is FATAL (exit 1)
and leaves the sidecars in place. Only the selected run's sidecars are counted
(see [Stale sidecars](#stale-sidecars-from-earlier-runs)), so a surplus means
the run itself produced more files than declared.

### Stale sidecars from earlier runs

Every sidecar and failure marker carries the id of the run that wrote it:
`GESSO_RUN_ID` when set, otherwise the paratest master's pid (every paratest or
Pest `--parallel` worker is a direct child of it). `coverage:merge` selects one
run — `--run-id`, then `GESSO_RUN_ID`, then the run that wrote the newest file —
and applies the failure-marker check and `--expect-sidecars` to that run only.
Files from other runs are named in one `[Gesso] NOTE` and are neither merged
nor deleted; cleanup removes only what was merged, and a failed gate keeps the
selected run's files so re-running the merge gates the same run rather than
falling back to an older one. A failure marker without a
run id (written by a worker on an older release) cannot be attributed to a run,
so it still fails every merge. A crashed or merge-less
earlier run therefore no longer poisons the next merge, and the sidecar
directory does not need to be emptied at the start of CI.

Two limits: concurrent runs sharing one directory must each set
`GESSO_RUN_ID`, since "newest" is a heuristic for runs that follow each other
(two runs whose newest files carry the same second fail the merge with a
request for `--run-id` rather than a guess); and without `ext-posix` (Windows) and without `GESSO_RUN_ID`, sidecars are
unscoped and every file in the directory counts, as in earlier releases.

Each shard is a partial run on its own, which is why none of them writes
`output_file` / `json_output` / `GITHUB_STEP_SUMMARY` or evaluates a gate — see
[Partial test runs](ci.md#partial-test-runs-filter-testsuite-path-args). The
merge step is the full-suite view, and
it owns those decisions for the whole matrix.

## Sidecar compatibility

Sidecars are a versioned worker-to-merge protocol, separate from the coverage
report produced by `json_output`. The current writer emits
`envelopeVersion: 10`: `coverage` is required, and `strictRequired`,
`strictAdditionalProperties`, `sdkExercise`, `deprecations` and `baseline` are
optional keys whose absence means the worker had no such tracker. Only a
baseline-generation run (`GESSO_BASELINE_GENERATE=1`) writes `baseline`.

The merge reader also accepts the legacy envelopes 2–9 and the older bare
coverage state `version: 1`, so HTTP coverage can still be combined while a
worker fleet is being upgraded. A missing half is reported, not treated as
empty: a strict SDK exercise threshold requires every worker to carry SDK
exercise state (warn-only mode reports the missing workers and evaluates the
available observations), and `--baseline="violations=…"` requires every worker to carry
the baseline half.

Deprecation counts travel the same way. A worker never reaches the end-of-run
report, so it stages its per-id counts in the envelope and `coverage:merge`
writes the one summed `[Gesso deprecation]` line — silence there means no
worker used a deprecated surface. When some sidecar predates the deprecation
channel the merge says so instead, because a missing half and "this worker used
none" are the same absence.

Unknown envelope or tracker versions fail the merge rather than being guessed.
Strict-required state `version: 1` is also rejected because merging it with the
current nested-pointer shape would silently lose information. Keep workers on
one version when using the strict-required gate. See the complete
[versioning policy](versioning.md#versioned-sidecar-compatibility) before
changing a sidecar shape or filename pattern.

## Notes

- **Sequential runs are unchanged.** Without `TEST_TOKEN` or
  `GESSO_SIDECAR_TOKEN` the extension renders inline as before. There is no
  need to wire the merge CLI into non-parallel CI jobs.
- **Pest plugin works under `--parallel`.** The expectations registered
  by the [Pest plugin](pest-plugin.md) record coverage through the
  same `OpenApiCoverageTracker` static, so each Pest worker drops a
  sidecar exactly like a paratest worker would. No additional wiring
  needed beyond the merge step shown above. Pest seals PHPUnit's event
  system before loading extensions in its parallel orchestrator, so the
  extension prints one `[Gesso] NOTE` there and registers nothing in that
  process; it runs no tests, and the workers report as usual.
- **`strict_required` aggregates across workers.** Workers always export
  observations via the versioned sidecar envelope. The merge CLI's
  `--strict-required` flag decides whether to assert the gate; the
  `strict_required` parameter on the PHPUnit extension does not propagate
  to the merge step. See [`strict-required.md`](strict-required.md#paratest).
- **Enum drift is evaluated once, by the process that starts the run.**
  paratest bootstraps the extension in its master before spawning workers
  (Pest `--parallel` does the same in its orchestrator). That process runs
  the `enum_drift_*` scan and writes the single stderr /
  `GITHUB_STEP_SUMMARY` block; a strict drift there aborts the run before any
  worker starts. Workers (`TEST_TOKEN` set) skip the scan, and
  `gesso coverage:merge` plays no part. `GESSO_SIDECAR_TOKEN` shards are
  separate jobs and each evaluates once, as a sequential run does. A
  sequential run whose environment pre-sets `TEST_TOKEN` is treated as a
  worker and skips the scan too — unset `TEST_TOKEN`, or name shards with
  `GESSO_SIDECAR_TOKEN` instead.
- **Baseline generation aggregates across workers too.** An
  `GESSO_BASELINE_GENERATE=1` parallel run stages fingerprints in the
  sidecars; pass `--baseline="violations=<path>"` to the merge to write the union.
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
  baseline-generation run (`GESSO_BASELINE_GENERATE=1`): the worker
  demoted its failures on the promise that the merge unions its sidecar, so
  losing the sidecar fails the worker — otherwise the merge could write an
  incomplete baseline from the remaining workers.
- **Stale sidecars across runs are ignored.** The merge picks one run and
  leaves other runs' files alone; see
  [Stale sidecars](#stale-sidecars-from-earlier-runs). Delete the files the
  NOTE lists by hand when you no longer need them.
- **Worker write failures fail the merge loudly.** When a worker can't
  persist its sidecar, it drops a `failed-<token>-<pid>[.<run-id>].json` marker. The merge
  CLI exits non-zero (`FATAL`) when any markers are present, since a missing
  worker would silently under-count coverage.
- **HTTP `$ref` auto-resolution from the merge CLI.** The CLI calls
  `OpenApiSpecLoader::configure()` with only `spec_base_path` and
  `strip_prefixes` — `allowRemoteRefs` cannot be set via CLI flags. If your
  spec uses HTTP(S) `$ref`, run the merge step from a process that calls
  `OpenApiSpecLoader::configure(..., allowRemoteRefs: true,
  allowedRemoteRefHosts: ['specs.example.com'], ...)` first
  (e.g. a Composer script), or pre-bundle remote refs offline.
