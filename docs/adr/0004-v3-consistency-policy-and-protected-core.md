# ADR 0004: v3 consistency policy and protected core

- Status: Accepted
- Date: 2026-08-06
- Issue: [#500](https://github.com/studio-design/gesso/issues/500)
- Related: [#499](https://github.com/studio-design/gesso/issues/499),
  [#501](https://github.com/studio-design/gesso/issues/501),
  [#502](https://github.com/studio-design/gesso/issues/502),
  [#503](https://github.com/studio-design/gesso/issues/503),
  [#504](https://github.com/studio-design/gesso/issues/504),
  [#505](https://github.com/studio-design/gesso/issues/505),
  [#506](https://github.com/studio-design/gesso/issues/506),
  [#507](https://github.com/studio-design/gesso/issues/507),
  [#508](https://github.com/studio-design/gesso/issues/508)
- Supersedes: none

## Context

The v3 milestone reduces surface area rather than capability: one configuration
location, names that match meaning, one output envelope. That direction is only
safe if two things are written down before the first reduction PR, because both
are currently implicit.

**What must never regress while we shrink.** The behaviours that make Gesso a
contract-testing tool rather than a JSON validator are enforced today by
scattered code and fixtures, not by a stated policy. Three of them are not
SemVer-covered at all: `Spec\OpenApiSchemaDialect` is `@internal`
(`src/Spec/OpenApiSchemaDialect.php:20`), `Validation\Support\DiscriminatorEnforcement`
is `@internal` (`src/Validation/Support/DiscriminatorEnforcement.php:32`), and the
doctor ⇄ runtime consistency rule exists only as one sentence of agent guidance
(`AGENTS.md:66-68`). A refactor could satisfy every letter of
[the versioning policy](../versioning.md) and still gut them.

**What a reduction PR must prove.** Two mechanical conformance gates already
exist under `tests/Integration/Conformance/`, but nothing states that they are
the acceptance criterion for reduction work.

ADR 0001 solved the equivalent problem for v2 with its
[scope boundaries](0001-gesso-v2-identity.md#scope-boundaries) — a list of what
the rename did *not* authorize. That device stopped scope creep then, and v3
planning invites the same pressure now.

## Decision

v3 changes shape, not capability. Nothing is added and nothing is removed.

Every v3 change must be expressible as one of: moving a configuration key to the
single entry point, renaming a symbol/flag/key to match its meaning, or
collapsing two output shapes into one. A change that alters what Gesso decides
about a request, a response, or a schema is not a v3 change, regardless of how
much surface area it would save.

## Protected core

Seven invariants. Renaming or relocating their carriers is allowed — that is
what v3 is for. Weakening the behaviour is not, at any version boundary, without
an ADR that supersedes this one.

| Invariant | Anchor today | SemVer-covered? |
| --- | --- | --- |
| OpenAPI 3.0 / 3.1 / 3.2 are each validated under their own JSON Schema dialect: 3.0 through the Draft 07 compatibility pipeline, 3.1/3.2 under 2020-12, and an unknown dialect is rejected rather than guessed | `src/OpenApiVersion.php:19-21`; `src/Spec/OpenApiSchemaDialect.php:34` (3.0 → Draft 07), `:38` (default OAS 3.1 base), `:57` (`assertSupported()`) | No — the class is `@internal` |
| Validation is tri-state. `Skipped` is a distinct outcome, never folded into success or failure | `src/OpenApiValidationOutcome.php:15-17` | Yes — `docs/versioning.md:18-19` |
| Discriminator enforcement is on by default | `src/Validation/Support/DiscriminatorEnforcement.php:36` (`private static bool $enabled = true`) | No — the class is `@internal` |
| The named contract checks and their default expected statuses | `src/Fuzz/ContractCheck.php:13-15` (`ignored_auth`, `missing_required_header`, `unsupported_method`) | Yes — `docs/versioning.md:83-88` |
| Coverage and gating are measured at `(method, path, status, content-type)` granularity | `src/Validation/Response/ResponseSchemaResolver.php:27`, `src/Coverage/OpenApiCoverageTracker.php:232`, `src/Cli/CoverageGateCommand.php:64` | Partly — only through the coverage baseline entry, `docs/versioning.md:67-82` |
| Doctor diagnostics and runtime validation agree: a malformed spec node must not pass one path and fail the other | `AGENTS.md:66-68`; mirrored in code at `src/Cli/DoctorCommand.php:537` (response guards) and `:747` (security-scheme classification) | No |
| Both upstream corpora stay pinned by commit SHA and stay measured | `composer.json` `repositories[].package.dist.reference`; asserted by `tests/Integration/Conformance/` | No — test fixtures |

The three uncovered rows are the reason this ADR exists. `@internal` means the
symbol may be renamed, moved, or merged without a major bump; it does not mean
the behaviour behind it is negotiable.

## Inclusion criterion

> Anything that must keep running after the PHPUnit or Pest process exits does
> not belong in Gesso.

Gesso is a test-time library. Its unit of work is one assertion inside one test,
and its lifetime is the test process. Everything in the package today satisfies
that; the criterion exists to keep it that way.

## Non-goals

Each entry names the half of the criterion it fails.

| Non-goal | Why it is out |
| --- | --- |
| An MCP server | Long-running process. It would outlive the test run by definition. |
| A mock server | Long-running process, and it inverts the direction: Gesso checks a real implementation against the spec, it does not stand in for one. |
| An Overlay merge engine | Not a test-time contract check. Applying an Overlay produces a spec; that is a spec-authoring or build step, and its output is what Gesso should be handed. |
| oasdiff-style spec diffing | Not a test-time contract check. `CoverageGateCommand` already draws this line explicitly — its diff is structural ("did the resolved node change?"), never semantic ("is the change backwards-incompatible?"), and the semantic classification needs a rule catalogue that belongs to a dedicated tool (`src/Cli/CoverageGateCommand.php:67-70`). |
| An official GitHub Action | Not a test-time contract check. It is a distribution wrapper around one, and `bin/gesso` already runs unmodified in any CI job. |
| A general-purpose spec linter | Not a test-time contract check. `docs/doctor.md` states this for `gesso doctor` already: it is not a replacement for Spectral or Redocly. |
| Spec generation | Not a test-time contract check. Generating the spec from code makes the spec a mirror of the implementation, which removes the thing Gesso is testing against. |
| Gesso calling an LLM | Not a test-time contract check. A nondeterministic network call inside a test run cannot produce a verdict a test can rely on. Gesso's output is consumed by agents; nothing inside it consults one. |
| OpenAPI 4.0 / Moonwalk support | This one does not fail the criterion. It is out because there is no stable specification to validate against yet. Revisit when there is; it needs its own ADR, not an extension of this one. |

## Reduction-PR acceptance gate

A PR whose stated purpose is reducing surface area must leave both conformance
baselines byte-identical:

- `tests/fixtures/compatibility/v1-json-schema-conversion-delta.json` —
  `format_version: 1`, **11 deltas** (9 `unsupported-dialect`, 2
  `stripped-annotation-ref`) and **2 statically excluded groups** (both
  `$dynamicRef` under an `unevaluated*` applicator, where bare opis exhausts the
  stack). 3,784 of the corpus's 3,824 cases are actually compared; the corpus is
  pinned at `cf2e5e0ff2e3d90239c3b59e68ac4c080bd4ac92` and the test fails if the
  installed SHA differs from the baseline.
- `tests/fixtures/compatibility/v1-oas-example-documents.json` — **22 documents**,
  `gesso doctor` exit `0` on all 22. Two entries, the JSON and YAML forms of
  `v3.1/tictactoe`, each carry 3 `skipped/feature` security-scheme diagnostics;
  every other document is clean. The corpus is pinned at
  `43756549c27cbf84107b190b82c65e0336f2f09f`.

Either baseline moving means the PR changed meaning, not shape. That is not
forbidden — it needs its own decision record and its own review, and it stops
being a reduction PR.

Both gates run in the `Integration` suite, which every CI matrix job executes
(`.github/workflows/ci.yml:59-60`), so no extra step is required to enforce this.

## What v3 does not delete

Enum drift (`src/Schema/`), SDK exercise coverage
(`src/Coverage/SdkExerciseCoverageTracker.php`,
`src/Coverage/SdkExerciseCoverageReportBuilder.php`), and `src/Fuzz/` all stay.

They are recent, and downstream measurement supports keeping them: one consumer
runs enum drift with fail-on-drift enabled over 52 backed enums, and another uses
the named contract checks across 2 files and 246 lines. v3 records the boundary
in the naming — `src/Fuzz/` holding the named contract checks is a misfiling that
[#503](https://github.com/studio-design/gesso/issues/503) corrects — and defers
the carve-out question to v4.

This is a deliberate constraint on v3, not an endorsement of the current split.
A v4 that removes any of them needs its own ADR and its own evidence.

## Scope boundaries

Inherited from ADR 0001 and restated for v3. The consistency milestone does not
authorize:

- splitting the repository into multiple Composer packages;
- a major upgrade of `opis/json-schema` or another production dependency;
- rewriting the validator, coverage engine, or framework adapters wholesale;
- changing OpenAPI validation semantics solely because a major version is
  available.

ADR 0001's boundaries were written for the v2 identity migration and still hold
on their own terms; this ADR extends the device rather than replacing it.

## Consequences

Three behaviours that SemVer does not cover become policy-protected: dialect
selection, the discriminator-enforcement default, and doctor ⇄ runtime
consistency. Their carriers stay `@internal` and freely renameable.

Three surfaces that SemVer *does* cover become protected beyond it — the
`OpenApiValidationOutcome` cases (`docs/versioning.md:18-19`), the `ContractCheck`
names and default statuses (`:83-88`), and the coverage baseline granularity
(`:67-82`). They may not be dropped even at a major boundary without a
superseding ADR.

The reduction gate makes "did this change meaning?" a mechanical question rather
than a review judgement, which is what lets the v3 renames be reviewed as
renames.

ADR 0002's phases and ADR 0003's phase list stay open. In particular this ADR
does not retract ADR 0003 phase 6, the SDK exercise coverage that already
shipped.
