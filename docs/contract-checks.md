# Named contract checks

Schema validation cannot see protocol-level contract holes. Named checks probe
them directly, mirroring [Schemathesis check names][schemathesis-checks] where
behavior matches. The pipeline is opt-in per check, deterministic under
`seed`, and collects every result before you assert — one run reports all
holes, not just the first.

The checks live in `Studio\Gesso\Contract\`; they consume generated cases from
`Studio\Gesso\Fuzz\` (`ExploredCase`, `ExploredOperation`), never the
reverse. The `Studio\Gesso\Fuzz\` spellings still resolve through a
deprecated alias until v3.0 — see [Deprecations in `UPGRADING.md`](https://github.com/studio-design/gesso/blob/main/UPGRADING.md#deprecations).

```php
use Studio\Gesso\Contract\ContractCheck;
use Studio\Gesso\Contract\OpenApiContractChecks;
use Studio\Gesso\Fuzz\ExploredCase; // still Fuzz\: a generated case

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
| `missing_required_header` | per `required: true` header parameter: the valid request with that one header omitted, gated behind a 2xx control request; state-changing methods need `dispatchIsolatedUsing()` | any `4xx` |
| `unsupported_method` | one deterministically chosen undocumented method per documented path | `405` |

`dispatchUsing()` may return an `int` status, a PSR-7 response, or any object
exposing `getStatusCode(): int` (Symfony `Response`, Laravel `TestResponse`).
The same `includeTags()`/`excludePaths()`/… filters and
`setUpUsing()`/`authenticateUsing()`/`tearDownUsing()` hooks as whole-spec
[exploration](fuzzing.md) apply; a path is probed when at least one of its documented
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
    $this->transformHeadersToServerVars(['Content-Type' => $case->contentType, ...$case->headers]),
    $case->body !== null ? $case->bodyAsString() : null,
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

**State-changing methods are skipped by default.** On `POST` / `PUT` / `PATCH`
/ `DELETE`, the control request's own effect can answer the probes that follow
it — a duplicate create is a 409, an already-deleted resource is a 404, and
both sit inside the accepted 4xx class — so the status cannot distinguish them
from real header enforcement.

Nothing observable from inside the plan tells an isolated dispatcher from a
leaky one. Repeating the control does not: an idempotent create answers 201
twice and still leaves the row behind, so the next probe collides with it.
General hooks do not either — `setUpUsing()` / `tearDownUsing()` may exist for
authentication, logging, or fixtures, and a no-op one proves nothing. So the
decision is the caller's, stated on the dispatcher that has to honour it:

```php
->dispatchIsolatedUsing(fn (ExploredCase $case): int => $this->send($case)->getStatusCode())
```

`dispatchIsolatedUsing()` registers the dispatcher **and** promises that every
dispatch starts from state indistinguishable from the one before it — a
transaction rolled back around each call, a database refreshed per call, a
fixture rebuilt per call. Only make that promise where the isolation is really
implemented; the plan cannot check it. `dispatchUsing()` is the same dispatcher
without the promise, and leaves state-changing methods skipped with the reason
naming this method (calling it after `dispatchIsolatedUsing()` withdraws the
promise). `GET` and `QUERY` are safe methods (RFC 9110 §9.2.1) and are probed
either way.

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
not prevent the probe. The per-operation checks such as `ignored_auth` do
send a body; a form-encoded one is synthesized, so send it with
`$case->contentType` and `$case->bodyAsString()`. When the concrete probe URI is also an instance
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
