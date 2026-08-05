# Spec patch coverage gate

`gesso coverage:gate` fails a pull request when it changes an operation that no
test exercises. It is the OpenAPI counterpart of `diff-cover` / Codecov's patch
status: instead of "the whole spec must be 80% covered", it enforces "whatever
*this* change touched must be covered".

It needs three inputs:

| Input | Where it comes from |
|---|---|
| the base spec | `git show` for a single file, `git worktree` for a split spec (below) |
| the current spec | your working tree |
| a coverage document | [`json_output`](coverage-json-schema.md) from the test run |

```bash
git show origin/main:openapi.json > /tmp/base.json

vendor/bin/gesso coverage:gate \
  --base-spec=/tmp/base.json \
  --spec=openapi.json \
  --coverage=build/coverage.json
```

Both documents are loaded exactly like the runtime loader loads them, so local
`$ref`s resolve **relative to their own entry document's directory**. The
single-file `git show` redirect above only works for a spec that has no local
`$ref`. For a split spec, materialise the whole base tree instead:

```bash
git worktree add /tmp/base origin/main

vendor/bin/gesso coverage:gate \
  --base-spec=/tmp/base/openapi/root.yaml \
  --spec=openapi/root.yaml \
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
  operation's request contract changed, every response of that operation is in
  scope, because a changed request is worth re-exercising end to end.
- **removed** — listed for information only. An operation or a response that
  no longer exists cannot be tested, so a removal never fails the gate. A
  change that only deletes a response still reports the operation, rendering
  the deleted row as `removed (not testable)`.

The request contract means the **effective** one, not just the operation
object:

- the Path Item fields the operation inherits (`servers`, …);
- the parameters after the Path Item / operation merge — an operation-level
  entry replaces the Path Item entry with the same `in` + `name`, so changing
  an *already-overridden* Path Item parameter is not a change;
- the security requirement that actually applies — operation-level if
  declared, otherwise the root default — together with the
  `components.securitySchemes` definitions it names.

Tightening a path-level parameter to `required`, or swapping an API key from a
header to a query parameter, therefore puts every affected operation in scope
even though no operation object changed.

The comparison is **structural, not semantic**. The gate does not classify a
change as breaking or non-breaking — that is the job of a dedicated diff tool
such as [oasdiff](https://github.com/oasdiff/oasdiff) or
[pb33f/openapi-changes](https://github.com/pb33f/openapi-changes). Because the
comparison happens after reference resolution, moving a schema into
`components` and pointing a `$ref` at it is *not* a change.

## Which operations are gated

The gate covers the methods a coverage document can actually contain: `GET`,
`POST`, `PUT`, `PATCH`, `DELETE`, `QUERY`, and OpenAPI 3.2
`additionalOperations`. `OPTIONS`, `HEAD`, and `TRACE` are skipped, because
the [coverage tracker](coverage.md) never records them — gating them would
demand coverage no report can ever show.

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
