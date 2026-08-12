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
| The named contract checks, their default expected statuses, and their default status classes | `src/Fuzz/ContractCheck.php:13-15` (`ignored_auth`, `missing_required_header`, `unsupported_method`), `:23-30` (`defaultExpectedStatuses()`), `:46-52` (`defaultExpectedStatusClasses()` — `missing_required_header` passes on any 4xx, deliberately, so framework choice does not read as contract drift) | Yes — `docs/versioning.md:83-88` |
| Coverage and gating are measured at `(method, path, status, content-type)` granularity | `src/Validation/Response/ResponseSchemaResolver.php:27`, `src/Coverage/OpenApiCoverageTracker.php:232`, `src/Cli/CoverageGateCommand.php:64` | Partly — only through the coverage baseline entry, `docs/versioning.md:67-82` |
| Doctor diagnostics and runtime validation agree: a malformed spec node must not pass one path and fail the other | `AGENTS.md:66-68`; mirrored in code at `src/Cli/DoctorCommand.php:537` (response guards) and `:747` (security-scheme classification) | No |
| Both upstream corpora stay pinned by commit SHA and stay measured | `composer.json` `repositories[].package.dist.reference`; asserted by `tests/Integration/Conformance/` | No — test fixtures |

Six rows are behaviours; the seventh is a measurement commitment. The two
corpora do not cover the other six — they exercise schema conversion and the
loader/doctor path, and nothing else. Unpinning a corpus or dropping either
conformance test is still a protected-core change, because it removes the only
independent evidence Gesso has about those two areas, but it must not be read as
"the corpora prove the protected core".

Each invariant has its own verification source, and a reduction PR has to keep
all of them green — not just the two conformance baselines:

| Invariant | Verified by |
| --- | --- |
| Dialect selection | `tests/Integration/Conformance/JsonSchemaConversionDeltaTest.php` (the delta set *is* the conversion pipeline's observable output), `tests/Unit/Spec/OpenApiSchemaDialectTest.php`, `tests/Unit/Spec/OpenApiSchemaConverterTest.php` |
| Tri-state outcome | `tests/Unit/Compatibility/PublicApiBaselineTest.php` against `tests/fixtures/compatibility/v2-public-api.json`, which records the enum's `cases` map; plus `tests/Unit/OpenApiValidationResultTest.php` |
| Discriminator enforcement default | `tests/Unit/Validation/Response/ResponseSchemaResolverTest.php`, `tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php`, `tests/Unit/ValidatesOpenApiSchemaEnforceDiscriminatorTest.php` |
| Contract-check names and defaults | The public-API baseline records the `cases` map and the two method signatures, but **not** their return values; the values are pinned by `tests/Unit/Fuzz/ContractCheckSummaryTest.php:22` (`[405]`) and `tests/Unit/Fuzz/OpenApiContractChecksTest.php:464` (`[401, 403]`), `:683` (the 4xx family) |
| Coverage granularity | `tests/Unit/Compatibility/MachineReadableFormatsBaselineTest.php` against `tests/fixtures/compatibility/v3-coverage-report.json`, plus the coverage tracker unit tests |
| Doctor ⇄ runtime consistency | Both sides, since agreement is the invariant. Doctor: `tests/Integration/Conformance/OasExampleDocumentTest.php` (corpus) and `tests/Unit/Cli/DoctorCommandTest.php`. Runtime: `tests/Unit/OpenApiRequestValidatorTest.php:3101` (`security_scheme_missing_type_is_hard_spec_error`, the counterpart to `DoctorCommand.php:747`) and `tests/Unit/OpenApiResponseValidatorTest.php:2404` (`malformed_response_status_entry_returns_failure`, the counterpart to `:537`) |
| Pinned corpora | The two conformance tests themselves |

Three invariants — tri-state outcome, contract-check defaults, coverage
granularity — can be broken without either corpus baseline moving. That is the
reason the rule below is stated as necessary, not sufficient.

Four rows are not SemVer-covered: dialect selection, the
discriminator-enforcement default, doctor ⇄ runtime consistency, and the pinned
corpora. Those four are why this ADR exists. `@internal` means the symbol may be
renamed, moved, or merged without a major bump; it does not mean the behaviour
behind it is negotiable. The `Partly` row is covered only through the coverage
baseline entry, so the granularity is contractual where a baseline file names it
and policy-protected everywhere else.

## Inclusion criterion

> Anything that must stay resident after the command that started it exits, or
> that is not part of checking an implementation against its spec, does not
> belong in Gesso.

Two halves, and both are needed. The first excludes anything that has to be
started, waited for, and torn down — a service. The second excludes work that
terminates but is not a contract check.

The first half is deliberately about *residency*, not about the test process.
Gesso already does work outside a test run and must keep being allowed to:
`gesso doctor` is a preflight that runs before the suite, and `coverage:merge`,
`coverage:gate`, and `stubs` are batch steps that run after it —
`CoverageMergeCommand` says so in its own docblock
(`src/Coverage/CoverageMergeCommand.php:56-58`: *"Designed to be invoked as a
separate step after the parallel test run finishes"*). Each is a short-lived
process that reads files, writes files, and exits. That is in scope. A component
that stays up between invocations, holds state across them, or has to be
health-checked is not.

## Non-goals

Most of these fail one half of the criterion. Three do not, and are recorded here
anyway so they are not relitigated — with their real reason rather than a forced
one, because a criterion that stretches to cover everything stops deciding
anything.

**Fails the first half — stays resident.**

| Non-goal | Why it is out |
| --- | --- |
| An MCP server | A process that has to be started and kept up so a client can call it. |
| A mock server | Same, and it inverts the direction: Gesso checks a real implementation against the spec, it does not stand in for one. |

**Fails the second half — not part of checking an implementation against its
spec.**

| Non-goal | Why it is out |
| --- | --- |
| An Overlay merge engine | Applying an Overlay produces a spec. That is a spec-authoring or build step, and its output is what Gesso should be handed. |
| oasdiff-style spec diffing | Spec versus spec, with no implementation in the comparison. `CoverageGateCommand` already draws this line — its diff is structural ("did the resolved node change?"), never semantic ("is the change backwards-incompatible?"), and the semantic classification needs a rule catalogue that belongs to a dedicated tool (`src/Cli/CoverageGateCommand.php:67-70`). |
| A general-purpose spec linter | Spec style, no implementation. `docs/doctor.md:5` states this for `gesso doctor` already: it is not a replacement for Spectral or Redocly. |
| Spec generation | Derives the spec from the implementation, which removes the independent thing Gesso tests against. |

**Out for other reasons.** These pass both halves; they are excluded on their own
terms.

| Non-goal | Why it is out |
| --- | --- |
| An official GitHub Action | A second distribution surface — its own versioning, marketplace listing, and update cadence — for something `bin/gesso` already does in one `run:` line. Duplicated maintenance, no new capability. |
| Gesso calling an LLM | A nondeterministic remote call cannot produce a verdict a test can rely on. Gesso's output is consumed by agents; nothing inside it consults one. |
| OpenAPI 4.0 / Moonwalk support | There is no stable specification to validate against yet. Revisit when there is; it needs its own ADR, not an extension of this one. |

## Reduction-PR acceptance rule

A PR whose stated purpose is reducing surface area must leave both conformance
baselines unchanged. **This is necessary, not sufficient** — the two corpora
cover schema conversion and the loader/doctor path only, so an unchanged
baseline is evidence about those two areas and about nothing else. The full
condition is that every row of the verification table above stays green; the
baselines are singled out because their expected values live in a data file the
same PR can regenerate, so a green suite stays compatible with a moved verdict.

The two baselines:

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

**This is a review rule, not an automated gate.** Be precise about what the
conformance tests do and do not catch, because the difference is where a
regression would slip through:

- They catch *unintentional* drift. Both tests compare the current observation
  against the committed fixture, and both first assert that the installed corpus
  SHA matches the one the baseline was recorded against
  (`JsonSchemaConversionDeltaTest.php:112-117`,
  `OasExampleDocumentTest.php:122-127`), so an implementation change that moves a
  verdict fails CI, and so does a corpus bump without a regenerated baseline.
- They do **not** catch a deliberate co-update. A PR that changes the converter
  and regenerates the fixture in the same commit is green. Nothing compares the
  fixture against the base branch.
- The JSON Schema comparison is narrower than the file. Only the verdict triple
  (`expected` / `bare` / `converted`) per case key is asserted;
  `JsonSchemaConversionDeltaTest.php:119-129` deliberately excludes the `reason`
  prose so rewording an explanation cannot read as a conformance regression.
  "Unchanged" in this rule means the verdict set, not the bytes.

So the mechanical step belongs to the reviewer, and it is one command:

```bash
git diff origin/main...HEAD -- \
  tests/fixtures/compatibility/v1-json-schema-conversion-delta.json \
  tests/fixtures/compatibility/v1-oas-example-documents.json
```

Three dots, not two: `origin/main...HEAD` diffs against the merge base, so a
branch that has not been rebased does not report main's own commits as if the PR
had made them.

Empty output on a PR that claims to be reducing surface area. Non-empty means
the PR is something else and needs its own decision record. A CI job that failed
on any diff to these files was considered and rejected: it cannot tell a
reduction PR from a deliberate conformance change or a corpus re-pin, so it
would need a label protocol, which is its own decision rather than a detail of
this one.

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

Four things that SemVer does not cover become policy-protected: dialect
selection, the discriminator-enforcement default, doctor ⇄ runtime consistency,
and the pinned conformance corpora. The first three keep `@internal` carriers
that stay freely renameable; the fourth is a commitment to keep measuring, so
unpinning a corpus or deleting a conformance test now needs a superseding ADR.

Three surfaces that SemVer *does* cover become protected beyond it — the
`OpenApiValidationOutcome` cases (`docs/versioning.md:18-19`), the `ContractCheck`
names and default statuses (`:83-88`), and the coverage baseline granularity
(`:67-82`). They may not be dropped even at a major boundary without a
superseding ADR.

The reduction rule gives "did this change meaning?" a fixed place to look — the
verification table, plus one `git diff` — instead of leaving it to be re-derived
per review. The `git diff` is not there because the tests are weak: an
implementation change alone fails them. It is there for the one case they cannot
see, an implementation change and a regenerated baseline in the same commit.

Writing the verification table also exposed a gap worth recording. Most
invariants have a committed artifact a reviewer can read directly — the enum
cases in `v2-public-api.json`, the coverage document shape in
`v3-coverage-report.json`, the two conformance baselines. The contract-check
*default return values* do not: the public-API baseline records the `cases` map
and both method signatures, so a change from `[401, 403]` to something else
would move no fixture and would be caught only by the unit assertions in
`tests/Unit/Fuzz/`. That is adequate coverage, but it is the one row of the
table where "read the diff" does not work and the suite has to be trusted.

ADR 0002's phases and ADR 0003's phase list stay open. In particular this ADR
does not retract ADR 0003 phase 6, the SDK exercise coverage that already
shipped.

## Amendments

### 2026-08-07 — #508 is a sanctioned exception to "shape, not capability"

The Decision above says nothing is added and nothing is removed, and that a
change altering what Gesso decides about a request, a response, or a schema is
not a v3 change. Applied mechanically, that test fails one milestone issue:
[#508](https://github.com/studio-design/gesso/issues/508) re-keys enum-drift
bindings from file-path attributes to spec component names, which changes the
compared set (its measured table: 17 of 19 existing bindings resolve by short
class name alone, and one consumer gains 10 newly compared bindings), and adds
a doctor warning for enum components no operation references. It is also the
only milestone issue labelled `enhancement` rather than `epic:dx`.

It stays in v3 because deleting the second filesystem root
(`enum_spec_base_path` and the private spec-reading pipeline behind it) is a
precondition for the single configuration entry point (#501), and shipping the
re-keying separately would migrate the same user-facing binding surface twice.
Consequences: the reduction-PR acceptance rule does not cover #508 — its PR
changes meaning by design and is reviewed against the issue's binding table,
not the conformance baselines.

This exception is closed. Any further v3 issue that fails the "shape, not
capability" test needs its own amendment here before implementation starts.

### 2026-08-07 — release sequencing: the v2 minors carry the value, v3.0 deletes

The milestone's adopter-visible value ships in the final v2 minors, not in
v3.0:

- #501 steps 1–5 (single `gesso.php`, `--config`, `doctor --emit-config`) are
  v2-compatible by design and ship in a v2 minor.
- #502's additive half ships in a v2 minor, as that issue already instructs.
- #507's additive flags (`--format` / `--output-file` on every subcommand)
  ship in a v2 minor; the unknown-flag rejection and exit-code change wait.
- #503's namespace move lands in a v2 minor behind the lazy alias; v3.0
  removes the alias only.
- Every rename ships its deprecation through the #499 channel in a v2 minor
  before the first breaking commit reaches `main`
  ([docs/versioning.md:216-221](../versioning.md) already requires this
  ordering; the channel itself shipped in v2.5.0).

v3.0 itself is then a deletion release: the old names, #505's envelope cutoff,
#506, #524, and #508's breaking half. It is time-boxed; an issue that does not
fit moves to v3.1 rather than extending the window.

During the v3 window, no new configuration parameter, CLI flag, or output key
is added under an old naming scheme — every flat addition grows the
deprecation surface this milestone exists to shrink (observed: the sidecar
envelope grew from six versions to eight while #505 sat open). New surfaces
carry their ADR 0005 names from the start.

### 2026-08-12 — the v3.0 window opens on a condition, not a date

The sequencing amendment above says the v3.0 window is time-boxed. That is
retained as a *scope* rule — an issue that does not fit still moves to v3.1
rather than extending the window — and retracted as a *scheduling* rule. No
date opens the window.

The reason is what that amendment already established. If the adopter-visible
value ships in the v2 minors, then v3.0's own payload is deletion. Two items
are the exception: #506 removes `phpunit/phpunit` from the production
requirement, and #508's breaking half changes the compared set. Everything
else on the list returns nothing to an adopter — it reduces the surface this
repository maintains, which is a maintainer benefit. A date attached to that
payload creates pressure to cut a release adopters gain nothing from, and
cutting it is not free: the first breaking commit on `main` turns the pending
release into `3.0.0`, after which no further v2 release can be cut from the
branch ([docs/versioning.md:216-221](../versioning.md)).

The window opens when both the floor and one trigger hold. The two are not
symmetric: the floor can only ever *permit* the window, never open one, and a
trigger is an event that has already happened rather than an item sitting in
the milestone. If a trigger could be satisfied by the plan itself, the
conjunction would collapse back into the floor's date and this amendment would
have renamed a deadline rather than removed one.

**Floor.** v1 has reached end of life (2027-07-01, per the
[v1 maintenance lifecycle](../versioning.md#v1-maintenance-lifecycle)).
Opening the window earlier puts three lines under simultaneous maintenance,
and the backport procedure in that section — cherry-pick from `main` into a
branch based on the maintenance line — is written for two. A trigger alone
does not open the window while v1 still receives releases.

The floor is v1's EOL rather than the end of its active maintenance
(2026-12-31) because security maintenance is still maintenance: through
2027-06-30 the `1.x` branch keeps taking security backports, so a v3.0 cut in
that window would leave `main`, `2.x`, and `1.x` all live. Moving the floor
six months later costs nothing this ADR is trying to buy — the window is
condition-gated precisely because v3.0 has no adopter-visible deadline — and
it is cheaper than defining a three-line backport procedure for a six-month
overlap. If a trigger fires before 2027-07-01 and waiting is genuinely worse
than the third line, that is an amendment with its own reasoning, not a
judgement call made under deadline.

**Triggers.** Both are worded as observations, because the obvious wording of
the first one is circular. The v3.0 milestone already catalogues the changes
that cannot be expressed additively — #505, #506, #507, #508, and #524 among
them — so "a breaking change is planned" has been true since the milestone
opened. A trigger satisfied by the catalogue is satisfied permanently, which
is the collapse described above.

1. A change that cannot be expressed additively is *blocked on the window*: a
   consumer reports the current state as a problem, it conflicts with a
   dependency or security policy, or other work cannot proceed until it lands.
   #506 is the likeliest candidate — moving `phpunit/phpunit` from `require`
   to `require-dev` cannot be done without breaking an install that relies on
   the transitive dependency — but it does not fire by being scoped and
   sitting in the milestone, which it already is and has been. It fires when
   someone who carries the production requirement reports it as an actual
   problem.
2. The deprecated surface starts costing more than deferring it saves. The
   operational test is that a change *had to be* implemented twice, once
   against the old surface and once against the new one. Carrying the
   deprecation bridge does not count on its own: that cost is what the
   sequencing amendment already decided to pay, so spending it is the plan
   working, not evidence against it.

This amendment changes *when* the deletion happens, not *whether* it does. The
sequencing amendment already decided that v3.0 deletes; nothing here reopens
that.

No adoption figure is offered in support, because the one that is easy to
reach does not measure what it would need to. Packagist reported 0 dependents
for both `studio-design/gesso` and `studio-design/openapi-contract-testing` on
2026-08-12, but that field counts published packages that require them — not
root applications, and not private consumers. This ADR's own evidence
contradicts the reading that nobody would be migrated: [what v3 does not
delete](#what-v3-does-not-delete) cites one consumer running enum drift over
52 backed enums and another using the named contract checks across 2 files and
246 lines, and the #508 amendment records a consumer gaining 10 newly compared
bindings. Sizing the migration would need consumer-side measurement of the
kind #508 already collected, per surface being deleted. Until that exists,
timing the deletion by an unmeasured cost is not available as an argument in
either direction.

What does not change is the work order. Every rename still ships additively in
a v2 minor carrying its deprecation, exactly as the sequencing amendment
requires. The intent is that v3.0 stays permanently ready to cut: once the
last deprecation has shipped, it is the removal of the old names plus a tag.
This amendment holds that option open; it does not defer the work behind it.
