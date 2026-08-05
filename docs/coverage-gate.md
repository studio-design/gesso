# Spec patch coverage gate

`gesso coverage:gate` fails a pull request when it changes an operation that no
test exercises. It is the OpenAPI counterpart of `diff-cover` / Codecov's patch
status: instead of "the whole spec must be 80% covered", it enforces "whatever
*this* change touched must be covered".

It needs three inputs:

| Input | Where it comes from |
|---|---|
| the base spec | `git show origin/main:openapi.json > base.json` |
| the current spec | your working tree |
| a coverage document | [`json_output`](coverage-json-schema.md) from the test run |

```bash
git show origin/main:openapi.json > /tmp/base.json

vendor/bin/gesso coverage:gate \
  --base-spec=/tmp/base.json \
  --spec=openapi.json \
  --coverage=build/coverage.json
```

```text
[Gesso] 2 operations changed against the base spec:

  DELETE /pets/{id}
    204 (no content)        UNCOVERED
  PUT /pets/{id}
    200 application/json    covered

1 changed response is not covered by any test.
```

## What counts as a change

Both documents are resolved through the same loader the validators use, then
each operation is fingerprinted:

- **added** — the `(method, path)` is not in the base spec. Every declared
  response is in scope.
- **changed** — the operation exists in both, but its resolved tree differs.
  When only some responses changed, only those are in scope. When the
  operation's request contract changed (parameters, request body, security,
  …), every response of that operation is in scope, because a changed request
  is worth re-exercising end to end.
- **removed** — listed for information only. A response that no longer exists
  cannot be tested, so it never fails the gate.

The comparison is **structural, not semantic**. The gate does not classify a
change as breaking or non-breaking — that is the job of a dedicated diff tool
such as [oasdiff](https://github.com/oasdiff/oasdiff) or
[pb33f/openapi-changes](https://github.com/pb33f/openapi-changes). Because the
comparison happens after reference resolution, moving a schema into
`components` and pointing a `$ref` at it is *not* a change.

## What counts as covered

The gate joins each in-scope `(method, path, status, content-type)` tuple
against the coverage document at the same granularity coverage is measured at.
A tuple passes only when its `response_state` is `validated`. A `skipped`
response is rendered as `SKIPPED` and fails the gate — a skip is a deliberate
hole, and a change is exactly the moment to revisit it. A tuple missing from
the coverage document entirely counts as `UNCOVERED`.

## Options

| Flag | Description |
|---|---|
| `--base-spec=<path>` | Spec as it looks on the base branch. Required. |
| `--spec=<path>` | Spec as it looks on this branch. Required. |
| `--coverage=<path>` | Coverage JSON, `schema_version` 3. Required. |
| `--spec-name=<name>` | Key under `specs` in the coverage document. Defaults to the `--spec` filename without its extension. |
| `--format=text\|markdown` | `markdown` renders a table for `$GITHUB_STEP_SUMMARY`. Default `text`. |

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Every changed operation is covered, including "nothing changed". |
| `1` | A changed operation has a response no test validated. |
| `2` | Command-line usage is invalid, or a spec / coverage file cannot be read. |

## In CI

See [CI integration](ci.md#spec-patch-coverage-gate) for a complete
`on: pull_request` workflow.

## Related

- [Coverage](coverage.md) — how coverage is measured and reported.
- [Coverage baseline](coverage-baseline.md) — ratchet the *existing* uncovered
  set instead of gating on the diff. The two compose: the baseline stops
  regressions from growing, the gate stops new work from arriving uncovered.
- [Coverage JSON schema](coverage-json-schema.md) — the document this command
  reads.
