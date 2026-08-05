# Coverage Report

After running tests, the PHPUnit extension prints a coverage report. The output format is controlled by the `console_output` parameter (or `OPENAPI_CONSOLE_OUTPUT` environment variable).

Coverage is tracked at **`(method, path, statusCode, contentType)` granularity**: a `GET /v1/pets` test that only exercises `200 application/json` does not count `404` or `application/problem+json` as covered. Per-endpoint markers reflect the resolved state across all declared response definitions:

| Marker | Meaning |
|--------|---------|
| `✓` / `:white_check_mark:` | All declared `(status, content-type)` pairs validated |
| `◐` / `:large_orange_diamond:` | Some pairs validated, others uncovered |
| `⚠` / `:warning:` | Pair was skipped (e.g. `5XX` matched the default skip pattern) |
| `✗` / `:x:` | No pair validated for this endpoint |
| `·` / `:information_source:` | Endpoint reached via request-validation but no response asserted |

Specification extensions (`x-` keys) on a Responses Object are not responses and are excluded from the declared set.

The report also breaks the coverage rate into two numbers — the strict endpoint rate (all declared responses validated) and the response-level rate (`responseCovered / responseTotal`).

SDK response exploration is reported separately as **SDK exercise coverage**.
It measures whether eligible JSON response schemas were handed to a generated
SDK decoder; it does not change HTTP validation coverage. A response can be
covered by an HTTP contract assertion but remain unexercised by an SDK, or vice
versa.

- [Console modes](#console-modes)
- [Coverage threshold gate](#coverage-threshold-gate)
- Output formats — see [`ci.md`](ci.md#coverage-output-formats), [`coverage-html-output.md`](coverage-html-output.md), [`coverage-json-schema.md`](coverage-json-schema.md)
- Parallel-runner merge — see [`parallel.md`](parallel.md)

## Console modes

### `default` mode (default)

Shows endpoint summary lines only:

```
OpenAPI Contract Test Coverage
==================================================

[front] endpoints: 12/45 fully covered (26.7%), 8 partial, 25 uncovered
        responses: 38/120 covered (31.7%), 4 skipped, 78 uncovered
        SDK responses: 20/24 exercised (83.3%), 4 unexercised
--------------------------------------------------
Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type
  ✓ GET /v1/pets  (3/3 responses)
  ◐ POST /v1/pets  (1/2 responses)
  ◐ DELETE /v1/pets/{petId}  (1/2 responses, 1 skipped)
  ✗ PUT /v1/pets/{petId}  (0/2 responses)
```

> Endpoint markers come from a fixed set: `✓` all-covered, `◐` partial (any combination of validated, skipped, uncovered short of full coverage), `·` request-only, `✗` uncovered. The `⚠` marker is reserved for per-response sub-rows (skipped responses), never for endpoint summary lines.

### `all` mode

Shows endpoint summaries with per-response sub-rows. Sub-row whitespace is illustrative — the renderer pads `statusKey` to 5 chars and `contentTypeKey` to 32 chars:

```
[front] endpoints: 12/45 fully covered (26.7%), 8 partial, 25 uncovered
        responses: 38/120 covered (31.7%), 4 skipped, 78 uncovered
--------------------------------------------------
Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type
  ✓ GET /v1/pets  (3/3 responses)
      ✓ 200    application/json                  [12]
      ✓ 400    application/problem+json          [1]
      ✓ 422    Application/Problem+JSON          [1]
  ◐ POST /v1/pets  (1/2 responses)
      ✓ 201    application/json                  [3]
      ✗ 422    application/problem+json          uncovered
  ◐ DELETE /v1/pets/{petId}  (1/2 responses, 1 skipped)
      ✓ 204    *                                 [2]
      ⚠ 5XX    *                                 skipped: status 503 matched skip pattern 5\d\d
```

### `uncovered_only` mode

Shows sub-rows only for partial / uncovered endpoints, keeping fully-covered ones compact:

```
[front] endpoints: 12/45 fully covered (26.7%), 8 partial, 25 uncovered
        responses: 38/120 covered (31.7%), 4 skipped, 78 uncovered
--------------------------------------------------
Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type
  ✓ GET /v1/pets  (3/3 responses)
  ◐ POST /v1/pets  (1/2 responses)
      ✗ 422    application/problem+json          uncovered
  ✗ PUT /v1/pets/{petId}  (0/2 responses)
      ✗ 200    application/json                  uncovered
      ✗ 404    application/problem+json          uncovered
```

To turn that list into pre-written tests, feed the same run's JSON output to
[`gesso stubs`](stubs.md).

### `active_only` mode

Useful for the local TDD loop with a multi-spec setup (e.g. `specs="front,store,admin"`). Specs that no test in this run touched are collapsed to a single line, so a focused single-test run no longer has to scroll past hundreds of `✗ uncovered` rows for unrelated specs. Specs with at least one validated, skipped, or request-only observation render the same one-line-per-endpoint view as `default`:

```
[front] no test activity (373 endpoints, 894 responses in spec)
[store] no test activity (148 endpoints, 312 responses in spec)

[admin] endpoints: 1/72 fully covered (1.4%), 0 partial, 71 uncovered
        responses: 1/172 covered (0.6%), 0 skipped, 171 uncovered
--------------------------------------------------
Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type
  ✓ GET /v2/admin/early_accesses  (1/1 responses)
  ✗ POST /v2/admin/early_accesses  (0/2 responses)
  ...
```

You can set the mode via `phpunit.xml`:

```xml
<parameter name="console_output" value="uncovered_only"/>
```

Or via environment variable (takes priority over `phpunit.xml`):

```bash
OPENAPI_CONSOLE_OUTPUT=uncovered_only vendor/bin/phpunit
```

## Coverage threshold gate

Optional CI gate that fails the run when contract or SDK exercise coverage drops below a configured percentage — the contract-testing analogue of PHPUnit's own `--coverage-threshold`. All metrics are aggregated across every spec listed in `specs=`:

- `min_endpoint_coverage` — percentage of endpoints with **all** declared `(status, content-type)` pairs validated.
- `min_response_coverage` — percentage of `(method, path, status, content-type)` rows validated (the same rate the report calls "responses covered").
- `min_sdk_exercise_coverage` — percentage of eligible JSON response schemas for which an SDK decoder callback was attempted.

The SDK denominator includes resolved JSON-compatible response media types
with a schema. It excludes no-content responses, non-JSON media types, missing
or non-JSON schemas, and OpenAPI 3.2 `itemSchema` streaming responses. A
configured SDK gate fails as unevaluable when that denominator is empty.

Default is **warn-only**: a miss prints `[OpenAPI Coverage] WARN: …` to stderr but the run exits 0. Flip `min_coverage_strict=true` to make a miss fail-fast with exit 1.

> To stop coverage from *regressing*, prefer the
> [coverage baseline](coverage-baseline.md): it gates the set of uncovered
> responses instead of a percentage, so there is no threshold to keep raising
> by hand, documenting a new response cannot fail an unrelated PR, and a
> failure names the offending rows. To gate only what the pull request
> actually touched, add the
> [spec patch coverage gate](coverage-gate.md).

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="min_endpoint_coverage" value="80"/>   <!-- percent, optional -->
        <parameter name="min_response_coverage" value="60"/>   <!-- percent, optional -->
        <parameter name="min_sdk_exercise_coverage" value="100"/> <!-- percent, optional -->
        <parameter name="min_coverage_strict" value="true"/>   <!-- default false → warn-only -->
    </bootstrap>
</extensions>
```

Failure looks like:

```
[OpenAPI Coverage] FAIL: endpoint coverage 67.4% < threshold 80%.
                         response coverage 71.2% (>= 60%, ok).
                         SDK exercise coverage 83.3% < threshold 100%.
```

Out-of-range or non-numeric values produce a `WARNING` to stderr and skip that gate (rather than silently treating the misconfiguration as `0%`).

On a partial run (`--filter`, `--testsuite`, path args, …) the gate is skipped with a one-line NOTE instead of evaluating — a subset cannot prove a suite-wide coverage rate. See [Partial test runs](ci.md#partial-test-runs-filter-testsuite-path-args-) for details.

For paratest / `pest --parallel`, the merge CLI accepts the same options as flags:

```bash
vendor/bin/gesso coverage:merge \
    --spec-base-path=openapi/bundled \
    --specs=front,admin \
    --min-endpoint-coverage=80 \
    --min-response-coverage=60 \
    --min-sdk-exercise-coverage=100 \
    --min-coverage-strict
```

The parallel strict SDK gate also requires every worker sidecar to contain SDK
exercise state. A mixed old/new worker fleet is incomplete evidence and fails;
warn-only mode reports the missing-worker count and evaluates the available
observations.
