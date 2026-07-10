# Doctor command

`openapi-contract doctor` answers one focused question before the test suite starts: can this package load and enforce the selected OpenAPI contract as configured?

It is not a replacement for a semantic or style linter such as Spectral or Redocly.

## Basic usage

```bash
vendor/bin/openapi-contract doctor \
  --spec=openapi/front.yaml \
  --strip-prefix=/api
```

The command checks file readability and parser availability, the declared OpenAPI version and JSON Schema dialect, internal and external references, schema keywords that the validator warns about, and structurally valid operations and response definitions. It also reports recognized features that are intentionally not enforced.

JSON and YAML are supported. YAML requires the optional `symfony/yaml` package, just like runtime validation.

## Multiple specs

Repeat `--spec`, or provide comma-separated paths:

```bash
vendor/bin/openapi-contract doctor \
  --spec=openapi/front.yaml \
  --spec=openapi/admin.json \
  --strip-prefix=/api
```

`--phpunit-snippet` prints the equivalent extension configuration when all entry documents share one directory. The snippet uses each entry document's filename without its extension as the configured spec name.

## HTTP references

Remote references remain opt-in because diagnostics must not access the network unexpectedly:

```bash
vendor/bin/openapi-contract doctor \
  --spec=openapi/root.yaml \
  --allow-remote-refs
```

The command automatically uses an installed Guzzle (`guzzlehttp/guzzle` plus `guzzlehttp/psr7`) or Symfony HttpClient PSR-18 implementation. Without `--allow-remote-refs`, an HTTP(S) `$ref` is reported as a reference error with an actionable hint. Network failures, malformed remote documents, content-type mismatches, and reference cycles also exit non-zero.

## Machine-readable output

Use `--format=json` for CI and tooling. The top-level `schemaVersion` is currently `1` and will change if the machine-readable contract requires an incompatible revision.

```json
{
    "schemaVersion": 1,
    "status": "ok",
    "summary": {
        "specs": 1,
        "operations": 4,
        "responses": 7,
        "errors": 0,
        "warnings": 0,
        "skipped": 0
    },
    "specs": [],
    "issues": [],
    "phpunit": null
}
```

Every issue has a stable `severity`, `category`, `spec`, `message`, and nullable `suggestion` field. Severities are:

- `error`: the contract cannot be loaded or enforced as configured;
- `warning`: validation can proceed, but the package detected a compatibility limitation;
- `skipped`: a recognized contract feature is not currently enforced and needs a separate test.

Categories currently emitted by schema version 1 are `io`, `parser`, `version`, `dialect`, `references`, `structure`, `keyword`, `feature`, `dependency`, `configuration`, `spec`, `compatibility`, and `internal`. Consumers should branch on `severity` and `category`, not the human-readable message.

## Exit codes

| Code | Meaning |
|---:|---|
| `0` | All selected specs are compatible. Warnings and skipped features may still be present. |
| `1` | At least one diagnostic error prevents reliable contract enforcement. |
| `2` | The command-line invocation is invalid, such as a missing `--spec` or unknown format. |

Treat both `1` and `2` as CI failures. Always inspect warnings and skipped features even when the exit code is `0`.
