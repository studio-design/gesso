# Spec-wide SDK round-trip plan design

## Context

`OpenApiResponseExplorer` can generate branch-complete payloads and verify an
SDK round trip for one resolved operation response. Consumers must still write
one test per response schema, so adding a response can remain silently
unexercised. Issue #447 adds a spec-wide plan that discovers selected response
schemas, applies explicit SDK mappings, and reports mapping gaps.

The plan must reuse the existing operation filters, response-schema resolver,
branch-complete generator, round-trip assertion, and crc32 operation-seed
derivation. It must not guess generated model names or add a production
dependency.

## Public API

The existing facade gains a spec-wide entry point:

```php
OpenApiResponseExplorer::exploreSpec('front', seed: 1)
    ->includeTags(['public'])
    ->mapResponse(
        operationId: 'introspect',
        status: 200,
        decode: static fn (GeneratedResponseCase $case): mixed =>
            ObjectSerializer::deserialize($case->bodyAsObject(), IntrospectResponse::class),
        encode: static fn (mixed $model): mixed =>
            ObjectSerializer::sanitizeForSerialization($model),
    )
    ->failOnUnmapped()
    ->assertRoundTrips();
```

`exploreSpec()` returns a new `OpenApiResponseSpecExploration` plan. The plan
uses the existing `includeTags()`, `excludeTags()`, `includeMethods()`,
`excludeMethods()`, `includePaths()`, `excludePaths()`, `includeOperations()`,
`excludeOperations()`, and `includeDeprecated()` surface through
`SelectsExploredOperations`.

`mapResponse()` registers one decode/encode pair for an operation ID and a
declared response status. Integer status values address exact statuses; string
values address exact keys, range keys such as `2XX`, and `default`. Duplicate
registrations and invalid status selectors fail immediately. Operations without
an `operationId` remain selectable but cannot be guessed or mapped; their JSON
response schemas become explicit mapping-gap skips.

The decode callback receives the `GeneratedResponseCase` and
`ExploredOperation`. The encode callback receives the decoded value followed by
the same case and operation. Callers may declare fewer parameters when they do
not need the metadata.

`failOnUnmapped()` is opt-in. Without it, mapping gaps stay visible in the
returned summary. With it, any selected JSON response schema without a mapping
fails the terminal assertion after the complete plan has been evaluated.

Laravel's `ExploresOpenApiEndpoint` trait gains `exploreResponseSpec()`, using
the same spec-name resolution and clean assertion-failure behavior as the
existing exploration conveniences.

## Discovery and execution

The plan loads and preflights the spec once, enumerates operations through
`OpenApiOperationResolver::declaredOperations()`, creates `ExploredOperation`
metadata, and applies the shared filters. A filter set matching no operations is
a configuration error, consistent with request-side whole-spec exploration.

For every selected operation, the plan validates the `responses` node and
enumerates declared response entries. Exact, range, and `default` keys are kept
as distinct targets. Each target is assigned a deterministic representative
wire status so the shared `ResponseSchemaResolver` and
`OpenApiResponseExplorer::explore()` remain the only response-semantics path:

- an exact key uses its numeric value;
- a range uses the first status in that class not shadowed by an exact response;
- `default` uses the first status from 100 through 599 not claimed by an exact
  or range response.

An unreachable range or default entry is an explicit skip. Every
JSON-compatible media type with a schema under a mapped response is explored;
the same operation/status mapping applies to all of them. No-content,
non-JSON-only, missing-schema, non-JSON-schema, and OpenAPI 3.2 `itemSchema`
outcomes remain loud skips carrying the resolver reason.

Each operation seed uses the existing derivation from spec name, normalized
method, path, and global seed. Every response target for that operation receives
the same derived seed, so adding or reordering paths, statuses, or mappings does
not change generated cases for an existing operation. `extraCases` is forwarded
to each response exploration.

For every generated case the plan calls decode, then encode, then the existing
`GeneratedResponseCase::assertRoundTrip()`. It continues after a case failure so
the final report exposes all problems in one run.

## Results and failures

`assertRoundTrips()` builds and returns a readonly
`ResponseSpecExplorationSummary` when no assertion-level failure exists. The
summary reports executed operations, response targets, generated cases, and
structured skips. Mapping gaps are skips with their own stable category.

Decode exceptions and encode/round-trip failures are recorded separately with
the operation, declared status, content type, case index, derived seed, pinned
branch, replay snippet, and original throwable. After discovery and execution,
any recorded decode or round-trip failure causes one categorized PHPUnit
assertion failure. Strict mapping gaps participate in that same aggregate
failure. Ordinary unsupported-response skips remain reportable but do not fail
unless they are also unmapped JSON response schemas.

Malformed `paths`, Path Item, operation, `responses`, response, content, or
media-type nodes fail with location-aware diagnostics rather than disappearing
from the plan. Doctor/runtime response semantics remain centralized in the
existing resolver and malformed-node helpers.

## Compatibility and documentation

The new public plan, summary, result DTOs, facade method, and Laravel method are
added to the v2 public API baseline. Existing public signatures and replay
snippets remain unchanged. The SDK round-trip guide and fuzzing guide gain a
spec-wide example, filter/mapping semantics, skip/failure behavior, deterministic
seed rules, and strict mapping guidance. The API reference lists the new entry
point and types.

## Test strategy

Implementation follows red-green-refactor. Tests first establish:

- all selected mapped response schemas and all JSON media types execute;
- existing tag, method, path, operation, and deprecated filters are identical;
- mappings are explicit, duplicate-safe, and support exact, range, and default
  response keys;
- per-operation seeds are stable under declaration and registration reordering;
- decode failures and round-trip failures are categorized with replay context;
- unmapped JSON schemas are explicit skips and strict mode fails on them;
- no-content and unsupported responses are explicit skips;
- malformed spec nodes fail loudly at their exact location;
- Laravel resolves the spec and exposes the same plan;
- the v2 public API baseline and user documentation include the new surface.

Focused unit tests cover the plan and Laravel adapter. The existing response
explorer, fuzz suite, public API compatibility test, PHPStan, and coding-style
checks provide regression coverage before the full repository suite.
