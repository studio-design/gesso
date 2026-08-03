# ADR 0003: branch-complete response payloads and an SDK round-trip harness

- Status: Accepted
- Date: 2026-07-31
- Issue: [#441](https://github.com/studio-design/gesso/issues/441)
- Related: [#403](https://github.com/studio-design/gesso/issues/403)

## Context

Gesso validates the server ⇄ spec side of a contract. Nothing validates the
SDK ⇄ spec side: whether a generated client SDK can deserialize every payload
the spec allows. That gap shipped studio-design/studio-auth#1520 —
openapi-generator rendered a primitive-only inline `oneOf`
(`string | string[]`) as a property-less wrapper model, and every existing
gate stayed green because none of them fed spec-derived payloads to the
generated decoder.

Most of the machinery already exists:

- `Fuzz\SchemaDataGenerator` generates deterministic values through
  `oneOf`/`anyOf`/`allOf`, but it is `@internal`, resolves branches by
  rotating the case index, and nested compositions share that index in
  lockstep. Branch coverage is therefore best-effort: a case count smaller
  than a branch fan-out, or a branch nested under an optional property (the
  studio-auth incident shape), can go unexercised.
- Response-schema selection by `(method, path, status, content-type)` exists
  only inlined in the response validator, entangled with assertion flow.
- Round-trip re-validation is the existing JSON Schema engine plus
  `SchemaValueValidator`, unchanged.

## Decision

Build a public response-payload explorer in the fuzz family that generates
deterministic, branch-complete valid payloads for a resolved response schema
(or a named component schema) and hands them to user-supplied decode/encode
callbacks, asserting acceptance and round-trip fidelity.

Follow the established facade-over-internal pattern: the documented strategy
matrix in `docs/fuzzing.md` is the contract, `SchemaDataGenerator` stays
`@internal`, and the public surface mirrors the existing explorer family
(`explore*` entry points, readonly case DTOs, `each()` collections that
reject empty runs, `*Using(callable)` hooks, fluent plans with derived
per-operation seeds).

Schema-to-model mapping stays user-side through explicit callbacks per
(operation, status). Guessing codegen naming conventions (openapi-generator,
Kiota, …) is a non-goal.

## Branch-coverage guarantee

Rotation becomes a documented guarantee: for a fixed (schema, seed), every
branch of every reachable composition choice point — `oneOf`/`anyOf`
branches, conditional branches, nullable and optional-property presence —
appears in at least one generated case.

This requires a pre-pass enumerator that collects choice points with their
JSON Pointers, and a pinned per-case selection plan that replaces the shared
iteration cursor. Ancestors of a targeted choice point are forced present so
nested branches are reachable; the incident's `oneOf` sat inside an optional
property and top-level-only coverage would have missed it. The case count is
derived from the enumeration (at least one case per (choice point, branch)
pair) plus caller-requested extras. Existing exclusions (recursive schemas,
`contains`, …) stay explicit and loud, with documented enumeration bounds.

Every generated case keeps the existing self-check against the converted
schema before it reaches user code.

## Round-trip fidelity

`assertRoundTrip($reEncoded)` asserts two things:

1. The re-encoded value validates against the same converted schema, through
   the same validator used for generation self-checks, so generation and
   verification cannot drift.
2. Every key and value of the generated payload survives recursively (arrays
   compared exactly). This catches silent value swallowing — the same
   generator bug minus the TypeError.

Failure messages carry the seed, case index, and pinned branch pointer, in
the style of the existing replayable exploration failures.

## Reuse boundaries

- Response-schema resolution is extracted into an `@internal` resolver shared
  with the response validator, preserving discriminator enforcement, dialect
  selection, and doctor/runtime diagnostic consistency. No re-implementation
  in the new entry point.
- No-content, non-JSON-only, and OAS 3.2 `itemSchema` responses are loud
  structured outcomes, never silent skips.
- Coverage-style reporting of exercised response schemas follows the
  parallel-tracker precedent (own state version, sidecar envelope
  participation, JSON report schema bump, reject-unknown-versions).
- #403 (spec-driven HTTP client fakes) targets consumer application code
  through faked transports; this harness targets the generated decode layer
  in-process. Whichever lands first, both share the generation facade.
- No new production dependency. Faker remains optional; generation stays
  deterministic without it.

## Delivery phases

1. Shared response-schema resolution —
   [#442](https://github.com/studio-design/gesso/issues/442).
2. Branch-complete generation across composition choice points —
   [#443](https://github.com/studio-design/gesso/issues/443).
3. Public response payload explorer with round-trip assertions (MVP that
   catches the incident) —
   [#444](https://github.com/studio-design/gesso/issues/444).
4. Named component schema entry point —
   [#446](https://github.com/studio-design/gesso/issues/446).
5. Spec-wide plan with loud mapping gaps —
   [#447](https://github.com/studio-design/gesso/issues/447).
6. SDK-exercise coverage reporting —
   [#449](https://github.com/studio-design/gesso/issues/449).

Phases 1 and 2 are independent; 3 needs both; 4–6 build on 3. Each phase
adds malformed-input tests and fails loudly on inputs it cannot handle.

## Consequences

- The strategy matrix gains a guarantee, not just a description; future
  generator changes must preserve branch-completeness or bump it visibly.
- The fuzz public surface grows a response-side family covered by the
  compatibility policy; naming and hook shapes are constrained by the
  existing explorer precedent.
- Coverage wire formats gain a new observation kind with its own version
  obligations.
- Request fuzzing keeps its documented rotation strategy until it opts into
  the pinned-plan generator, so existing seeds keep reproducing.

## Revisit criteria

Reconsider the derived-case-count model if real specs make enumeration
explode (many choice points × deep nesting); a bounded covering strategy
with a documented cap would replace it. Reconsider shipping phase 6 if
phases 3–5 show the summary output already serves the "degrades loudly"
requirement without new wire formats.

## Sources

- [OpenAPI 3.2](https://spec.openapis.org/oas/v3.2.0.html)
- [JSON Schema 2020-12](https://json-schema.org/specification)
- [RFC 7662: OAuth 2.0 Token Introspection](https://www.rfc-editor.org/rfc/rfc7662)
- Incident and interim mitigation: studio-design/studio-auth#1520,
  studio-design/studio-auth#1523
- `docs/fuzzing.md` strategy matrix and determinism contract
