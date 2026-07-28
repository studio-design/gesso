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
| `instance_path` | `string \| null` | JSON Pointer into the validated body (`/` = document root). Set only on body-schema violations; `null` for every other error source. |
| `keyword` | `string \| null` | JSON Schema keyword that failed (`type`, `required`, `enum`, …). Set together with `instance_path`; `null` otherwise. |
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

- No absolute filesystem paths are emitted.
- `message` values can embed fragments of the request/response under test;
  treat the document with the same sensitivity as your test payloads.
- Invalid UTF-8 byte sequences inside messages are replaced with U+FFFD so the
  document always renders instead of masking the original validation failure.
