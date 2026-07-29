# Violation baseline

Adopting contract testing on an existing API usually fails at the first run:
hundreds of known spec/implementation mismatches, all failing at once. The
violation baseline converts that into a one-PR adoption, the same way PHPStan
and Psalm baselines work: record today's violations in a committed file, keep
CI green, and fail only on **new** violations. The debt stays enumerated in
one reviewable file and can be ratcheted down entry by entry.

## Quick start

1. Point the PHPUnit extension at a baseline file:

   ```xml
   <extensions>
       <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
           <parameter name="spec_base_path" value="openapi/bundled"/>
           <parameter name="baseline_file" value="gesso-baseline.json"/>
       </bootstrap>
   </extensions>
   ```

2. Generate the baseline (full suite, no parallelism, no `--filter`):

   ```bash
   OPENAPI_BASELINE_GENERATE=1 vendor/bin/phpunit
   ```

   Every contract violation is recorded instead of failing, and the sorted,
   versioned baseline is written at run end:

   ```text
   [Gesso] Baseline written: 42 violation(s) → /path/to/gesso-baseline.json
   ```

3. Commit `gesso-baseline.json`. From now on every normal run loads it and
   suppresses baselined failures; new violations fail as usual.

The file is deterministic — fully sorted, no timestamps — so regenerating an
unchanged contract produces a byte-identical file and diffs stay reviewable.
Its format is a versioned compatibility surface; see
[versioning.md](versioning.md#whats-covered-by-semver).

## What counts as "the same violation"

Each violation is identified by a fingerprint:
`(spec, method, path template, status, content type, category, parameter,
instance path, keyword)`. Two properties make it survive unrelated change:

- **Message wording is excluded.** Validator prose may improve between
  releases without invalidating the baseline.
- **Numeric JSON Pointer segments are canonicalized to `*`.** The same schema
  defect reported at `/data/0/id` and `/data/3/id` is one entry, regardless
  of test-data size or ordering.

Fixed HTTP methods normalize to their canonical uppercase form (`get` and
`GET` are one entry). OpenAPI 3.2 custom `additionalOperations` methods are
case-sensitive, so `COPY` and `copy` are distinct entries — a baselined
`COPY` violation never absorbs a new `copy` violation.

Non-body violations carry the parameter / response-header / security-scheme
name plus the failing keyword, so a known `limit` violation does not absorb a
future `page` violation, and a known "`limit` missing" does not absorb a
future "`limit` has the wrong type". Violations with neither a name nor a
keyword (structural spec errors, error-boundary captures) collapse per
operation and category — a documented trade-off.

## Enforcement semantics

- A failing assertion is suppressed only when **every** one of its violations
  is baselined. One new violation fails the assertion with the full,
  unmodified error output (including the baselined violations — fix or
  regenerate deliberately, never silently).
- The PSR-7 exchange assertion evaluates each failing side independently and
  passes only when all failing sides are fully baselined.
- A missing or malformed `baseline_file` is FATAL at bootstrap. A typo'd path
  must not silently disable suppression.
- A body that fails to decode as JSON is baselined as a body-category entry
  without matched status/content-type context (the failure happens before
  path matching). When that entry is baselined, validation still continues
  against an absent body so violations elsewhere in the request/response are
  not masked.
- While a baseline is active the `max_errors` cap is lifted: a truncated
  error list could hide a new violation behind baselined ones. Failure output
  may therefore list more errors than the configured cap.

## The end-of-run summary

Every full enforcement run prints one line to stderr:

```text
[Gesso] baseline: 42 entries, 40 hit, 2 stale (removable).
```

`hit` counts baseline entries that occurred during the run; `stale` counts
entries that never occurred — debt that has been fixed and can be removed
from the file.

## Ratcheting down

Stale entries are the ratchet-down signal. The `baseline_stale` parameter
controls how they are reported:

| Value | Behavior |
|---|---|
| `note` (default) | Stale entries are listed on stderr and in the GitHub Actions step summary |
| `fail` | Same listing, and the run exits non-zero — CI enforces that the baseline only shrinks |
| `off` | Stale entries are not evaluated |

To shrink the baseline, delete the listed entries from the file (or re-run
the generation command, which rewrites the file to exactly the current debt).
Hand-edited entries are re-normalized on load, so removing an entry is safe
without regenerating.

Stale evaluation needs a complete, clean run: an entry is only provably
gone when every assertion that could hit it actually ran. It is therefore
skipped — entries/hits are still reported, and the `fail` gate never trips —
in two cases:

- **Partial runs** (`--filter` / `--testsuite` / path arguments): the subset
  cannot prove an entry no longer occurs.
- **Runs that did not complete cleanly**: any failed, errored, skipped, or
  incomplete test, a skipped test suite (class-level requirements), or a
  truncated run (any `--stop-on-*` flag, hook failures — detected by
  comparing the planned test count against the tests that actually
  finished): later assertions may never have executed, so an unhit entry
  proves nothing.

## Limitations

- **Parallel runners:** baseline generation is refused under paratest
  (`TEST_TOKEN`) — every worker would demote failures and none would write
  the file. Enforcement (suppression) works per worker, but the stale
  summary is not aggregated across workers. Sidecar-based parallel
  generation is tracked in
  [#417](https://github.com/studio-design/gesso/issues/417).
- **PSR-7 decode failures:** the PSR-7 adapter folds body-decode failures
  into the validation result itself; its generation-time interaction with
  the absent-body placeholder is tracked in
  [#418](https://github.com/studio-design/gesso/issues/418).
- Suppression is per assertion, not per test: an assertion mixing baselined
  and new violations fails as a whole (by design — see enforcement
  semantics).
