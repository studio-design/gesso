# v3 research: release plan and roadmap

- Date: 2026-10-26
- Status: research input for [ADR 0007](../adr/0007-v3-scope-and-release-window.md)
  (Accepted 2026-10-26). Internal; not shipped in the package.
- Baseline: `origin/main` at `6f2c26c` (release manifest `2.7.1`, release PR
  [#642](https://github.com/studio-design/gesso/pull/642) `chore(main): release 2.8.0`
  open). Counts were derived from that checkout or from `git show v2.7.1:…`.
- Companion research:
  [competitive landscape](2026-10-26-v3-competitive-landscape.md),
  [gap verification](2026-10-26-v3-gap-verification.md),
  [performance and pruning](2026-10-26-v3-performance-and-pruning.md),
  [adoption](2026-10-26-v3-adoption.md).

## Repository inventory (2026-10-26)

- 52 documentation pages, about 10.5k lines.
- `src/`: about 55k LOC. Largest areas: `Validation` 10.5k, `Fuzz` 7.2k,
  `Coverage` 5.7k, `Spec` 5.0k, `Arazzo` 3.1k.
- Public API: 80 types.
- Findings:
  - `UPGRADING.md` labelled 17 deprecations with the wrong release; fixed in
    [#647](https://github.com/studio-design/gesso/pull/647).
  - The v2 (additive) half of [#507](https://github.com/studio-design/gesso/issues/507)
    is incomplete (in progress).
  - The `stubs --output` → `--output-dir` rename is not tracked in ADR 0005 or
    `v3-renames.json`.
  - `coverage:merge` silently ignores unknown flags.
  - The docs teach deprecated phpunit.xml extension parameters in 11 files, plus
    the README quickstart and `doctor --phpunit-snippet` output.

## 1. Branch and release mechanics

### Recommendation: option (a), cut `2.x` from the v2.9.0 tag, not v2.8.0

Sequence:

1. Merge the pre-2.8.0 fixes (§4), then release PR #642 → **v2.8.0**.
2. Land the deprecations that are still missing (§1.6) on `main` as `feat:` PRs.
   Additive v3 features can land here too and ship as v2 value. Merge the release
   PR → **v2.9.0**, the "deprecation-complete" minor (`unstaged_count` = 0).
   The `ci.yml` and `release-please.yml` trigger edits (§1.3) merge **before**
   this release PR, so the `v2.9.0` tag already carries them.
3. Cut `2.x` from the `v2.9.0` tag commit once the tag and GitHub Release exist
   (the same rule used for `1.x` from `v1.10.0`).
4. On `main`, merge the prerelease flip (§1.4) **before** any `!` commit.
5. Merge the v3 breaking PRs (§2). Publish `3.0.0-beta.1`, then further betas,
   then stable.

Why not cut at 2.8.0: 34 renames and several untracked surfaces have no shipped
notice yet. With the branch cut at 2.8.0, each of those additive PRs would land
twice (the new spelling on `main`; the new spelling plus notice on `2.x`). That
duplicated work costs more than waiting one minor. Cutting at 2.9.0 also keeps
`docs/versioning.md:216-221` ("every v2 release ships before the first breaking
commit reaches `main`") true on `main` itself. A deprecation found late can
still ship as a `2.x` minor (`2.10.0`). That is the safety valve, not the plan.

**Option (b), a long-lived `3.x` branch, is rejected.** Every v3 feature would
need forward-porting from `main` while other work keeps adding v3 features, and
docs, Renovate, and release-please would all need switching at GA. The risk is
the same as in (a), but the conflicts last longer.

### 1.1 What three live lines cost

| Cost | Size today | Mitigation |
| --- | --- | --- |
| Backports | `1.x` has had **zero** releases since v1.10.0 (manifest `1.10.0`); it goes security-only on 2027-01-01 | two separate cherry-picks (§1.2) |
| CI | full matrix on 3 branches | `1.x` keeps its own `ci.yml` copy; `main` and `2.x` both need `2.x` in their triggers (§1.3) |
| Release PRs | up to 3 open (`chore(main)`, `chore(2.x)`, `chore(1.x)`) | already supported: the workflow sets `target-branch` from `github.ref_name` |
| Dependencies | `renovate.json` has no `baseBranches`, so `2.x` gets nothing | add `2.x` for security and lock-file updates only (§1.3) |
| Docs site | `docs.yml` deploys `main` only, so v3 docs would replace v2 docs | deploy from `2.x` until 3.0.0 stable (§1.3) |

### 1.2 Backport procedure (would replace CONTRIBUTING "Maintenance branches and backports")

1. Merge the fix on `main` (v3). If the code no longer exists on `main`, start
   from `2.x` (a v2-only fix).
2. Branch from the latest `2.x`, run `git cherry-pick -x <squashed main sha>`,
   and open a PR targeting `2.x` with the same title.
3. If v1 is affected and still in its window (bug fixes through 2026-12-31,
   security only from 2027-01-01 through 2027-06-30), branch from `1.x`,
   cherry-pick the **2.x** squash commit, and open a PR targeting `1.x`.
4. One fix per PR. Never merge `main` → `2.x` or `2.x` → `1.x`. Each PR passes
   that branch's required checks.

### 1.3 Config and workflow edits: "chore(release): cut the 2.x maintenance line"

GitHub reads a `push` or `pull_request` trigger from the workflow file on the
branch the event targets
([events that trigger workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#push)).
An edit that lands on `main` only after the cut leaves `2.x` with
`[main, 1.x]`: maintenance PRs get no CI and pushes to `2.x` open no release PR.
So the `release-please.yml` and `ci.yml` edits below merge on `main` **before**
the v2.9.0 release PR, and `2.x` inherits them from the tag. If `2.x` is ever
cut without them, open the same edit as a PR targeting `2.x` first; every other
maintenance PR waits for it.

After the cut, verify both: a test PR targeting `2.x` shows the CI checks, and
the first push to `2.x` runs release-please with `target-branch: 2.x`.

`.github/workflows/release-please.yml`:

```yaml
on:
  push:
    branches:
      - main
      - 2.x
      - 1.x
```

`.github/workflows/ci.yml`:

```yaml
on:
  push:
    branches: [main, 2.x, 1.x]
  pull_request:
    branches: [main, 2.x, 1.x]
```

`.github/workflows/docs.yml` publishes the installable stable line until v3 GA.
Unlike the two above, this one differs per branch, so it is edited after the
cut: on `main` **and** the same file on `2.x`, for the same reason:

```yaml
on:
  push:
    branches: [2.x]     # flip back to [main] in the v3.0.0 GA follow-up PR
```

Also check that the job's `actions/checkout` has no hard-coded `ref: main`, and
that the `github-pages` environment's deployment-branch policy allows `2.x`.
That is a repository settings change, not a file edit, so it will not show up in
the PR.

`renovate.json`:

```json
"baseBranches": ["main", "2.x"],
"packageRules": [
  { "matchBaseBranches": ["2.x"], "matchUpdateTypes": ["major", "minor"], "enabled": false }
]
```

`scheduled-checks.yml` needs no change. If the weekly audit should cover v2 too,
add `strategy.matrix.ref: [main, 2.x]` and pass the matrix ref to its checkout.
This is optional.

`2.x` keeps `release-please-config.json` as it is (`prerelease: false`) and its
own manifest (`2.9.0`). Tags `v2.9.z` and `v3.0.0-beta.N` cannot collide
(`include-component-in-tag: false`, different majors).

### 1.4 "chore(release): open the v3 beta line"

On `main`, before the first `!` commit. `versioning: prerelease` and
`prerelease-type: beta` are already set. With `prerelease: false`, the first
`feat!:` makes the bot propose a *stable* `3.0.0`, so this flip must come first:

```json
"versioning": "prerelease",
"prerelease-type": "beta",
"prerelease": true,
"release-as": "3.0.0-beta.1",
```

Then follow CONTRIBUTING's "Gesso 2.0 beta and stable promotion", steps 4–8,
with `3.0.0` in place of `2.0.0` (no Packagist registration or abandonment
steps):

- verify that the release PR proposes `3.0.0-beta.1`, merge it, and check that
  the tag, GitHub Release (Pre-release), manifest, and Packagist all agree;
- **remove `release-as` immediately** in a normal PR;
- clean-install with `composer require --dev "studio-design/gesso:^3.0@beta"`,
  then run the examples and one downstream suite;
- GA: a PR sets `prerelease: false`, `release-as: 3.0.0`, and the stable-only
  `changelog-sections`. After the tag, remove `release-as`, flip `docs.yml` back
  to `main`, and drop the `@beta` install commands.

CONTRIBUTING "Releases" steps 1 and 5 ("`main` for v2 and `1.x` for v1") become
`main` (v3), `2.x`, and `1.x`.

### 1.5 `docs/versioning.md` edits (part of accepting ADR 0007)

- **v2 maintenance lifecycle**: keep the table ("12 months after v3.0.0 is
  released, and no earlier than 2028-07-01"; security +6 months). Replace the
  intro and the "Adopting v2 today…" paragraph with text to this effect: v3.0
  has no condition gate since ADR 0007; the 2028-07-01 floor is independent of
  v3's date, so an earlier v3.0.0 gives v2 more than 12 months of active
  maintenance, never less; v2.9.0 is the final v2 feature minor from `main`; v2
  patches, and minors that only add migration aids or deprecations, ship from
  the `2.x` branch cut from the v2.9.0 release commit. When v3.0.0 ships, record
  the resolved dates (unchanged mechanism).
- **Deprecation policy, last paragraph**: "the deprecation-carrying release is
  the final v2 minor … no further v2 release can be cut from the branch"
  becomes "every v3.0 removal requires its deprecation in a **tagged** v2
  release; v2.9.0 is planned as that release, and `2.x` can carry a late one".
- **Add** the non-runtime-surface rule from ADR 0007 rule 3(a) under the
  deprecation policy.
- **Support policy**: add `v3.x` row placeholders (PHP `^8.3`; PHPUnit 12/13 in
  `require-dev` after #506).
- Replace both links to ADR 0004's 2026-08-12 amendment anchor.

### 1.6 Removals that still lack a shipped deprecation

Shipped in a tag (v2.7.1): 11 ids — `laravel.config.auto_inject_dummy_bearer`,
`forBinding`, `shouldSkipOpenApi`, `hasAnyCoverage`, and the 7
`Fuzz\ContractCheck*` aliases.

Ships with **2.8.0** (on `main`, untagged): 20 ids — the 17 `phpunit.*` ids from
#622 and 3 enum ids from #628 (`phpunit.enum_spec_base_path`,
`spec.loader.configure.enum_base_path`,
`attribute.bound_to_open_api_enum.spec_path`).

**Must land in 2.9.0** (none of these has a notice anywhere yet):

| Count | Surface | Issue | What the v2 PR adds |
| --- | --- | --- | --- |
| 19 | PHPUnit / Laravel flat keys: `spec_base_path`, `default_spec`, `specs`, `strip_prefixes`, `validation_output`, `max_errors`, `enforce_discriminator`, `acknowledged_unvalidatable_schemes`, `skip_response_codes`, `skip_request_validation_response_codes`, `sidecar_dir`, `enum_drift_enabled`, `enum_drift_scan_namespaces`, `enum_drift_fail_on_drift`, `default_testsuite_as_full`, `auto_assert`, `auto_validate_request`, `auto_inject_dummy_credentials`, `route_parity` | #501 | Add the names to `LegacyConfigurationKeys` so `ExtensionParameters::load()` (`src/PHPUnit/ExtensionParameters.php:56-58`) and the Laravel reader deprecate them as they already do for the 17. Keep the bridge's existing precedence (a v2 parameter overrides `gesso.php`, `ExtensionParameters.php:27,72`), not either issue's text (#501 says legacy wins; #502 says new wins plus FATAL). The `gesso.php` replacements have existed since 2.7.0. |
| 15 | `coverage:merge` `--json-output`, `--junit-output`, `--html-output`, `--console-output`, `--min-endpoint-coverage`, `--min-response-coverage`, `--min-sdk-exercise-coverage`, `--min-coverage-strict`, `--baseline-file`, `--coverage-baseline-file`, `--coverage-baseline-stale`, `--strict-required` (bare value), `--strict-additional-properties` (bare value), `--specs`, `--strip-prefixes` | #507, #502 | **First** accept the replacements (`--report=`, `--console-report`, `--min-coverage=`, `--baseline=`, `--baseline-stale=`, `--strict-*="run=…,per_call=…"`, `--spec-name`, `--strip-prefix`), **then** add the notices. None of the replacements is in `ArgvParser::parse()` today (`src/Coverage/CoverageMergeCommand.php:151-161`). |
| 1 | `gesso stubs --output` / `gesso:stubs --output` → `--output-dir` | #507 | Missing from the ADR 0005 CLI table **and** `v3-renames.json`. Add an ADR 0005 amendment row and a fixture row (raise the test's spelling bound by 1; `unstaged_count` 34 → 35), then accept `--output-dir` and add the notice. |
| 1 | `coverage:merge` unknown flags (ignored today, exit 2 in v3) | #507 | One `[Gesso deprecation]` notice naming the flag ("ignored; v3 rejects with exit 2"). |
| 1 | `coverage:merge` I/O failure exit `1` → `2` | #507 | An `UPGRADING.md` row and a notice printed on that path; add the `EXIT_*` constants now (the #551 half). |
| 1 | Sidecar envelopes 2–9 and bare coverage state (reader cutoff) | #505 | The reader emits a `[Gesso deprecation]` notice when it accepts a legacy payload; an `UPGRADING.md` row. |
| 10 | `EnumBindingReason` cases that #508 removes (`BasePathNotConfigured`, `EnumBasePathNotFound`, `EnumSpecBasePathOrphaned`, `SpecFileNotFound`, `MalformedJson`, `MalformedYaml`, `YamlLibraryMissing`, `NonMappingRoot`, `EnumKeyMissing`, `EnumKeyNotArray`; `ComponentNotFound` already exists on main) | #508 | A docblock `@deprecated` per case plus `UPGRADING.md` rows (ADR 0007 rule 3(a)). The registry scan allows docblocks and forbids only `#[\Deprecated]`. |
| 1 | `phpunit/phpunit` leaves `require` | #506 | An `UPGRADING.md` row, plus README and setup install snippets that already show `composer require --dev phpunit/phpunit`. |
| 5 | Private trait helpers `failOpenApi()`, `assertOpenApi()`, `failPsr7()`, `assertPsr7()`, `failExplore()` | #506 | Route internal callers to the new `@internal` helper and leave the private names as forwarders that call `Deprecations::notice()`, so the notice fires only for consumer code. |

**Conflict to settle.** #507's body (item 8) says renamed flags stay accepted
through v3.x and go in v4.0. `v3-renames.json` says `removed_in: 3.0` with the
`deprecation` channel, and that file is the gate that is actually enforced.
Recommendation: **3.0**, and edit #507's body to match. The alternative, moving
the 15 flags to `accepted-spelling` / `4.0`, keeps the old flag parser for a
whole major, against v3's goal of removing code for speed and size. Listed under
[open decisions](#open-decisions).

## 2. v3 breaking changes

Shared ledgers that every PR edits (rebase-only, merge one at a time):
`tests/fixtures/compatibility/v2-deprecations.json` (delete rows),
`v3-renames.json`, `v2-public-api.json`, `UPGRADING.md` (a new "From v2.9 to
v3.0" section), and `docs/migration/v3.md`. Apart from those, the file sets are
disjoint.

| PR | Title | Issues | Files (beyond the ledgers) | Depends on |
| --- | --- | --- | --- | --- |
| **P0** | `chore(release): open the v3 beta line` | — | `release-please-config.json` | v2.9.0 tag, `2.x` cut |
| **P1** | `feat(config)!: make gesso.php the only configuration entry point` | #501 (28 extension parameters, Laravel flat keys), #502 (collapsed keys), #508 (`enum_spec_base_path` parameter), #524 (`ConsoleOutput` prefix → `[Gesso]`; re-baseline `DiagnosticPrefixesBaselineTest` against a v3 fixture) | `src/PHPUnit/OpenApiCoverageExtension.php`, `src/PHPUnit/ExtensionParameters.php`, `src/PHPUnit/ConsoleOutput.php`, `src/PHPUnit/CoverageReportSubscriber.php` (enum base-path pass-through), `src/Config/ConfigurationBridge.php`, `src/Config/GessoConfig.php`, `src/Laravel/config.php`, `src/Laravel/GessoServiceProvider.php`, `src/Laravel/ValidatesOpenApiSchema.php` (bearer key and config readers), `src/Laravel/Commands/OpenApiRoutesCommand.php`, `tests/fixtures/compatibility/v2-diagnostic-prefixes.json` | P0 |
| **P2** | `feat(cli)!: unify coverage:merge and stubs flags and exit codes` | #507, #502 (merge flags) | `src/Coverage/CoverageMergeCommand.php`, `src/Internal/ArgvParser.php`, `src/Cli/StubsCommand.php`, `src/Laravel/Commands/OpenApiStubsCommand.php` (`--output`, plus deleting the exit-code remap at `:97`), `docs/cli.md` | P0 |
| **P3** | `refactor(coverage)!: read only sidecar envelope 10` | #505 (steps 3–5 only; the single version and optional keys shipped in 2.7) | `src/Coverage/CoverageSidecarEnvelope.php` (drop `LEGACY_*`), merge diagnostics in `CoverageMergeCommand.php`, `docs/parallel.md`, the `docs/versioning.md` sidecar section | **P2** (same `CoverageMergeCommand.php`) |
| **P4** | `refactor(schema)!: bind enums by component name only` | the rest of #508, plus the `forBinding()` removal | `src/Attribute/BoundToOpenApiEnum.php`, `src/Spec/OpenApiSpecLoader.php` (`configure(enumBasePath:)`, `getEnumBasePath()`), `src/Exception/EnumBindingReason.php`, `src/Exception/EnumBindingException.php`, `src/Schema/EnumDriftAsserter.php`, `src/Internal/EnumScanner*`, `docs/enum-drift.md` | **P1** (the extension stops calling `configure(enumBasePath:)`) |
| **P5** | `refactor(contract)!: remove the Fuzz contract-check aliases` | #503 | delete `src/Contract/Autoload.php`; the `composer.json` `autoload.files` entry | P0 |
| **P6** | `build(deps)!: move phpunit/phpunit to require-dev` | #506 | `composer.json` (require, require-dev, conflict, suggest), `examples/*/composer.json`, `src/Internal/OpenApiAssertionCore.php`, `src/Psr7/OpenApiAssertions.php`, `src/Laravel/ExploresOpenApiEndpoint.php` (delete the forwarders) | **P5** (`composer.json`) |
| **P7** | `refactor!: remove deprecated helper methods` | `hasAnyCoverage()`, `shouldSkipOpenApi()` | `src/Coverage/OpenApiCoverageTracker.php`, `src/SkipOpenApiResolver.php` | P0 |

Dependency order: P0 → {P1, P2, P5, P7} in parallel → P3 after P2, P4 after P1,
P6 after P5. Every PR uses `!` in the title; CONTRIBUTING requires checking that
the `!` is still there at squash time. The performance research's breaking PRs D
(extension config), E (sidecar envelopes and shared run gates), and F (aliases
and methods) overlap with P1, P3, and P5/P7; fold them into these PRs rather than
landing both sets.

Not in v3:

- **#523** (removing the `OPENAPI_*` environment variables and `openapi:*`
  aliases) stays **v4.0**, because `docs/versioning.md` promises "accepted
  through v3.x".
- **#554** is non-breaking v2 work. Item 3 (the instance validator cache) shipped
  in #616 (v2.7.x) and item 1 in #565. Item 2 (the three `resolve*` readers in
  `src/Laravel/ValidatesOpenApiSchema.php:1002/1061/1071`, plus `spec_base_path`
  parity between the two Artisan commands) still needs re-auditing against its
  acceptance criteria. Finish it on `main` before P1, which rewrites those
  readers.
- New removals proposed by the performance and adoption research each need a
  row in the 2.9.0 table above, plus the evidence ADR 0007 rule 3(b) asks for.

## 3. Consolidated roadmap

### 3.1 Before v2.8.0 (non-breaking, on `main`, before merging #642)

Done in [#647](https://github.com/studio-design/gesso/pull/647)
(`docs: date the 2.8.0 deprecations and drop stale unreleased notes`):

1. `UPGRADING.md`: relabel the 17 `phpunit.*` rows from `2.7.0` to `2.8.0`
   (#622 is not in `v2.7.1`).
2. `UPGRADING.md`: drop "(available on `main`, unreleased)" for the shared
   `gesso.php` and `laravel.auto_inject_dummy_credentials`, which shipped in
   2.7.0 (#593).
3. `UPGRADING.md`: retitle the "Unreleased" body-validation-reliability and
   Laravel-validator-cache sections that shipped in v2.7.1. An in-flight branch
   that extends the body-validation section adds its own `Unreleased`
   subsection instead of reusing the old heading.
4. `docs/setup.md` and `docs/recipes/scramble.md`: drop stale "unreleased"
   claims about `config` and the shared `gesso.php` (shipped 2.7.0).
5. `docs/versioning.md`: "the four environment variables" becomes five, adding
   `GESSO_RUN_ID` (read by `CoverageReportSubscriber.php:272` and
   `CoverageMergeCommand.php:405`, ships in 2.8.0 via #624); keep
   `docs/parallel.md` consistent.
6. A final grep for `unreleased` / "available on `main`" across docs,
   `UPGRADING.md`, and `README.md` before merging.

Not for 2.8.0, which should not wait on it: #507's additive half and the 19
#501 key notices. Those are the 2.9.0 payload.

### 3.2 v2.9.0: deprecation-complete minor

The table in §1.6: the 19 flat keys (#501), the 15 `coverage:merge` flags plus
their replacements (#507/#502), `stubs --output-dir`, the unknown-flag and
exit-code notices, the sidecar reader notice (#505), the 10 `EnumBindingReason`
cases (#508), and the #506 items. Also fix the docs that still teach deprecated
phpunit.xml parameters (11 files, the README quickstart, and
`doctor --phpunit-snippet`). The performance PRs A, B, and C and the gap waves
below can ship in 2.8.x / 2.9.0 as v2 value.

### 3.3 Gap waves

From the [gap verification](2026-10-26-v3-gap-verification.md#5-pr-plan):

| Wave | Item | PR |
| --- | --- | --- |
| 1 | G1 annotation-only subschema crash | [#648](https://github.com/studio-design/gesso/pull/648) |
| 1 | G2 reserved header parameters | [#649](https://github.com/studio-design/gesso/pull/649) |
| 1 | G3 mixed literal and template path segments | [#652](https://github.com/studio-design/gesso/pull/652) |
| 1 | G9 doctor ⇄ runtime parity sweep | [#650](https://github.com/studio-design/gesso/pull/650) |
| 2 | G4 parameter styles | not opened |
| 2 | G5 cookie parameters | not opened |
| 2 | G6 `parameters[].content` | not opened |
| 2 | G7 readOnly / writeOnly through property `allOf` | not opened |
| 3 | §4.3 streaming `itemSchema` | not opened |
| 3 | §4.2 webhooks and callbacks (moves a conformance baseline) | not opened |
| 3 | §4.1 keyword-level schema coverage | not opened |
| 3 | G8 `$id`-scoped references | not opened |

### 3.4 Performance

From the [performance research](2026-10-26-v3-performance-and-pruning.md#6-proposed-pr-split):

| PR | Item | Status |
| --- | --- | --- |
| A | Share resolved `$ref` targets; keep the normalizer copy-on-write | [#651](https://github.com/studio-design/gesso/pull/651) |
| B | Skip strict-mode observation when both strict checks are off | [#653](https://github.com/studio-design/gesso/pull/653) |
| C | Pool opis validators and path matchers across validator instances | not opened |

### 3.5 v3.0 breaking set

P0–P7 in §2, in that dependency order, after v2.9.0 is tagged and `2.x` is cut.
The performance research's G and I (SDK exercise coverage, fuzz breadth) are
**not** in the set unless their open decisions are resolved. H (Arazzo) was
decided on 2026-10-26 and lands before 2.8.0 as a non-breaking removal, because
Arazzo never shipped in a tagged release.

### 3.6 Adoption

From the [adoption research](2026-10-26-v3-adoption.md#pr-split-disjoint-file-sets):

| PR | Title | Depends on |
| --- | --- | --- |
| A | `docs(migration): map league, osteel, kirschbaum, and spectator apis to gesso` | v3 config keys frozen; the osteel rows that reference B ship after B |
| B | `feat(symfony): validate a response by method and path without a request` | — |
| C | `feat(symfony): validate every kernel exchange automatically` | B |
| D | `docs(conformance): publish a measured comparison with league` | — |
| E | `feat(laravel): ship a laravel boost skill` | v3 config and CLI freeze (#511); last |
| F | `docs(recipes): refresh scramble recipe for v3` | the v3 release |

Order: B → A (and C in parallel after B) → D (any time) → F (at the v3 release)
→ E (after the freeze). Also refresh the README feature comparison (dated
2026-07-10).

## Open decisions

Resolved on 2026-10-26: ADR 0007 is accepted; the branch strategy is option (a)
(§1); the #507 renamed flags warn through v2 and are removed in 3.0 (§1.6);
Arazzo is extracted into a separate package before 2.8.0 and the adapters stay
in the core (ADR 0007 rule 5).

Still open:

1. **Drop SDK exercise coverage?** ADR 0004 "What v3 does not delete" and ADR
   0007 rule 4 keep it; removal needs the rule 3(b) evidence, a coverage-JSON
   format bump, and an amendment.
2. **Remove fuzz breadth (spec-wide exploration, link chains, failure
   reducer)?** `src/Fuzz/` is kept by ADR 0004 and ADR 0007 rule 4, and link
   following shipped recently (#634).
