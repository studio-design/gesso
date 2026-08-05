# ADR 0002: phase Arazzo workflow execution behind shared runtime expressions

- Status: Accepted
- Date: 2026-07-30
- Issue: [#409](https://github.com/studio-design/gesso/issues/409)
- Related: [#400](https://github.com/studio-design/gesso/issues/400)

## Context

[Arazzo 1.1.0](https://spec.openapis.org/arazzo/v1.1.0.html) describes
multi-step API workflows. A workflow can call operations from multiple source
descriptions, pass values between steps with runtime expressions, assert
success criteria, and redirect control through success and failure actions.

This overlaps Gesso in two useful ways:

- Each OpenAPI step can be checked by the existing request and response
  validators, rather than treating the workflow's success criteria as the only
  contract.
- OpenAPI Links-based stateful exploration in
  [#400](https://github.com/studio-design/gesso/issues/400) needs the same HTTP
  runtime-expression evaluator and ordered dispatch context.

It does not fit directly into the current OpenAPI loader or fuzz explorer.
`OpenApiSpecLoader` requires an `openapi` root field, selects an OpenAPI schema
dialect, and resolves OpenAPI references eagerly. An Arazzo description has an
`arazzo` root and different reference, source, and execution semantics.
`ExploredCase` is generated from one OpenAPI operation and carries an
`HttpMethod` enum; an Arazzo step starts from authored values, can invoke
another workflow, and needs the previous step's request and response context.

The Arazzo schema can catch much of the document shape, but the specification
explicitly makes its prose authoritative over the schema. A loader therefore
cannot stop at schema validation. It must also check cross-field and
cross-document rules such as unique IDs, mutually exclusive operation targets,
source-qualified operation IDs, and resolvable workflow dependencies.

## Decision

Proceed with Arazzo support in phases, but do not add an executor or advertise
Arazzo support until the shared runtime-expression and source-resolution
boundaries exist.

The first executable scope will be synchronous workflows whose steps target
OpenAPI operations. It will be described as an OpenAPI workflow runner, not as
complete Arazzo 1.1 conformance. Unsupported Arazzo features will fail before
dispatch with a structured reason; they will not be ignored or counted as
successful.

The implementation belongs in the main package initially:

- JSON parsing needs no dependency.
- YAML can keep the existing optional `symfony/yaml` behavior.
- The caller-owned dispatch hook avoids adding an HTTP implementation.
- PSR-7, PSR-17, and PSR-18 interfaces are already runtime dependencies.
- The runtime-expression evaluator is also needed by the core Links explorer.

An optional package becomes worthwhile only if AsyncAPI execution or advanced
criterion engines introduce transport or expression dependencies that the
OpenAPI path does not need.

## Proposed execution boundary

The runner will keep policy in a framework-independent core. Adapters only
translate a framework request or response at the dispatch boundary.

For each OpenAPI step, the core will:

1. Resolve exactly one target (`operationId` or `operationPath`) to a named
   source, method, path template, and operation.
2. Evaluate workflow and step parameters and the request body into an immutable
   request value.
3. Run `OpenApiRequestValidator` before dispatch. An invalid authored request
   stops the workflow.
4. Pass the request value and step metadata to the caller's dispatch callback.
5. Normalize the callback result to a status, headers, content type, and
   decoded body.
6. Run `OpenApiResponseValidator` against the same named source. A contract
   failure is distinct from a failed Arazzo success criterion.
7. Store the request, response, and declared step outputs in an immutable
   execution context for later runtime expressions.

This proves that the existing validators and callback-style exploration API
are reusable. The new work is document/source loading, target indexing,
request/response normalization, expression evaluation, control flow, and
workflow-specific reporting.

## Runtime-expression boundary

Arazzo 1.1 runtime expressions are substantially larger than
`$response.body#/id`. They include request and response values, workflow
inputs/outputs, prior steps and workflows, source descriptions, reusable
components, `$self`, and message payloads. Expressions can also be embedded in
strings. Selector Objects and `simple`, `regex`, `jsonpath`, and `xpath`
criteria add separate evaluation languages.

Implement the shared evaluator as typed context lookups, not string
substitution:

- Phase one: `$url`, `$method`, `$statusCode`, request/response headers, query
  and path parameters, request/response bodies with JSON Pointer, `$inputs`,
  and prior step outputs.
- Parse a whole-value expression separately from an embedded expression so a
  whole expression preserves integers, booleans, arrays, objects, and `null`.
- Make header lookup case-insensitive and preserve parameter-name case.
- Use one JSON Pointer implementation for Arazzo and OpenAPI Links.
- Reject unavailable context, malformed pointers, unsupported expression
  families, and non-scalar embedded results explicitly.
- Add workflow/source/component expressions only with the feature that owns
  their lifecycle.

`jsonpath` and `xpath` criteria are not part of the first executor. Adding a
general JSONPath package would increase the production dependency and security
surface, while XPath also depends on an XML representation that Gesso does not
currently validate. The first runner will accept only the deliberately
implemented `simple` criterion subset and will reject the other criterion
types before dispatch.

## Source descriptions and loading

Create an Arazzo-specific loader rather than weakening `OpenApiSpecLoader`'s
root invariant.

The loader may reuse a neutral JSON/YAML decoder after its `$ref`-specific
diagnostics are generalized. It must then:

- accept the supported `arazzo` feature family and validate required root
  fields;
- establish the base URI as Arazzo's "Establishing the Base URI" section
  requires: an absolute `$self` is the base URI, a relative `$self` is first
  resolved against the retrieval URI, and the retrieval URI of the Arazzo
  document is the base only when `$self` is absent;
- map every unique `sourceDescriptions[].name` to a typed source handle;
- load local OpenAPI sources through the same canonical-path and traversal
  protections as the OpenAPI loader;
- apply the existing opt-in, exact-host allowlist, byte-limit, and redirect
  protections to remote sources. There is no address-level protection to
  reuse: `HttpRefLoader::assertHostAllowed()` compares the normalized hostname
  against the configured list and nothing else, and `docs/setup.md` states
  that DNS and network-layer controls remain the application's responsibility
  because PSR-18 does not expose connection-level address policy. If Arazzo
  needs more than the OpenAPI loader offers here, that is new work to scope,
  not an existing guarantee;
- reject `asyncapi` and nested `arazzo` sources until their execution semantics
  are implemented;
- index `operationId` values across sources and require the runtime-expression
  form `$sourceDescriptions.<name>.<operationId>` whenever more than one
  non-`arazzo` source description is defined. Arazzo makes that a MUST from
  the second source onwards, independently of whether the IDs actually clash,
  so the check is on the source count, not on collision detection. `workflowId`
  carries a separate condition that phase 4 inherits and must not be folded
  into this one: qualification is a MUST whenever the referenced workflow lives
  in an `arazzo` source description, no matter how many sources are defined;
  and
- retain enough source identity that request/response validation and coverage
  are attributed to the correct OpenAPI description.

Remote source loading must not become an implicit network action merely because
an Arazzo document is parsed.

## Blockers identified by the spike

These are implementation tasks, not reasons to abandon the feature:

1. There is no source-neutral document loader. Current decoder diagnostics and
   reference resolution are phrased and structured around OpenAPI `$ref`
   targets.
2. There is no operation-ID index across named specs. Existing validation
   starts with method and request path.
3. There is no runtime-expression parser or typed execution context. This is
   the direct shared blocker with #400.
4. Existing exploration dispatch values do not represent cookies, a raw
   querystring, arbitrary HTTP methods, server selection, or prior-step state.
5. Existing response extraction is adapter-specific. A workflow needs one
   normalized result before it can validate the response and expose it to the
   next step.
6. Validator results model one exchange. Workflow reporting needs ordered
   steps and must distinguish request-contract, dispatch, response-contract,
   criterion, and control-flow failures without changing validator error prose.
7. Full Arazzo 1.1 also includes workflow calls, dependency graphs,
   success/failure actions, retries, polling, AsyncAPI operations, Selector
   Objects, and multiple criterion languages. Treating an unknown field as a
   sequential no-op would produce false green workflows.

## Delivery phases

### 1. Shared runtime expressions

Tracked by
[#427](https://github.com/studio-design/gesso/issues/427).

Build and test the HTTP/JSON-Pointer expression subset needed by both OpenAPI
Links and Arazzo. Keep it internal until its supported syntax and failure model
are proven by both callers.

### 2. Arazzo document and source preflight

Tracked by
[#429](https://github.com/studio-design/gesso/issues/429).

Load JSON and optional YAML, validate Arazzo 1.1 root/workflow/step structure,
resolve local OpenAPI source descriptions, build the operation index, and
report all unsupported execution features before any request is dispatched.
Remote sources remain opt-in under the existing network policy.

### 3. Synchronous OpenAPI workflow MVP

Tracked by
[#428](https://github.com/studio-design/gesso/issues/428).

Execute ordered OpenAPI steps with `operationId` and `operationPath`,
parameters, JSON request bodies, step outputs, and the supported simple success
criteria. Validate every request and response through Gesso. Add deterministic
ordered failure output and framework-neutral dispatch hooks, then adapter
conveniences.

### 4. Control flow and conformance expansion

Tracked by
[#430](https://github.com/studio-design/gesso/issues/430).

Add workflow calls, `dependsOn`, success/failure actions, retry/poll behavior,
Selector Objects, and additional criteria in separately reviewable increments.
Evaluate an optional package before adding AsyncAPI transports or a new
expression-engine dependency.

Each phase must add malformed-input tests and must fail atomically during
preflight when a document uses a feature that phase cannot execute.

## Consequences

- Gesso can be first in PHP without coupling the initial design to one
  framework or HTTP client.
- #400 and Arazzo share expression semantics rather than growing subtly
  different evaluators.
- The initial release is intentionally narrower than the Arazzo 1.1 document
  model, so support claims must list the accepted source, target, criterion,
  and control-flow subsets.
- Arazzo parsing cannot be presented as a small extension of OpenAPI spec
  loading. It needs a separate preflight and diagnostic vocabulary.
- No production dependency is justified by this spike.

## Revisit criteria

Reconsider the main-package decision if a required criterion or AsyncAPI
transport adds a dependency that is unused by OpenAPI validation and Links
exploration. Reconsider the phased-go decision if the shared expression phase
cannot serve both #400 and Arazzo without exposing caller-specific state in its
API.

## Sources

- [Arazzo Specification 1.1.0](https://spec.openapis.org/arazzo/v1.1.0.html)
- [Arazzo 1.1 schema revisions](https://spec.openapis.org/arazzo/)
- [OpenAPI runtime expressions](https://spec.openapis.org/oas/v3.2.0.html#runtime-expressions)
- [RFC 6901: JSON Pointer](https://www.rfc-editor.org/rfc/rfc6901)
- [Spectral's Arazzo ruleset](https://github.com/stoplightio/spectral)
- [Speakeasy custom Arazzo contract tests](https://www.speakeasy.com/docs/sdks/sdk-contract-testing/custom-contract-tests)
