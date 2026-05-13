# Schema under-description detection (`strict_required`)

Runtime contract validation answers **"does the response conform to the spec?"** It does not answer the inverse: **"does the spec adequately describe the implementation?"**

The most common gap that slips past conformance checks is *under-description*: the implementation always returns a field, but the spec marks it optional. The response validates cleanly (an optional field that *is* present violates nothing), yet downstream SDK consumers see the field typed as `T | undefined` and pay for needless null checks. Linters never see runtime responses; only contract tests do.

`strict_required` mode closes this hole by aggregating observed response keys across the test run and comparing the intersection against the matched schema's `required` array.

- [How it works](#how-it-works)
- [Configuration](#configuration)
- [Example](#example)
- [What it does NOT do](#what-it-does-not-do)
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

## Known limitations

- **Mixed sidecar versions.** Workers running an older library version write a v1 (coverage-only) sidecar. The merge CLI still accepts those and merges their coverage, but their strict_required contribution is empty. Upgrade all workers to share the gate fully.
- **Mixed strict_required wire versions.** Starting with this release the strict_required tracker emits state format **v2** (per-pointer rows). The merge CLI rejects v1 strict_required payloads with a loud error rather than silently downgrading nested observations to root-only. Coverage merging is unaffected (the envelope still tolerates v1 coverage payloads); only the strict_required half requires version-aligned workers.
- **`additionalProperties` schemas are not walked.** When a schema sets `additionalProperties: <schema>` (object form), the asserter does not descend into that schema to look for `required` on dynamically-keyed properties. Walk-depth follows declared `properties` and `items` only.
- **`allOf` is unioned; `anyOf` / `oneOf` are not walked.** `allOf` semantics are AND, so the union of `required` arrays across branches is sound — applied at every level of descent. `anyOf` / `oneOf` are disjunctions and there is no safe AND-semantic for "required" across them; the asserter ignores those branches when collecting `required`. **Consequence:** for a schema whose shape at a node is purely `anyOf` / `oneOf`, the collected `required` is `[]` and *every* always-present key at that node will be reported as drift. Prefer `allOf` for required-set composition, or open a follow-up issue with your use case.
- **Per-call mode is not implemented.** The Issue #224 design noted an alternative "warn on every optional field present in a single observation" mode — the run-level intersection is the MVP because per-call warns indiscriminately on every legitimately-optional field. If you need it, follow up.
