# Schema-driven request fuzzing

The `ExploresOpenApiEndpoint` trait generates deterministic request inputs for
one operation or a filtered whole spec. Its workflow is inspired by
[Schemathesis][schemathesis], but the supported strategy matrix below is the
contract; this package does not claim feature parity.

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
| `kind`, `targetKeyword`, `targetPointer` | Valid/invalid classification and the single constraint targeted by a negative case |
| `expectedStatusClasses` | Explicit response classes supplied for a negative case (for example `[4]`) |
| `seed`, `caseIndex` | Stable replay identity |

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
runnable [`examples/psr7`](https://github.com/studio-design/gesso/tree/main/examples/psr7) suite demonstrates this with
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
Each case also exposes `replayToken()`, `replaySnippet($specName)`, and
`curlSnippet($baseUrl)`. The token identifies generation inputs; the PHP and
curl output include the concrete generated request.

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

Every purportedly valid value is checked against the converted JSON Schema
before dispatch. A generator bug therefore fails locally with an `Internal
fuzz generator defect` diagnostic instead of sending invalid data to the API.

| Strategy | Valid generation | Targeted invalid mutation |
|---|---|---|
| Scalars | `type`, `const`, `enum`, nullable branches | wrong type, const/enum miss |
| Strings | min/max length, common regex patterns, Unicode code points, Faker-backed email/UUID/date/time/URI/host/IP formats | below/above length, pattern miss, invalid recognized format |
| Numbers | inclusive/exclusive bounds and `multipleOf`, including OAS 3.0 boolean-exclusive lowering | outside/equal-exclusive bound, non-multiple |
| Arrays | `items`, `prefixItems`, min/max items, `uniqueItems` | too few/many or duplicate items |
| Objects | properties, required, min/max properties, schema-valued/default additional properties | missing required, extra forbidden, too few/many properties, nested property constraint |
| Composition | branch rotation for `oneOf`/`anyOf`, merged object/range assertions for `allOf`, `not`, and `if`/`then`/`else`; lowered discriminator branches use the same path | deterministic composition miss where one can be isolated |

Arbitrary regex synthesis, recursive schemas, `contains`,
`patternProperties`, `dependentSchemas`, and `unevaluated*` generation are not
currently strategies. Those keywords remain validator features; an operation
whose valid value cannot be synthesized fails locally or is an explicit
whole-spec skip.

- Optional object properties alternate between included and omitted across cases, so each batch exercises both required-only and required+optional shapes.
- Required keys are always emitted.
- Path resolution accepts both the spec template form (`/v1/pets/{petId}`) and concrete URIs that match it (`/api/v1/pets/123` with `strip_prefixes=/api`). Captured URI values are intentionally discarded — `pathParams` is always regenerated from the operation spec for consistency.

## `seed` and determinism

When [`fakerphp/faker`][faker] is installed, generation uses Faker's
locale-aware primitives and is deterministic for a given `seed:`. Without it,
ordinary strings and scalar boundaries use deterministic counter-based values.
Recognized formats such as email and UUID cannot be synthesized reliably by
that fallback: the existing one-shot warning is followed by the valid-case
self-check, so the operation fails locally rather than dispatching a value that
does not satisfy its format.

```bash
# Required when explored schemas use Faker-backed formats
composer require --dev fakerphp/faker
```

## Negative cases and reduction

Negative exploration requires the expected response class. There is no
implicit "anything except 5xx" fallback:

```php
$this->exploreInvalidEndpoint(
    'POST',
    '/v1/pets',
    expectedStatusClasses: [4],
    cases: 20,
    seed: 7,
)->each(function (ExploredCase $case): void {
    $response = $this->postJson('/api/v1/pets', $case->body);
    self::assertContains(intdiv($response->getStatusCode(), 100), $case->expectedStatusClasses);
});
```

For a whole spec, add `->negativeCases([4])` before `dispatchUsing()` and
inspect the same metadata in `assertResponseUsing()`. Each generated invalid
case is self-checked to ensure it actually fails the complete schema.

`FailureReducer::reduce($case, $classify)` deterministically removes body
members only while the callback returns the original non-empty classification.
Use a stable value such as `status:500` or an exception class. Reduction is
deliberately classification-preserving; it never equates every failure.

## Remaining gaps

- Arbitrary ECMA-262 regex synthesis and recursive/reference-cycle generation.
- Cookie and `parameters[].content` fuzz generation.
- Structural shrinking inside nested arrays/objects; current reduction removes
  top-level body members.
- Measurement-based feature parity with Schemathesis.

[schemathesis]: https://github.com/schemathesis/schemathesis
[faker]: https://github.com/FakerPHP/Faker
