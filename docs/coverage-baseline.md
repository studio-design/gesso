# Coverage baseline

The [coverage threshold gate](coverage.md#coverage-threshold-gate) answers
"is coverage above N%". That is a poor fit for "coverage must not get worse",
because a percentage has to be maintained by hand:

- setting the threshold below the current rate leaves headroom, so several
  responses can go uncovered without failing;
- setting it exactly at the current rate couples the gate to the
  **denominator** — documenting one new response in the spec fails an
  otherwise unrelated PR, and the message only reports a percentage, not
  which row caused it;
- improving coverage without also raising the threshold silently restores
  the headroom, and CI stays green while the ratchet loosens.

The coverage baseline gates the **set** of uncovered responses instead. It is
the [violation baseline](baseline.md) idea applied to coverage: commit
today's gaps, fail on new ones by name, and let the ratchet show up as a diff.

## Quick start

1. Point the PHPUnit extension at a coverage baseline file:

   ```xml
   <extensions>
       <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
           <parameter name="spec_base_path" value="openapi/bundled"/>
           <parameter name="coverage_baseline_file" value="gesso-coverage-baseline.json"/>
       </bootstrap>
   </extensions>
   ```

2. Generate it from a full run (no `--filter`):

   ```bash
   OPENAPI_BASELINE_GENERATE=1 vendor/bin/phpunit
   ```

   ```text
   [Gesso] Coverage baseline written: 105 uncovered response(s) → /path/to/gesso-coverage-baseline.json
   ```

3. Commit the file. Every later run compares its uncovered set against it.

`OPENAPI_BASELINE_GENERATE` drives both baselines: if `baseline_file` is
configured too, one command regenerates both. Configuring only
`coverage_baseline_file` is fine — the violation baseline stays uninvolved.

The file is deterministic — fully sorted, no timestamps — so regenerating an
unchanged suite produces a byte-identical file. Its format is a versioned
compatibility surface; see
[versioning.md](versioning.md#whats-covered-by-semver).

## What an entry is

One entry per response the run did not validate, at the same granularity
coverage is measured at:

```json
{
    "spec": "front",
    "method": "GET",
    "path": "/v1/pets",
    "status": "500",
    "content_type": "application/json"
}
```

`status` is the spec's response key (`200`, `5XX`, `default`), not an observed
HTTP status. `content_type` is `*` for responses declared without a `content`
block (204-style). Fixed HTTP methods normalize to uppercase; OpenAPI 3.2
custom `additionalOperations` methods stay case-sensitive.

**Skipped responses are baselined too.** A response matched by
`skip_response_codes`, or one whose content type has no schema engine, counts
as not covered — exactly as it does in the `min_response_coverage` numerator.
Including it means switching from the percentage gate to the baseline never
weakens the gate.

## Enforcement semantics

Every full run prints one summary line to stderr:

```text
[Gesso] coverage baseline: 105 entries, 105 uncovered response(s) in this run, 0 covered now.
```

An uncovered response that is **not** in the file fails the run and is named:

```text
[Gesso] FATAL: 2 response(s) are not covered and are not listed in the coverage baseline:
  - [front] DELETE /v1/pets/{id} status=204 content-type=*
  - [front] GET /v1/pets status=500 content-type=application/json
  Action: cover them with a test, or accept them by regenerating the baseline with `OPENAPI_BASELINE_GENERATE=1 vendor/bin/phpunit`.
```

Because the gate compares sets, documenting a new response in the spec does
not fail an unrelated PR — as long as that response is covered. If it is not,
the failure names it and says so, which is the intended behavior arriving for
the intended reason.

The gate is skipped, with a one-line NOTE, when it cannot be evaluated
honestly:

- **Partial runs** (`--filter` / `--testsuite` / path arguments): a subset
  leaves responses uncovered that the full suite covers.
- **Runs that did not complete cleanly** (any failed, errored, skipped, or
  incomplete test, or a truncated `--stop-on-*` run): a test that never
  reached its contract assertion leaves its responses uncovered, and
  reporting those as regressions would bury the real failure.

A run that recorded no contract coverage at all is FATAL rather than a
vacuous pass — an empty uncovered set there means "no data", not "no gaps".

## Ratcheting down

Baseline entries that are covered now — the ratchet-down signal — are
reported per `coverage_baseline_stale`:

| Value | Behavior |
|---|---|
| `note` (default) | Entries are listed on stderr as removable |
| `fail` | Same listing, and the run exits non-zero — CI enforces that the baseline only shrinks |
| `off` | Entries that are covered now are not reported |

```text
[Gesso] NOTE: 1 coverage baseline entry(ies) are covered now and can be removed from the baseline file:
  - [front] GET /v1/pets status=500 content-type=application/json
```

Delete the listed entries, or re-run the generation command — it rewrites the
file to exactly the current gaps. Either way the improvement appears in the
diff, so it cannot be forgotten the way a threshold bump can. Entries for
responses the spec no longer declares are reported the same way, since they
can never be covered again.

## Parallel runners

A paratest worker sees only its slice of the suite, so no worker can decide
whether a response is covered. Workers therefore neither generate nor enforce;
the merge step owns both halves, reading the same merged coverage state it
already renders reports from:

```bash
# Generate
OPENAPI_BASELINE_GENERATE=1 vendor/bin/paratest --processes=4
OPENAPI_BASELINE_GENERATE=1 vendor/bin/gesso coverage:merge \
    --spec-base-path=openapi/bundled \
    --coverage-baseline-file=gesso-coverage-baseline.json

# Enforce
vendor/bin/paratest --processes=4
vendor/bin/gesso coverage:merge \
    --spec-base-path=openapi/bundled \
    --coverage-baseline-file=gesso-coverage-baseline.json \
    --coverage-baseline-stale=fail
```

The merge exits 1 when a response is newly uncovered, when no sidecars were
found, when no coverage was recorded, or when the baseline file cannot be
read; it exits 2 for usage errors (an unknown `--coverage-baseline-stale`
value, or that flag without `--coverage-baseline-file`).

Unlike the sequential path, the merge has no view of whether the suite passed,
so a red parallel run can report responses of failed tests as newly uncovered.
Check the test run's own exit code first — the same caveat the threshold gate
has here.

## Using it alongside the threshold gate

They are independent and can both be configured, but the baseline is the
stronger of the two for regression protection. `min_endpoint_coverage` and
`min_sdk_exercise_coverage` still measure things the coverage baseline does
not, so keeping those while dropping `min_response_coverage` is a reasonable
setup.
