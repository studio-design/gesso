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

1. On every Success result, `OpenApiResponseValidator` records the **top-level keys** of the decoded response body, keyed by `(spec, METHOD path, status, content-type)`.
2. Across the run, the tracker keeps the **intersection** of every observed key set — the keys that appeared in *every* recorded response for that group. `hits` counts the observations that contributed.
3. At PHPUnit's `ExecutionFinished` event (or the merge step for paratest — currently unsupported, see Known limitations), the asserter loads each spec and, for each group, computes `intersection - schema.required`. Any keys that survive are flagged: the impl always returns them but the spec does not declare them required.
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

  PUT /publish/signed-url/{projectId}/snapshot/{snapshotId}  200  application/json
    Observed in 7 response(s); the following keys appeared every time but are not declared in `required`:
      - expires
      - signed_url
      - url

Action: add these fields to the response schema's `required` array, or set `strict_required = off` if intentional.
Configuration: phpunit.xml <parameter name="strict_required">warn|fail|off</parameter>
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

## Known limitations

- **Paratest is currently sequential-only.** Each worker maintains its own in-memory observations, but the sidecar protocol used by the merge CLI carries only coverage state. When `strict_required` is enabled in worker mode the subscriber emits a one-line `NOTE` and skips the asserter; run the suite sequentially to evaluate the gate, or follow the parallel-runner support follow-up issue.
- **Top-level keys only.** Nested object schemas' `required` arrays are not yet evaluated — only the outermost. A follow-up issue covers nested-object support.
- **`allOf` is unioned; `anyOf` / `oneOf` are not walked.** `allOf` semantics are AND, so the union of `required` arrays across branches is sound. `anyOf` / `oneOf` are disjunctions and there is no safe AND-semantic for "required" across them; the asserter ignores those branches when collecting `required`. **Consequence:** for a schema whose top-level shape is purely `anyOf` / `oneOf`, the collected `required` is `[]` and *every* always-present key will be reported as drift. Prefer `allOf` for required-set composition, or open a follow-up issue with your use case.
- **Per-call mode is not implemented.** The Issue #224 design noted an alternative "warn on every optional field present in a single observation" mode — the run-level intersection is the MVP because per-call warns indiscriminately on every legitimately-optional field. If you need it, follow up.
