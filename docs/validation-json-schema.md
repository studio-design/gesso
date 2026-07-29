# Validation JSON Schema

`Studio\Gesso\JsonValidationResultRenderer::render()` turns any
`OpenApiValidationResult` into a machine-readable JSON document for consumers
that need more than the flat assertion text — CI ingestion, IDE annotations,
scripted triage, or AI-assisted remediation. This page documents the schema so
downstream consumers can rely on a stable shape.

A sample document is committed at [`samples/validation.json`](samples/validation.json).

```php
use Studio\Gesso\JsonValidationResultRenderer;

$json = JsonValidationResultRenderer::render($result);
// Optionally embed a reproduction command (redact secrets first — the
// built-in curl reproduction output is already redacted by default):
$json = JsonValidationResultRenderer::render($result, $curlCommand);
```

The document is deliberately timestamp-free: rendering the same result twice
produces byte-identical output, so snapshot tests and CI diffing work without
masking.

## Selecting json failure output in adapters

Every framework adapter (Laravel, Symfony, Pest, PSR-7) can emit this document
in its assertion failure message instead of the plain text shape. One
process-wide switch selects the mode everywhere, resolved in priority order:

1. the `OPENAPI_VALIDATION_OUTPUT` environment variable (`text` | `json`);
2. `ValidationOutput::use(ValidationOutputFormat::Json)` — call it from your
   test bootstrap, or set the `validation_output` parameter on the PHPUnit
   extension, which calls it for you:

   ```xml
   <extensions>
       <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
           <parameter name="spec_base_path" value="openapi/dist"/>
           <parameter name="validation_output" value="json"/>
       </bootstrap>
   </extensions>
   ```

3. `text` (the default — failure output is unchanged unless you opt in).

An unrecognised value warns on STDERR and never changes the selection:
resolution falls through to the next source (so an invalid `validation_output`
parameter keeps a format already selected via `ValidationOutput::use()`).

In json mode a failing assertion message is one human-readable header line
followed by this document (the curl reproduction moves into
`reproduce_command`, so no separate `Reproduce:` line is emitted):

```
OpenAPI schema validation failed for GET /v1/pets (spec: front):
{
    "schema_version": 1,
    ...
}
```

Everything after the first line parses as JSON. The one exception is the PSR-7
exchange assertion (`assertPsr7ExchangeMatchesOpenApiSchema()`), which
validates two results at once: it emits one `[request]` / `[response]` label
line plus document per *failing* side, each block parseable on its own and all
blocks sharing the same `reproduce_command`.

## Top level

| Field | Type | Description |
|-------|------|-------------|
| `schema_version` | `integer` | Bumped on incompatible contract changes. The current version is `1`. Consumers SHOULD reject unknown values. |
| `tool` | `object` | `{ "name": "studio-design/gesso", "version": "<composer version or 'unknown'>" }`. `"unknown"` is emitted when Composer's `InstalledVersions` metadata is unavailable; the field is always a string. |
| `outcome` | `string` | One of `"success"`, `"failure"`, `"skipped"` — the result's `outcome()` enum value. |
| `matched` | `object` | Operation context the validator resolved. See [matched fields](#matched-fields). |
| `skip_reason` | `string \| null` | Human-readable reason for `"skipped"` outcomes; `null` otherwise. |
| `reproduce_command` | `string \| null` | The command passed as the second `render()` argument, verbatim; `null` when omitted. The renderer performs no redaction of its own — pass a pre-redacted command (the built-in curl formatter redacts sensitive headers, cookies, and query values by default). |
| `issues` | `array` | One entry per structured issue, in the same order as `errors()`. Empty for `"success"` and `"skipped"` outcomes. See [Issue](#issue). |

## Matched fields

| Field | Type | Description |
|-------|------|-------------|
| `path` | `string \| null` | Matched spec path template (e.g. `/v1/pets/{petId}`), or `null` when no path matched. |
| `status_code` | `string \| null` | Spec response key (`"200"`, `"5XX"`, `"default"`) or the literal status for skipped responses; `null` for request-side results and unresolved lookups. |
| `content_type` | `string \| null` | Spec media-type key (spec author's casing) the body was checked against; `null` when no body lookup occurred. |

## Issue

Each entry serialises one `ValidationIssue` field-for-field (snake_case). The
`category` slugs and null-ness semantics are the same as the PHP API — see
[api-reference.md](api-reference.md#openapivalidationresult) and
[versioning.md](versioning.md#whats-covered-by-semver).

| Field | Type | Description |
|-------|------|-------------|
| `category` | `string` | Stable slug naming the producing validator (`request.body`, `response.header`, …). Results built by code that predates the structured API derive `"unknown"`. |
| `message` | `string` | Exact human-readable error text. **Not** a compatibility surface — assert on `category` and context instead. |
| `instance_path` | `string \| null` | RFC 6901 JSON Pointer: `""` is the document root and `"/"` is the property whose name is the empty string. (The human-readable `message` prefix renders the root as `[/]` for historical reasons; `instance_path` is the unambiguous form.) Set on schema violations — for body issues it points into the validated body, for parameter / response-header issues into the named value; `null` for structural and spec-malformation errors. |
| `keyword` | `string \| null` | JSON Schema keyword that failed (`type`, `required`, `enum`, …). Set on schema violations, and additionally as a synthetic violation kind: `"required"` when a required parameter / header / security credential is missing, `"format"` when a credential is present but unusable. `null` otherwise. |
| `parameter` | `string \| null` | Name of the spec object a non-body issue is about: the request parameter (`request.parameter.*`), response header (`response.header`), or security scheme (`request.security`). `null` for body issues and for errors not attributable to one named object (structural spec errors, error-boundary captures). |
| `method` | `string \| null` | HTTP method of the validated operation. |
| `path` | `string \| null` | Matched spec path template. |
| `status_code` | `string \| null` | Spec response key. Always `null` on request-side issues. |
| `content_type` | `string \| null` | Resolved spec media-type key. Set only on body issues. |

## Versioning

The document shape is covered by the [versioning policy](versioning.md):

- Adding a new field is a minor change — consumers MUST ignore fields they do
  not recognise.
- Removing or renaming a field, changing a field's type, or changing the
  meaning of an existing value is a major change and bumps `schema_version`.
- New `category` slugs and new `keyword` values may appear in minor releases.

## Security notes

- The renderer itself adds no filesystem paths or environment details — every
  field is derived from the validation result plus the caller-supplied
  `reproduce_command`. `message` and `reproduce_command` are emitted verbatim,
  so anything they embed (request/response fragments, absolute paths in test
  payloads or commands) passes through: treat the document with the same
  sensitivity as your test payloads and redact before rendering if needed.
- Invalid UTF-8 byte sequences inside messages are replaced with U+FFFD so the
  document always renders instead of masking the original validation failure.
