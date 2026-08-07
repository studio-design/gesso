# Violation baseline

Adopting contract testing on an existing API usually fails at the first run:
hundreds of known spec/implementation mismatches, all failing at once. The
violation baseline converts that into a one-PR adoption, the same way PHPStan
and Psalm baselines work: record today's violations in a committed file, keep
CI green, and fail only on **new** violations. The debt stays enumerated in
one reviewable file and can be ratcheted down entry by entry.

Its counterpart for *untested* responses is the
[coverage baseline](coverage-baseline.md), which shares the same generation
command.

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

2. Generate the baseline (full suite, no `--filter`):

   ```bash
   GESSO_BASELINE_GENERATE=1 vendor/bin/phpunit
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

Body-decode failures (unparseable JSON, an unreadable or non-seekable PSR-7
stream) carry the synthetic `parse` keyword, so a baselined decode failure
never absorbs a genuinely empty body on the same operation — and vice
versa.

## Generating under parallel runners

Under paratest or Pest `--parallel`, each worker sees only its slice of the
suite, so no single worker may write the baseline file. Generation instead
follows the same two-step workflow as [parallel coverage](parallel.md): each
worker stages its collected fingerprints in its sidecar (envelope v5), and
the merge step unions them into the committed file:

```bash
# 1. Run the parallel suite in generation mode — failures are demoted,
#    fingerprints ride the worker sidecars.
GESSO_BASELINE_GENERATE=1 vendor/bin/paratest --processes=4

# 2. Union the worker halves and write the baseline.
vendor/bin/gesso coverage:merge \
    --spec-base-path=openapi/bundled \
    --baseline-file=gesso-baseline.json
```

The union is deterministic — the same contract debt produces a byte-identical
file regardless of how tests were distributed across workers.

The merge fails loudly (exit 1, sidecars preserved for a retry) instead of
writing a wrong or missing baseline when:

- sidecars carry baseline data but `--baseline-file` was not given —
  discarding it would silently hide the violations the workers demoted;
- `--baseline-file` was given but some sidecar carries no baseline data
  (a worker ran without generation mode, or on a pre-2.2 library version) —
  the union would be an incomplete baseline;
- `--baseline-file` was given but no sidecars exist at all.

Because generation demotes failures per worker, the merge step is what makes
the run trustworthy — wire both steps into the same CI job so a forgotten
merge cannot leave a green run with no baseline written. As with sequential
generation, run the full suite: per-worker slices are expected, but a
`--filter`ed parallel run cannot be detected and would bake an incomplete
baseline.

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
  with the synthetic `parse` keyword. In the Laravel and Symfony adapters it
  carries no matched status/content-type context (the failure happens before
  path matching); the PSR-7 adapter folds the failure into the validation
  result as a `parse`-keyword issue with its matched context. Either way,
  validation still continues against a placeholder body so violations
  elsewhere in the request/response are not masked — and the placeholder's
  own body verdicts are treated as artifacts of the decode failure: they are
  neither recorded at generation time nor required to be baselined at
  enforcement time.
- The `response.content_type` note — emitted when an undeclared `+json`
  Content-Type fell through to the first JSON key, see
  [supported-features.md](supported-features.md#body-validation) — explains
  the body errors beside it rather than being a violation of its own. It is
  neither recorded nor required to be baselined, so a baseline written before
  the note existed keeps suppressing the same failure.
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

- **Parallel runners:** enforcement (suppression) works per worker, but the
  stale summary is not aggregated across workers — ratchet-down evaluation
  needs a sequential full run. Generation is supported via the sidecar merge
  (see [Generating under parallel runners](#generating-under-parallel-runners)).
- Suppression is per assertion, not per test: an assertion mixing baselined
  and new violations fails as a whole (by design — see enforcement
  semantics).
