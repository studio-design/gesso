# Named component schema explorer design

- Date: 2026-08-03
- Issue: [#446](https://github.com/studio-design/gesso/issues/446)
- Parent design: [ADR 0003](../../adr/0003-sdk-roundtrip-harness.md)

## Goal

Allow a generated SDK model to be exercised directly from a named
`components.schemas` entry without inventing an OpenAPI operation. The entry
point must retain the existing branch-complete generation and round-trip
fidelity guarantees, and must fail loudly when the name or schema cannot be
used.

## Public API

Add a second entry point to the existing response-payload facade:

```php
OpenApiResponseExplorer::exploreComponent(
    string $specName,
    string $schemaName,
    ?int $seed = null,
    int $extraCases = 0,
): GeneratedResponseCases
```

The method name makes component lookup explicit while keeping both ways of
obtaining an SDK response payload on the same facade:

- `explore()` resolves a schema through operation, status, and content type.
- `exploreComponent()` resolves a schema through `components.schemas`.

Both methods return the existing `GeneratedResponseCases` collection
containing existing `GeneratedResponseCase` values. No component-specific case
or collection type is introduced.

## Component resolution and conversion

`exploreComponent()` loads the named spec through `OpenApiSpecLoader`, then
validates each structural boundary before indexing it:

1. `components`, when present, must be an object.
2. `components.schemas`, when present, must be an object.
3. `$schemaName` must exist as an exact, case-sensitive key.
4. The selected entry must be a Schema Object.

An absent name throws `InvalidArgumentException` and identifies the requested
schema and spec. Malformed document nodes use the repository's existing
malformed-node descriptions so scalar, null, and list-shaped nodes fail at the
boundary instead of producing a type error or an empty run.

The selected Schema Object is converted with:

- `OpenApiVersion::fromSpec()`;
- `OpenApiSchemaDialect::fromSpec()`;
- `SchemaContext::Response`; and
- `DiscriminatorContext` rooted at the loaded document and using the same
  `DiscriminatorEnforcement` gate as operation response resolution.

This preserves OpenAPI 3.0 compatibility lowering, OpenAPI 3.1/3.2 JSON Schema
dialects, response-side `readOnly`/`writeOnly` semantics, and discriminator
mapping enforcement. The converted schema is then passed to the same
`BranchCompleteCaseGenerator` used by `explore()`; unsupported schemas such as
recursive schemas therefore retain the generator's existing loud exceptions.

## Shared case construction

Extract only the post-resolution case-construction loop inside
`OpenApiResponseExplorer`. It receives a converted schema and replay metadata,
runs `BranchCompleteCaseGenerator`, and creates the existing cases. Schema
resolution remains separate because operation responses and components have
different lookup rules and diagnostics.

Operation-specific metadata is absent for component cases:

- public `status` and `contentType` become nullable and are `null`;
- private `method` and `matchedPath` become nullable and are `null`;
- component cases carry the selected schema name for replay generation.

Existing operation cases retain their current non-null values and replay text.
`replaySnippet()` emits `OpenApiResponseExplorer::exploreComponent(...)` for a
component case and `OpenApiResponseExplorer::explore(...)` for an operation
case. `assertRoundTrip()` remains unchanged and validates against the converted
schema stored in either case shape.

Constructor changes preserve the existing parameter names and operation call
sites. The intentional nullable metadata and new entry point are recorded in
the v2 public API compatibility baseline.

## Validation and error behavior

The public boundary rejects negative `extraCases` before loading or generating,
with a diagnostic naming `exploreComponent()`. A valid schema always produces a
non-empty `GeneratedResponseCases` collection. There is no skip or empty-success
path.

Failures remain exceptions rather than collection entries:

- unknown component schema: `InvalidArgumentException`;
- malformed `components`, `schemas`, or selected schema: loud malformed-spec
  exception or `InvalidArgumentException` with the exact location;
- unsupported version, dialect, or discriminator: existing converter/spec
  exception;
- recursive or otherwise unsupported generation: existing generator
  `InvalidArgumentException`.

## Tests

Add focused tests that prove:

- a named component produces every reachable branch and requested extra case;
- generated component cases have null operation metadata and a replayable
  `exploreComponent()` snippet;
- `assertRoundTrip()` accepts fidelity-preserving SDK output and rejects a
  swallowed value for component-generated cases;
- OpenAPI 3.0 response conversion removes `writeOnly` properties;
- OpenAPI 3.1/3.2 document dialect selection is retained;
- discriminator mappings resolve against the document root;
- unknown names, malformed component boundaries, negative `extraCases`, and a
  recursive schema fail loudly;
- existing operation-response exploration behavior remains unchanged; and
- the public API inventory reflects the intentional additive/widening changes.

## Documentation

Extend the `OpenApiResponseExplorer` API reference with
`exploreComponent()`. Add a short named-model example to the SDK round-trip
guide and remove the statement that named component exploration is a future
phase. The documentation will state that component cases have no status or
content-type metadata and retain the same branch-complete and round-trip
guarantees.

## Non-goals

- No Laravel convenience method is added; issue #446 requests the public core
  entry point and documentation only.
- No generic request-context or arbitrary JSON Schema explorer is introduced.
- No schema-to-SDK-class naming convention is inferred.
- No production dependency or coverage wire-format change is required.
