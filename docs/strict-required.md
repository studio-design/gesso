# Schema under-description detection (`strict_required`)

Runtime contract validation answers **"does the response conform to the spec?"** It does not answer the inverse: **"does the spec adequately describe the implementation?"**

The most common gap that slips past conformance checks is *under-description*: the implementation always returns a field, but the spec marks it optional. The response validates cleanly (an optional field that *is* present violates nothing), yet downstream SDK consumers see the field typed as `T | undefined` and pay for needless null checks. Linters never see runtime responses; only contract tests do.

`strict_required` mode closes this hole by aggregating observed response keys across the test run and comparing the intersection against the matched schema's `required` array.

- [How it works](#how-it-works)
- [Configuration](#configuration)
- [Example](#example)
- [Per-call mode](#per-call-mode)
  - [Per-call silent paths and NOTE channel](#per-call-silent-paths-and-note-channel)
- [What it does NOT do](#what-it-does-not-do)
- [Paratest](#paratest)
- [Known limitations](#known-limitations)

## How it works

1. On every Success result, `OpenApiResponseValidator` walks the decoded response body and records the **set of keys at every object node** it observes, keyed by `(spec, METHOD path, status, content-type)`. Each node is identified by a JSON-Pointer-like path:
   - `/` is the root object.
   - `/data` is the `data` property of the root.
   - `/items[*]` is the *element-shape* of the `items` array (the intersection of every element's keys within that one response).
   - `[*]` is the element-shape when the response root is itself a JSON array.
   - Property names containing `/`, `~`, or the literal `[*]` are escaped per RFC 6901 plus a `[*]` → `[~*]` extension.
2. Across the run, the tracker keeps the **intersection** of every observed key set **per pointer** — the keys that appeared in *every* recorded response at that node. A pointer is also "always observed" only when every response contributed it: an `items` array that's empty in one response drops the `/items[*]` row entirely. `hits` counts the observations.
3. At PHPUnit's `ExecutionFinished` event (or the merge step for paratest — see "Paratest" below), the asserter loads each spec and **descends the schema in parallel** with the recorded pointers: `type: object` into `properties`, `type: array` into `items`. For every pointer it diffs `intersection - schema.required` and emits one report per drifting node.
4. The result is emitted to STDERR and GitHub Step Summary (`$GITHUB_STEP_SUMMARY`) following the same format as the existing enum-drift block, and — in `fail` mode — terminates the run with `exit(1)`.

Only **conformance-passing** responses are recorded. A response that fails the existing JSON Schema check is excluded (its body shape is suspect); skipped statuses (e.g. matching `skipResponseCode`) are excluded too.

## Configuration

`strict_required` is a single PHPUnit extension parameter with three values:

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="strict_required" value="warn"/>
    </bootstrap>
</extensions>
```

| Value | Behaviour |
|---|---|
| `off` (default) | Observations are still recorded (the cost is `O(top-level keys)` per response), but no report is rendered. Existing test suites see no change. |
| `warn` | A diagnostic block is written to STDERR and `$GITHUB_STEP_SUMMARY`. The run exits zero. Use this when adopting the gate — surface the gaps, then triage them at your own pace. |
| `fail` | Same diagnostic, plus `exit(1)`. Use this once your spec is clean to prevent regressions. |

Unrecognised values (`enforce`, `true`, `1`, …) fail bootstrap with a FATAL line — silently dropping a misspelled gate would defeat the point. Leading / trailing whitespace and casing are tolerated (`Warn`, `  fail  `).

## Example

Given the response schema:

```jsonc
{
  "type": "object",
  "properties": {
    "expires":    { "type": "integer" },
    "signed_url": { "type": "string" },
    "url":        { "type": "string" }
  },
  // required absent → all three are optional
  "additionalProperties": false
}
```

and an implementation that always returns all three fields, `strict_required = warn` produces:

```
[OpenAPI Strict Required] WARNING: 1 endpoint response(s) have always-present fields missing from `required`.

  PUT /publish/signed-url/{projectId}/snapshot/{snapshotId}  200  application/json:/
    Observed in 7 response(s); the following keys appeared every time but are not declared in `required`:
      - expires
      - signed_url
      - url

Action: add these fields to the response schema's `required` array, or set `strict_required = off` if intentional.
Configuration: phpunit.xml <parameter name="strict_required">warn|fail|off</parameter>
```

The `application/json:/` suffix is the JSON-Pointer-like `schemaPointer` — `/` here is the response root. Nested drifts are reported the same way with the corresponding pointer; given the schema:

```jsonc
{
  "type": "object",
  "required": ["items"],
  "properties": {
    "items": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["id"],
        "properties": {
          "id":         { "type": "string" },
          "name":       { "type": "string" },
          "created_at": { "type": "string", "format": "date-time" }
        }
      }
    }
  }
}
```

and an implementation that always returns `name` and `created_at` per item, the diagnostic block points directly at the offending array-element shape:

```
  GET /catalog  200  application/json:/items[*]
    Observed in 4 response(s); the following keys appeared every time but are not declared in `required`:
      - created_at
      - name
```

The fix is to update the spec:

```jsonc
{
  "type": "object",
  "required": ["expires", "signed_url", "url"],
  "properties": { /* ... */ },
  "additionalProperties": false
}
```

After re-running the suite the diagnostic disappears and downstream SDK consumers receive concrete (non-optional) types for the three fields.

## Per-call mode

The default `strict_required` mode aggregates observations and reports drift only after the run finishes. Endpoints with a single test case never reach the `hits >= 2` threshold needed to confirm "this key appears in *every* call" — they silently pass even when the spec is under-described.

`strict_required_per_call` is a separate, lightweight gate that fires immediately on every conformance-passing response: any optional field present in this single observation is reported as `E_USER_WARNING`. Pair it with PHPUnit's `failOnWarning="true"` to convert per-call drift into per-test failures.

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="strict_required"          value="fail"/>  <!-- run-level safe gate  -->
        <parameter name="strict_required_per_call" value="warn"/>  <!-- per-call early signal -->
    </bootstrap>
</extensions>
```

| Value | Behaviour |
|---|---|
| `off` (default) | The checker short-circuits; no per-call comparison runs. |
| `warn` | Each Success response is diffed against the matching schema's `required`. Drift triggers `E_USER_WARNING` with the prefix `[OpenAPI Strict Required per-call]`. |

`fail` is **not** an accepted value — silently demoting it to `warn` would mislead a CI that opted in by mistake. Per-call must stay warn-only by design (see trade-off below). Use `failOnWarning="true"` if you want hard test failures.

Sample output (one warning per drifting observation):

```
[OpenAPI Strict Required per-call] WARN: PUT /signed-url  200  application/json: response carries 3 optional field(s) not declared in `required` at the matching schema pointer(s):
  / : expires, signed_url, url
Action: add these fields to the schema's `required` array, or set strict_required_per_call=off if intentional.
Note: per-call mode warns on every legitimately-optional field present in this single observation. See docs/strict-required.md "Per-call mode" for the trade-off.
```

The `[OpenAPI Strict Required per-call]` prefix is intentionally distinct from the run-level `[OpenAPI Strict Required]` block so log scrapers can route the two channels independently.

### Why no `fail` mode?

Per-call by definition fires on every legitimately-optional field that happens to be present in any one observation — nullable fields, conditional payloads, fields gated by feature flags. Forcing the run to exit non-zero on the first such hit would push false positives onto every test suite. Run-level intersection mode (`strict_required=fail`) is the stable fail-gate; per-call is the early-visibility companion.

### Run-level vs per-call: which gate when?

| | Run-level (`strict_required`) | Per-call (`strict_required_per_call`) |
|---|---|---|
| When does it fire? | Once at `ExecutionFinished` | Immediately on each Success response |
| Aggregation | Intersection across every observation per pointer | None — uses the single observation's keys |
| False positives | Low (a field must appear in *every* call) | Higher (any optional-but-present field) |
| Single-observation endpoint | Silently skipped | Surfaces drift |
| Recommended escalation path | `off` → `warn` → `fail` | `off` → `warn` (no `fail`) |

Both gates can be enabled together; they read the same per-response observation but make different calls about when to act on it.

### Per-call silent paths and NOTE channel

Per-call mode has no equivalent to the run-level asserter's `ExecutionFinished` summary, so several "infrastructure-level" no-ops cannot be surfaced as drift. To keep these visible without escalating them to per-test failures, the checker emits a **one-shot stderr NOTE** the first time each condition is hit per process:

- **Spec load failure mid-run** (the spec file was unlinked or rewritten after bootstrap eager-load).
- **Unresolvable response schema** for an observation (path-matcher / asserter disagreement, or a `$ref` resolved to an unexpected shape — both bug-level).
- **Disjunction-covered observation** (the pointer falls under an `anyOf` / `oneOf` node, or the response root is itself unwalkable). Per-call cannot emit "add to `required`" advice safely here.

NOTE volume is deduped (one per spec for load failures, one per `(spec, endpoint, response)` for unresolved schemas, one per `(spec, endpoint, response, covering-pointer)` for disjunctions). All NOTEs use the `[OpenAPI Strict Required per-call] NOTE:` prefix and write through the same channel as the WARN messages, so a single log scrape covers both severities.

Under paratest, each worker maintains its own NOTE dedupe set, so the same condition can produce N NOTEs across N workers — by design, so the parent run can see which workers tripped the condition.

### Suppressing per-call drift

There is currently no per-test or per-field suppression mechanism for per-call mode — if a field is genuinely optional and you do not want the warning, the recommended workflows are:

1. **Add the field to the schema's `required` array** if the implementation actually always returns it.
2. **Disable per-call** (`strict_required_per_call=off`) for the suite and rely on the run-level intersection gate, which already handles the "sometimes-present" case correctly.
3. **Avoid `failOnWarning="true"`** at the global level if you only want per-call warnings as advisory output.

A future release may add an opt-out attribute (e.g. `#[StrictRequiredPerCallIgnore]`) for endpoints with known noisy optional fields — see Issue #228 follow-up tracking. The MVP ships without it so the noise floor of the gate is observed in real CI before deciding the suppression API.

## What it does NOT do

- It does **not** flag fields the impl returns *sometimes* but not always. By design — those fields are legitimately optional, and `required` only describes invariant fields.
- It does **not** check for fields the spec declares required but the impl omits. That is conformance, and the existing validator already catches it.
- It does **not** flag fields the impl returns that are not declared in `properties`. Use `additionalProperties: false` for that — also already supported by the existing validator.
- It does **not** read the spec for endpoints your tests never touched. Strict required is a runtime-observation gate; coverage-tracking gaps are reported separately by `OpenApiCoverageExtension`'s coverage report.

## Paratest

Paratest / Pest `--parallel` is supported. Each worker exports its observations via the coverage sidecar envelope (v2). Pass `--strict-required=<mode>` to the merge CLI to evaluate the gate after the workers complete:

```bash
vendor/bin/openapi-coverage-merge \
  --spec-base-path=tests/fixtures/specs \
  --specs=front \
  --sidecar-dir=$RUNNER_TEMP/openapi-sidecars \
  --strict-required=fail
```

`--strict-required` accepts `off` (default), `warn` (emit diagnostic, exit 0), and `fail` (emit diagnostic, exit 1). The `strict_required` parameter on the PHPUnit extension does **not** propagate to the merge CLI — workers always export observations, and the merge step decides whether to assert. This keeps mode flips a single-knob CI operation without per-worker reruns.

The diagnostic block is rendered after the coverage report (Markdown, JUnit, JSON, HTML, GITHUB_STEP_SUMMARY) so a fatal drift does not suppress the coverage output that helps triage the failure.

**Per-call mode is worker-local.** `strict_required_per_call=warn` fires `E_USER_WARNING` inside the worker process. PHPUnit's `failOnWarning="true"` converts those warnings into per-worker test failures, which paratest aggregates into its own non-zero exit code — the gate works correctly under paratest. The `strict_required_per_call` parameter is **not** consumed by the merge CLI; there is no `--strict-required-per-call` flag because the per-call decision is made entirely inside the worker. NOTEs (see [Per-call silent paths](#per-call-silent-paths-and-note-channel)) are also per-worker, so the same condition may produce multiple NOTEs across a paratest run.

## Known limitations

- **Mixed sidecar versions.** Workers running an older library version write a v1 (coverage-only) sidecar. The merge CLI still accepts those and merges their coverage, but their strict_required contribution is empty. Upgrade all workers to share the gate fully.
- **Mixed strict_required wire versions.** Starting with this release the strict_required tracker emits state format **v2** (per-pointer rows). The merge CLI rejects v1 strict_required payloads with a loud error rather than silently downgrading nested observations to root-only. Coverage merging is unaffected (the envelope still tolerates v1 coverage payloads); only the strict_required half requires version-aligned workers.
- **`additionalProperties` schemas are not walked.** When a schema sets `additionalProperties: <schema>` (object form), the asserter does not descend into that schema to look for `required` on dynamically-keyed properties. Walk-depth follows declared `properties` and `items` only. **Consequence:** dynamically-keyed response objects (`{"user-42": {...}, "user-43": {...}}`-style maps) silently miss strict-required coverage on their values. If you need strict-required coverage on map-shaped responses, pin the shape with declared `properties` instead.
- **`allOf` is unioned; `anyOf` / `oneOf` are not walked.** `allOf` semantics are AND, so the union of `required` arrays across branches is sound — applied at every level of descent. `anyOf` / `oneOf` are disjunctions and there is no safe AND-semantic for "required" across them; the asserter stops descending at such a node. **Consequence:** observations under an `anyOf` / `oneOf` node surface as a separate `NOTE` block (not drift), pointing to the disjunction site so reviewers can pin the shape with `allOf` if strict-required coverage matters there. Drift advice ("add to `required`") is suppressed for these pointers because it would be actively wrong. Per-call mode applies the same rule: pointers under a disjunction are silently skipped (per-call has no NOTE channel).
- **Empty `{}` and `[]` at child positions are skipped.** PHP cannot distinguish empty object from empty list once `json_decode` lands them both as `[]`. To avoid polluting observations with ambiguous shapes, the walker records empty `{}` only at the response root (preserving "empty response collapses the intersection"). Empty arrays at nested positions contribute no pointer to that observation — combined with the tracker's "absence drops the pointer" rule, a sometimes-empty nested array silently drops its `[*]` row from cross-response analysis. If you need strict-required coverage on a nullable array property, pin the shape with a more specific spec (e.g. `oneOf` with concrete element types — though see disjunction limitation above).
