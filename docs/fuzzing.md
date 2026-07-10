# Schema-driven request fuzzing

The `ExploresOpenApiEndpoint` trait generates N happy-path request inputs for one (method, path) operation directly from the OpenAPI spec — the PHP equivalent of [Schemathesis][schemathesis]. Pair it with the existing `ValidatesOpenApiSchema` trait and every fuzzed call automatically asserts response contract conformance and records coverage.

```php
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Laravel\ExploresOpenApiEndpoint;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;

#[OpenApiSpec('front')]
class CreatePetTest extends TestCase
{
    use ExploresOpenApiEndpoint;
    use ValidatesOpenApiSchema;

    public function test_create_pet_contract(): void
    {
        $this->exploreEndpoint('POST', '/v1/pets', cases: 50, seed: 1)
            ->each(fn ($input) => $this->postJson('/api/v1/pets', $input->body)
                ->assertSuccessful());
    }
}
```

What you get per case (`Studio\OpenApiContractTesting\Fuzz\ExploredCase`):

| Property | Description |
|--------|-------------|
| `body` | Generated JSON body (or `null` when the operation has no `application/json` requestBody) |
| `query` | name → value for every `in: query` parameter |
| `headers` | name → value for every `in: header` parameter (excludes the OpenAPI-reserved `Accept`/`Content-Type`/`Authorization`) |
| `pathParams` | name → value for every `{placeholder}` segment |
| `method`, `matchedPath` | The resolved spec template (`/v1/pets/{petId}`) and its method |

The collection is `Countable` and `IteratorAggregate`, so `foreach ($cases as $case)` works too if you prefer it over the fluent `each()` helper.

## Explore a whole spec

`exploreSpec()` enumerates the Path Item operations defined by OpenAPI,
including 3.2 `additionalOperations`, then generates and dispatches cases for
every selected method supported by the explorer. It uses the same Laravel spec
resolution as response validation (`#[OpenApiSpec]`, `openApiSpec()`, then
`default_spec`). The two traits are designed to be composed:

```php
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;
use Studio\OpenApiContractTesting\Fuzz\ExploredOperation;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ExploresOpenApiEndpoint;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;

class ApiContractTest extends TestCase
{
    use ExploresOpenApiEndpoint;
    use ValidatesOpenApiSchema;

    public function test_public_contract(): void
    {
        $summary = $this->exploreSpec(casesPerOperation: 20, seed: 20260711)
            ->includeTags(['public'])
            ->excludeOperations(['admin.users.destroy'])
            ->authenticateUsing(fn (ExploredOperation $operation) => $this->actingAs($this->userFor($operation)))
            ->dispatchUsing(fn (ExploredCase $case) => match ($case->method) {
                HttpMethod::GET => $this->get($this->uriFor($case), $case->headers),
                HttpMethod::POST => $this->postJson($this->uriFor($case), $case->body, $case->headers),
                default => throw new LogicException('Add the method to the test dispatcher.'),
            })
            ->assertResponses(); // ValidatesOpenApiSchema auto_assert validates each response

        self::assertFalse($summary->hasSkips(), $summary->skips[0]->reason ?? '');
    }
}
```

Framework-agnostic code starts with
`OpenApiSpecExplorer::explore('front', casesPerOperation: 20, seed: 1)` and
adds `assertResponseUsing()` to validate whatever the dispatcher returns. The
runnable [`examples/psr7`](../examples/psr7) suite demonstrates this with
`OpenApiPsr7Validator` assertions.

### Filters and hooks

- `includeTags()` / `excludeTags()` match when any operation tag overlaps.
- `includeMethods()` / `excludeMethods()`, `includePaths()` /
  `excludePaths()`, and `includeOperations()` / `excludeOperations()` use exact
  values. Fixed HTTP methods are canonicalized; OpenAPI 3.2 custom method
  spelling stays case-sensitive.
- Deprecated operations are excluded by default. Call `includeDeprecated()`
  to opt in.
- `setUpUsing()` and `tearDownUsing()` run once per operation;
  `authenticateUsing()` runs after setup and before its cases.
- `mutateCasesUsing()` runs per generated case and must return an
  `ExploredCase`. Its `withBody()`, `withQuery()`, `withHeaders()`, and
  `withPathParams()` helpers support credentials, stateful IDs, and other
  request-specific changes without mutating shared state.

Operations that are declared but cannot be generated are returned in
`SpecExplorationSummary::$skips` with their reason. This includes schema-less
required inputs and methods outside `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, and
`QUERY`. Malformed `paths`, Path Item, `additionalOperations`, and Operation
Object nodes are spec errors, not unsupported operations: a structural preflight
fails before any request is dispatched. Filters intentionally remove operations
and therefore do not create skip entries. A filter set matching nothing fails
loudly instead of producing a test that asserted nothing.

### Replay and parallel runs

The global seed is deterministic. Each operation receives a stable derived
seed based on `(spec, method, path, global seed)`, so adding or reordering a
different operation does not change existing cases. A dispatch, mutation, or
assertion failure prints the spec, operation ID, method/path, both seeds, case
index, and a minimal `OpenApiEndpointExplorer::explore(...)` replay expression.

Summaries are local immutable values; the explorer adds no process-global
aggregation. Run one plan per parallel worker partition and use the existing
coverage sidecar/merge workflow to aggregate validated response rows.

### Safety for mutating operations

Whole-spec exploration can execute `POST`, `PUT`, `PATCH`, and `DELETE`. Run it
only against an isolated test database or disposable environment, wrap each
case/operation in your framework's transaction reset, and start with
`includeMethods(['GET', 'QUERY'])` when evaluating an unfamiliar spec. Use
`includeOperations()` for a deliberate allowlist when an endpoint triggers
external effects that a database rollback cannot undo.

## Generation behaviour

- Supported keywords: `type` (`string`/`integer`/`number`/`boolean`/`object`/`array`/`null`), `enum`, `format` (`email`/`idn-email`/`uuid`/`date`/`date-time`/`time`/`uri`/`url`/`iri`/`hostname`/`ipv4`/`ipv6`), `minLength`/`maxLength`, `minimum`/`maximum`, `required`, `properties`, `items`.
- Optional object properties alternate between included and omitted across cases, so each batch exercises both required-only and required+optional shapes.
- Required keys are always emitted.
- Path resolution accepts both the spec template form (`/v1/pets/{petId}`) and concrete URIs that match it (`/api/v1/pets/123` with `strip_prefixes=/api`). Captured URI values are intentionally discarded — `pathParams` is always regenerated from the operation spec for consistency.

## `seed` and determinism

When [`fakerphp/faker`][faker] is installed (already a transitive dev dependency via `orchestra/testbench` for most projects), generation uses Faker's locale-aware primitives and is fully deterministic for a given `seed:`. Without Faker, the trait falls back to deterministic counter-based primitives that still pass schema validation — your CI never depends on a runtime-installed package.

```bash
# Optional but recommended for realistic generation
composer require --dev fakerphp/faker
```

## Out of scope (today)

The MVP intentionally targets happy-path generation. Tracked separately:

- Boundary value injection (min/max-length extremes, Unicode edge cases)
- Negative-case generation (deliberately invalid inputs to assert 4xx responses)
- `oneOf` / `anyOf` / `allOf` composition; regex `pattern`; `multipleOf`; `minItems` / `maxItems`

[schemathesis]: https://github.com/schemathesis/schemathesis
[faker]: https://github.com/FakerPHP/Faker
