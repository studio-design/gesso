# Coverage JSON Schema

`studio-design/gesso` can emit machine-readable coverage
output via the `json_output` PHPUnit Extension parameter or the
`--json-output` flag on `gesso coverage:merge`. This page documents the
schema so downstream consumers (custom dashboards, contract-coverage
analytics, scripted gating) can rely on a stable shape.

A sample document is committed at [`samples/coverage.json`](samples/coverage.json).

## Top level

| Field | Type | Description |
|-------|------|-------------|
| `schema_version` | `integer` | Bumped on incompatible contract changes. The current version is `3`. Consumers SHOULD reject unknown values. |
| `generated_at` | `string` | ISO-8601 timestamp (`DateTimeImmutable::ATOM`) for when the document was rendered. |
| `tool` | `object` | `{ "name": "studio-design/gesso", "version": "<composer version or 'unknown'>" }`. Useful for downstream consumers diagnosing format drift. `"unknown"` is emitted when Composer's `InstalledVersions` metadata is unavailable (e.g. running from a vendored checkout without `composer install`, or `replace`d in a parent project); the field is always a string so downstream JSON schema validators do not need a nullable type. |
| `aggregate` | `object` | Rollup across every spec in the document. Lets consumers read one "total" without re-summing. See [aggregate fields](#aggregate-fields). |
| `specs` | `object` | Keyed by spec name. Each value is `{ "aggregates": …, "endpoints": [...], "sdk_exercise": … }`. |

## Aggregate fields

The following HTTP-validation fields are used at the top level and in
`specs.<name>.aggregates`.

| Field | Type | Description |
|-------|------|-------------|
| `endpoint_total` | `integer` | Total declared `(method, path)` endpoints in the spec(s). |
| `endpoint_fully_covered` | `integer` | Endpoints where every declared `(status, content-type)` response pair was validated. |
| `endpoint_partial` | `integer` | Endpoints where at least one response was validated and at least one was not. |
| `endpoint_uncovered` | `integer` | Endpoints with no validated responses and no skipped responses. |
| `endpoint_request_only` | `integer` | Endpoints where a request reached the endpoint but no response definitions matched (request-only observations). |
| `response_total` | `integer` | Total declared `(status, content-type)` pairs. |
| `response_covered` | `integer` | Pairs validated by at least one test. |
| `response_skipped` | `integer` | Pairs reconciled to a skip (e.g. a status matched a configured skip pattern). |
| `response_uncovered` | `integer` | Pairs with no observation. |

The top-level `aggregate` additionally contains `sdk_exercise`, with
`response_total`, `response_exercised`, and `response_unexercised` rolled up
across all specs. Per-spec SDK details live at
`specs.<name>.sdk_exercise` rather than inside the HTTP `aggregates` object.

## Endpoint

Each entry in `specs.<name>.endpoints` has this shape:

| Field | Type | Description |
|-------|------|-------------|
| `endpoint` | `string` | `"{METHOD} {path}"`, e.g. `"GET /v1/pets"`. |
| `method` | `string` | HTTP method, uppercase. |
| `path` | `string` | Spec path template (e.g. `/v1/pets/{petId}`), not a concrete request path. |
| `operation_id` | `string \| null` | OpenAPI `operationId` if declared. |
| `endpoint_state` | `string` | One of `"all-covered"`, `"partial"`, `"uncovered"`, `"request-only"`. Namespaced (not just `"state"`) to avoid value-string collision with `response_state`. |
| `request_reached` | `boolean` | Whether a request hook fired for this endpoint during the run. |
| `responses` | `array` | Per `(status, content-type)` rows. See [Response row](#response-row). |
| `covered_response_count` | `integer` | Convenience count: how many of `responses` reached state `"validated"`. |
| `skipped_response_count` | `integer` | How many of `responses` reached state `"skipped"`. |
| `total_response_count` | `integer` | Length of `responses` — included for ergonomics so consumers do not have to count. |
| `unexpected_observations` | `array` | Observations whose `(status, content-type)` pair is not declared in the spec. See [Unexpected observation](#unexpected-observation). |

### Response row

| Field | Type | Description |
|-------|------|-------------|
| `status_key` | `string` | Literal HTTP status (`"200"`) or spec range key (`"5XX"`, `"default"`). |
| `content_type_key` | `string` | The spec's original-cased media-type key (e.g. `"application/json"`), or the wildcard sentinel `"*"` for "any / no content-type". |
| `response_state` | `string` | One of `"validated"`, `"skipped"`, `"uncovered"`. Namespaced as above. |
| `hits` | `integer` | Monotonic count of observations recorded for this pair across the run. |
| `skip_reason` | `string \| null` | Latest non-null skip reason, when `response_state` is `"skipped"`. |

### Unexpected observation

| Field | Type | Description |
|-------|------|-------------|
| `status_key` | `string` | The literal status observed. |
| `content_type_key` | `string` | The literal content-type observed. |

## SDK exercise

`specs.<name>.sdk_exercise` is independent from HTTP validation coverage:

| Field | Type | Description |
|-------|------|-------------|
| `response_total` | `integer` | Eligible JSON response schemas in the live spec. |
| `response_exercised` | `integer` | Eligible schemas whose SDK decoder callback was attempted at least once. |
| `response_unexercised` | `integer` | Eligible schemas with no decoder attempt. |
| `responses` | `array` | One row per eligible response schema, in stable spec order. |
| `unexpected_observations` | `array` | Recorded SDK attempts that no longer reconcile to an eligible response schema. |

Each `responses` row has:

| Field | Type | Description |
|-------|------|-------------|
| `endpoint` | `string` | `"{METHOD} {path}"` identity. |
| `method` | `string` | Declared operation method. Fixed HTTP methods are uppercase; custom OpenAPI 3.2 methods preserve their identity. |
| `path` | `string` | Spec path template. |
| `operation_id` | `string \| null` | OpenAPI `operationId`, when present. |
| `status_key` | `string` | Declared exact, range, or `default` response key. |
| `content_type_key` | `string` | Original-cased declared JSON-compatible media-type key. |
| `exercised` | `boolean` | Whether at least one decoder attempt was recorded. |
| `hits` | `integer` | Number of decoder attempts aggregated across the run/workers. |

Each SDK `unexpected_observations` row contains `endpoint`, `status_key`,
`content_type_key`, and `hits`.

## Compatibility policy

- Additive changes (new fields, new enum values for `endpoint_state` / `response_state`) keep `schema_version` at `3`.
- Removals, renames, or shape changes bump `schema_version`.
- Schema version 3 adds the top-level aggregate and per-spec SDK exercise
  structures. All version 2 HTTP fields retain their names, types, and
  meanings. Version 2 had previously changed only the fixed `tool.name` from
  `studio-design/openapi-contract-testing` to `studio-design/gesso`.
- The wire format used by paratest worker sidecars is **separate** from this schema. Coverage, strict, and SDK tracker state are versioned independently inside the sidecar envelope. Do not consume sidecar payloads as if they were `json_output`; they omit derived report fields and have a different compatibility policy.

## Generating the file

PHPUnit Extension:

```xml
<extensions>
  <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
    <parameter name="spec_base_path" value="openapi" />
    <parameter name="json_output" value="build/coverage.json" />
  </bootstrap>
</extensions>
```

Paratest merge CLI:

```bash
vendor/bin/gesso coverage:merge \
  --spec-base-path=openapi \
  --json-output=build/coverage.json
```

Multiple format outputs are independent: setting `output_file`, `junit_output`, `json_output`, and `html_output` simultaneously writes all four. A write failure on one does not block the others; severity follows the existing convention (subscriber WARN, CLI FATAL+exit 1).
