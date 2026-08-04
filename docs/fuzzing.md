# Schema-driven fuzzing

The `ExploresOpenApiEndpoint` trait ships for Laravel only. It generates
deterministic request inputs for one operation or a filtered whole spec. Symfony,
Pest, and plain PHPUnit users call the framework-agnostic
[`OpenApiSpecExplorer::explore()`](#framework-agnostic-exploration) instead. The
workflow is inspired by
[Schemathesis][schemathesis], but the supported strategy matrix below is the
contract; this package does not claim feature parity.

```php
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Laravel\ExploresOpenApiEndpoint;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Attribute\OpenApiSpec;

#[OpenApiSpec('front')]
class CreatePetTest extends TestCase
{
    use ExploresOpenApiEndpoint;
    use ValidatesOpenApiSchema;

    public function test_create_pet_contract(): void
    {
        $this->exploreEndpoint('POST', '/v1/pets', cases: 50, seed: 1)
            ->each(fn (ExploredCase $case) => $this->postJson('/api/v1/pets', $case->bodyAsArray() ?? [])
                ->assertSuccessful());
    }
}
```

What you get per case (`Studio\Gesso\Fuzz\ExploredCase`):

| Property | Description |
|--------|-------------|
| `body` | Generated JSON body (or `null` when the operation has no `application/json` requestBody) |
| `query` | name → value for every `in: query` parameter |
| `headers` | name → value for every `in: header` parameter (excludes the OpenAPI-reserved `Accept`/`Content-Type`/`Authorization`) |
| `pathParams` | name → value for every `{placeholder}` segment |
| `cookies` | name → value; empty unless a check populated it (cookie generation is not implemented). A dispatcher must forward these to its client's cookie bag — a `Cookie:` header alone never reaches `$request->cookie(...)` in Laravel or Symfony test clients |
| `method`, `matchedPath` | The resolved spec template (`/v1/pets/{petId}`) and its method |
| `kind`, `targetKeyword`, `targetPointer` | Valid/invalid classification and the single constraint targeted by a negative case |
| `expectedStatusClasses` | Explicit response classes supplied for a negative case (for example `[4]`) |
| `seed`, `caseIndex` | Stable replay identity |

The collection is `Countable` and `IteratorAggregate`, so `foreach ($cases as $case)` works too if you prefer it over the fluent `each()` helper.

Use `bodyAsArray()` with Laravel and other HTTP helpers that require an array
payload. It recursively converts generated JSON objects, but an empty JSON
object becomes an empty PHP array; encode `body` directly when the `{}` versus
`[]` distinction matters. `uri($prefix)` substitutes and URL-encodes path
parameters, prepends an optional application prefix, and appends the generated
query string.

## Explore response payloads against a generated SDK

`OpenApiResponseExplorer` exercises the other side of the contract: whether a
generated SDK can decode every supported branch of one response schema and
encode the values without losing data. Unlike request exploration's fixed case
count and rotation, the response explorer derives enough cases to cover every
branch of every reachable choice point, then appends any requested
`extraCases`.

```php
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;

OpenApiResponseExplorer::explore(
    'front',
    'POST',
    '/oauth/introspect',
    200,
    seed: 1,
)->each(function (GeneratedResponseCase $case): void {
    $model = ObjectSerializer::deserialize($case->bodyAsObject(), IntrospectResponse::class);
    $case->assertRoundTrip(ObjectSerializer::sanitizeForSerialization($model));
});
```

Laravel tests using `ExploresOpenApiEndpoint` can call
`$this->exploreResponseSchema(...)` with the same arguments except the spec
name. If `seed` is omitted, response exploration uses the replayable default
seed `0`. See the [SDK round-trip guide](sdk-roundtrip.md) for the complete case
contract, fidelity rules, deterministic replay, and unsupported response
outcomes.

To replace one-test-per-response maintenance with a loud spec-wide gate,
register explicit SDK mappings on `OpenApiResponseExplorer::exploreSpec()`:

```php
OpenApiResponseExplorer::exploreSpec('front', seed: 1)
    ->includeTags(['public'])
    ->mapResponse(
        'introspect',
        200,
        static fn (GeneratedResponseCase $case): mixed => sdk_decode($case->bodyAsObject()),
        static fn (mixed $model): mixed => sdk_encode($model),
    )
    ->failOnUnmapped()
    ->assertRoundTrips();
```

The plan uses the whole-spec tag/method/path/operation/deprecated filters and
stable per-operation crc32 seeds. Exact, range, and `default` status mappings
are supported; one mapping exercises every JSON media type for that response.
The summary distinguishes explicit skips from decoder and round-trip failures,
while `failOnUnmapped()` makes newly added unmapped schemas fail CI. Laravel
tests call `$this->exploreResponseSpec()` for the same builder. See the
[SDK round-trip guide](sdk-roundtrip.md#exercise-every-mapped-response-in-a-spec)
for callback and reporting details.

## Explore a whole spec

`exploreSpec()` enumerates the Path Item operations defined by OpenAPI,
including 3.2 `additionalOperations`, then generates and dispatches cases for
every selected method supported by the explorer. It uses the same Laravel spec
resolution as response validation (`#[OpenApiSpec]`, `openApiSpec()`, then
`default_spec`). The two traits are designed to be composed:

```php
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\ExploredOperation;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ExploresOpenApiEndpoint;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

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
                HttpMethod::GET => $this->get($case->uri('/api'), $case->headers),
                HttpMethod::POST => $this->postJson($case->uri('/api'), $case->bodyAsArray() ?? [], $case->headers),
                default => throw new LogicException('Add the method to the test dispatcher.'),
            })
            ->assertResponses(); // ValidatesOpenApiSchema auto_assert validates each response

        self::assertFalse($summary->hasSkips(), $summary->skips[0]->reason ?? '');
    }
}
```

### Framework-agnostic exploration

`ExploresOpenApiEndpoint` is Laravel-only; there is no Symfony equivalent.
Symfony, Pest, and plain PHPUnit users call `OpenApiSpecExplorer::explore()`
directly. It returns the same filter/hook builder, so everything below applies
unchanged:

```php
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\OpenApiSpecExplorer;

$summary = OpenApiSpecExplorer::explore('petstore', casesPerOperation: 20, seed: 7)
    ->dispatchUsing(fn (ExploredCase $case) => $this->sendRequest($case))
    ->assertResponseUsing(fn (mixed $exchange) => $this->assertExchangeIsValid($exchange));
```

`dispatchUsing()` returns whatever your client produces and
`assertResponseUsing()` validates it. The runnable
[`examples/psr7`](https://github.com/studio-design/gesso/tree/main/examples/psr7)
suite demonstrates this with `OpenApiPsr7Validator` assertions.

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
  `ExploredCase`. Its `withBody()`, `withQuery()`, `withHeaders()`,
  `withCookies()`, and `withPathParams()` helpers support credentials, stateful
  IDs, and other request-specific changes without mutating shared state.

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
curl output include the concrete generated request. `curlSnippet()` redacts
sensitive header values (`Authorization`, `Cookie`, API-key/token/secret-style
names) as `<redacted>` so the command is safe to paste into CI logs; pass
`redactSensitiveHeaders: false` to keep the raw values.

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
| Strings | min/max length, common regex patterns (including anchored character classes with fixed or `+` quantifiers, FQDN labels with a fixed domain suffix, and phone-number alternations), Unicode code points, Faker-backed email/UUID/date/time/URI/host/IP formats | below/above length, pattern miss, invalid recognized format |
| Numbers | inclusive/exclusive bounds and `multipleOf`, including OAS 3.0 boolean-exclusive lowering | outside/equal-exclusive bound, non-multiple |
| Arrays | `items`, `prefixItems`, min/max items, `uniqueItems` | too few/many or duplicate items |
| Objects | properties, required, min/max properties, schema-valued/default additional properties | missing required, extra forbidden, too few/many properties, nested property constraint |
| Composition | Request cases rotate `oneOf`/`anyOf` and conditional branches. Response payload exploration guarantees at least one case for every branch of every reachable composition, nullable, and optional-property choice point. Both merge `allOf`/range assertions and preserve lowered discriminator branches. | deterministic composition miss where one can be isolated |

Arbitrary regex synthesis, recursive schemas, `contains`,
`patternProperties`, `dependentSchemas`, and `unevaluated*` generation are not
currently strategies. Those keywords remain validator features; an operation
whose valid value cannot be synthesized fails locally with a supported-subset
diagnostic or is an explicit whole-spec skip carrying that reason.

Branch-complete response enumeration is bounded to 32 nested property/item
levels, 256 collected choice points, and 10,000 visited schema nodes across all
branch contexts. Exceeding any limit fails locally with a supported-subset
diagnostic; Gesso never returns partial branch coverage.

- Request cases alternate optional object properties between included and omitted. Response payload exploration pins both presence branches for every reachable optional property.
- Required keys are always emitted.
- Path resolution accepts both the spec template form (`/v1/pets/{petId}`) and concrete URIs that match it (`/api/v1/pets/123` with `strip_prefixes=/api`). Captured URI values are intentionally discarded — `pathParams` is always regenerated from the operation spec for consistency.

## `seed` and determinism

When [`fakerphp/faker`][faker] is installed, generation uses Faker's
locale-aware primitives and is deterministic for a given `seed:`. Without it,
ordinary strings and scalar boundaries use deterministic counter-based values.
Supported character-class patterns sample multiple class members after the
first boundary case, so increasing `cases:` also increases their input variety.
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
    $response = $this->postJson('/api/v1/pets', $case->bodyAsArray() ?? []);
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

## Named contract checks

Schema validation cannot see protocol-level contract holes. Named checks probe
them directly, mirroring [Schemathesis check names][schemathesis-checks] where
behavior matches. The pipeline is opt-in per check, deterministic under
`seed`, and collects every result before you assert — one run reports all
holes, not just the first:

```php
use Studio\Gesso\Fuzz\ContractCheck;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\OpenApiContractChecks;

$summary = OpenApiContractChecks::run('petstore', seed: 7)
    ->checks([
        ContractCheck::IgnoredAuth,
        ContractCheck::MissingRequiredHeader,
        ContractCheck::UnsupportedMethod,
    ])
    ->dispatchUsing(fn (ExploredCase $case): int => $this->send($case)->getStatusCode())
    ->report();

self::assertSame([], $summary->failures, $summary->describeFailures());
```

| Check | Probe | Default pass statuses |
|---|---|---|
| `ignored_auth` | per operation with an effective `security` requirement: the valid request with no credentials, then again with credentials the API cannot have issued | `401`, `403` |
| `missing_required_header` | per `required: true` header parameter: the valid request with that one header omitted, gated behind a 2xx control request (repeated on state-changing methods to measure isolation) | any `4xx` |
| `unsupported_method` | one deterministically chosen undocumented method per documented path | `405` |

`dispatchUsing()` may return an `int` status, a PSR-7 response, or any object
exposing `getStatusCode(): int` (Symfony `Response`, Laravel `TestResponse`).
The same `includeTags()`/`excludePaths()`/… filters and
`setUpUsing()`/`authenticateUsing()`/`tearDownUsing()` hooks as whole-spec
exploration apply; a path is probed when at least one of its documented
operations passes the filters. `ignored_auth` is the one exception to
`authenticateUsing()`: its probes deliberately bypass the hook, because
handing them credentials is exactly what the check exists to prevent.

Override an expectation per check with
`expectedStatuses(ContractCheck::UnsupportedMethod, [405, 404])` for frameworks
that answer unknown methods with 404, or
`expectedStatusClasses(ContractCheck::IgnoredAuth, [4])` to accept any client
error. Either call replaces the check's whole default expectation, so
`expectedStatuses(ContractCheck::MissingRequiredHeader, [400])` means exactly
400 — the default `4xx` class no longer widens it.

### `ignored_auth`

An operation is probed when its effective `security` — operation-level if
declared, root-level otherwise — actually demands credentials. `security: []`
and a requirement list containing an empty `{}` entry both document
unauthenticated access, so they are skipped rather than reported. Anything else
malformed is a hard error instead — reading it as "no authentication required",
or probing a declaration the runtime validator rejects, would turn a broken spec
into a green run. That covers the outer node (`security: "not-a-list"`, a scalar
requirement entry) and the inside of each entry: non-list scopes, non-string
scope items, undefined scheme names, and malformed scheme definitions. The
detection rules are the runtime validator's own
(`SecurityValidator::inspectRequirementPair()`), shared rather than
re-derived, so the two cannot disagree about which specs are broken.

The invalid-credential probe writes a placeholder into every credential
location the operation declares (all of them, so an AND-style requirement is
genuinely exercised): `Authorization: Bearer …` for `http` + `bearer`, and the
declared header / query / cookie name for `apiKey`. Those are the schemes
[request validation can locate](supported-features.md#security-schemes);
operations secured
solely by `oauth2`, `openIdConnect`, `mutualTLS`, or non-bearer `http` schemes
get the no-credential probe plus a skip for the other one, rather than a
fabricated credential in a guessed location.

The no-credential probe also strips any `apiKey` header, query, or cookie value
the generated valid case happened to carry, so it is genuinely credential-free.
It cannot, however, see credentials your own `dispatchUsing()` closure adds —
send the case as-is there.

**Cookie credentials require dispatcher cooperation.** `apiKey` + `in: cookie`
values land in `$case->cookies`, not in a `Cookie:` request header, because
Laravel's and Symfony's test clients build their cookie bag from a separate
`SymfonyRequest::create()` argument — a header alone leaves
`$request->cookie(...)` empty. A dispatcher that drops `$case->cookies` makes
the invalid-credential probe identical to the no-credential one, and an API
that accepts any cookie value would pass. Forward them:

```php
->dispatchUsing(fn (ExploredCase $case): int => $this->call(
    $case->method->value,
    $case->uri(),
    [],
    $case->cookies,      // <- not optional for cookie-secured operations
    [],
    $this->transformHeadersToServerVars($case->headers),
    $case->body !== null ? (string) json_encode($case->body) : null,
)->getStatusCode())
```

### `missing_required_header`

One probe per `required: true` `in: header` parameter, each omitting exactly
that header so the failure names the one that was not enforced.
`Accept`/`Content-Type`/`Authorization` parameter declarations are excluded:
per OAS 3.x §4.7.12.1 they are ignored, and content negotiation and security
schemes own them. Operations that declare no required header are skipped with
a reason.

The default expectation is the `4xx` class rather than a status list, because
frameworks answer a missing required header with 400, 406, 422, or a
scheme-specific 401/403 — pinning one of them would report framework choice as
contract drift.

Accepting a family that wide is only sound with a control: each operation first
dispatches the **unmutated** valid case, and the omission probes run only when
that control answers a 2xx. Without it, an operation that never enforces the
header would score green because the request was rejected for an unrelated
reason — no credentials configured, a missing fixture, a 404. A 3xx does not
qualify either: Laravel answers an unauthenticated non-JSON request with a 302
to the login page, and comparing the omission probes against that redirect
proves nothing. When the control does not return 2xx, the operation is skipped
with its status in the reason, so use `setUpUsing()` / `authenticateUsing()` to
make the valid request succeed. The control counts toward
`$summary->dispatchedProbes`.

State-changing methods need one more gate. On `POST` / `PUT` / `PATCH` /
`DELETE`, the control request's own effect can answer the probes that follow it
— a duplicate create is a 409, an already-deleted resource is a 404, and both
sit inside the accepted 4xx class — so the status cannot distinguish them from
real header enforcement.

Whether your dispatcher isolates state between requests is not something the
plan can read off its configuration: `setUpUsing()` / `tearDownUsing()` are
general hooks that may exist for authentication, logging, or fixtures, and a
no-op one would prove nothing. So it is **measured, not declared**: the control
is dispatched a second time and must answer identically. Under real isolation
the two dispatches are indistinguishable; a different answer the second time
means the first dispatch left state behind, and the operation is skipped with
both statuses in the reason. Reset state around every dispatch (both hooks run
around each one, so a transaction rollback there works) or use
`excludeMethods()`. `GET` and `QUERY` are safe methods (RFC 9110 §9.2.1) and
skip this second dispatch entirely.

### `unsupported_method`

Probe construction: candidates are the explorer-supported methods (`GET`,
`POST`, `PUT`, `PATCH`, `DELETE`, `QUERY`) minus every method the path
documents — fixed fields and `additionalOperations` keys, matched
case-sensitively, so a (spec-invalid but runtime-honored) `"PUT"` entry
under `additionalOperations` removes `PUT` from the candidates while a
custom `"copy"` entry removes nothing. OpenAPI 3.2 `additionalOperations`
are never probed themselves (case-sensitive custom methods). Concrete path
parameters are generated from a documented operation of the same path; the
probe sends no body, query, or headers, so only path-parameter
generatability gates it — a documented operation whose required request
body is not JSON (for example a form-encoded OAuth token endpoint) does
not prevent the probe. When the concrete probe URI is also an instance
of a different documented template (`/members/me` alongside
`/members/{member_id}`), methods documented by that colliding template are
excluded too — the application would route the probe to the documented
operation, so a failure there would be a false positive. Paths where every
explorable method is documented, where every remaining candidate collides
with another template, or where no documented operation's path parameters
can be generated, appear in `$summary->skips` with a reason.

Two caveats: `HEAD`/`OPTIONS`/`TRACE` are outside the explorer's method set
and are not probed, and a probe response cannot pass normal response
validation (its method is undocumented by definition) — disable Laravel's
`auto_assert` in contract-check tests or dispatch outside the trait's helpers.

### Reading the results

`ContractCheckFailure::describe()` names the check, the operation, the mutation
the probe dispatched, the expected and actual status, and a replayable curl
command:

```text
ignored_auth: GET /orders/{orderId} (showOrder) [no credentials] — expected 401 or 403, got 200
  Curl: curl -X GET '/orders/42'
```

Every reason a probe was not dispatched lands in `$summary->skips` as a
`ContractCheckSkip` carrying the check, path, method (null for path-level
skips), and an explanation. Nothing is dropped silently — a check that found no
work says so.

### Compared with Schemathesis

Check names match [Schemathesis][schemathesis-checks] so cross-tool
documentation and CI dashboards line up, and the probes match its behavior:
`ignored_auth` sends two extra requests per operation (no credentials, then
invalid ones), `unsupported_method` expects 405, and
`missing_required_header` expects a client error. Two deliberate differences:
Gesso accepts 403 alongside 401 for `ignored_auth` (many PHP frameworks answer
an unauthenticated request with 403), and it does not assert the RFC 9110
`Allow` header on a 405 — only the status. The rest of the Schemathesis check
catalog (`use_after_free`, `ensure_resource_availability`,
`max_response_time`, and the response-conformance family) is out of scope here;
response conformance is what `OpenApiResponseValidator` and the coverage
tracker already cover.

[schemathesis-checks]: https://schemathesis.readthedocs.io/en/stable/reference/checks/

## Remaining gaps

- Arbitrary ECMA-262 regex synthesis and recursive/reference-cycle generation.
- Cookie and `parameters[].content` fuzz generation.
- Structural shrinking inside nested arrays/objects; current reduction removes
  top-level body members.
- Measurement-based feature parity with Schemathesis.

[schemathesis]: https://github.com/schemathesis/schemathesis
[faker]: https://github.com/FakerPHP/Faker
