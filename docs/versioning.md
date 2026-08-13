# Versioning and support policy

This library follows [Semantic Versioning 2.0](https://semver.org/). v1.0.0 is the API stability commitment: anything not marked `@internal` in v1.0.0 is covered by SemVer for the entire v1.x line.

- [What's covered by SemVer](#whats-covered-by-semver)
- [What's NOT covered by SemVer](#whats-not-covered-by-semver)
- [Deprecation policy](#deprecation-policy)
- [Support policy](#support-policy)
- [v2 maintenance lifecycle](#v2-maintenance-lifecycle)
- [v1 maintenance lifecycle](#v1-maintenance-lifecycle)
- [Release checklist](#release-checklist)

## What's covered by SemVer

- Public class names and namespaces (anything not marked `@internal`)
- Public method signatures (parameters, return types, visibility)
- Public constants and their values
- Every non-`@internal` member composed by a public trait, including protected
  and private methods, properties, and constants
- Enum cases (additions are minor; removals or renames are major)
- The `OpenApiValidationResult` shape (`outcome()`, `errors()`, `issues()`, `matchedPath()`, `skipReason()`, `isValid()`, `isSkipped()`)
- The `ValidationIssue` shape and its `category` slugs (`request.spec`,
  `request.path_match`, `request.method`, `request.parameter.path`,
  `request.parameter.query`, `request.parameter.header`, `request.security`,
  `request.body`, `response.spec`, `response.request_context`,
  `response.status`, `response.body`, `response.header`,
  `response.content_type`, and the legacy
  fallback `unknown`). New categories may be added in minor releases;
  renaming or removing one is major. `instancePath` / `keyword` are populated
  on schema violations — for body issues the pointer is into the validated
  body, for parameter / response-header issues into the named value — and
  `keyword` additionally carries synthetic violation kinds (`required` for a
  missing required parameter / header / credential, `format` for a present
  but unusable credential); both are `null` for structural and
  spec-malformation errors. New `keyword` values may appear in minor
  releases. `message` remains explicitly outside the contract (see below).
- The validation JSON document rendered by `JsonValidationResultRenderer`
  (`schema_version` 1) — see
  [validation-json-schema.md](validation-json-schema.md) for the field-level
  rules (additions are minor, removals/renames/type changes are major and
  bump `schema_version`).
- The validation failure output selection: the `ValidationOutputFormat` enum
  (`text` | `json`), the `ValidationOutput` methods (`format()`, `use()`,
  `reset()`), the `GESSO_VALIDATION_FORMAT` environment variable, and the
  json-mode failure shape (one header line followed by the versioned JSON
  document; for the PSR-7 exchange assertion, one `[request]` / `[response]`
  labelled document per failing side).
- The violation baseline file (`baseline_version` 1): the entry fields
  (`spec`, `method`, `path`, `status_code`, `content_type`, `category`,
  `parameter`, `instance_path`, `keyword`), the fingerprint composition those
  fields encode (the human-readable message is deliberately excluded; fixed
  HTTP methods are normalized to uppercase while OpenAPI 3.2 custom
  `additionalOperations` methods stay case-sensitive; numeric
  `instance_path` segments are canonicalized to `*`; non-body issues are
  distinguished by the parameter / response-header / security-scheme name in
  `parameter`, and parameter / response-header schema violations additionally
  by `keyword` and `instance_path` — missing required parameters and headers
  carry `keyword: required`, security-scheme satisfaction failures carry
  `keyword: required` (credentials absent) or `keyword: format` (present but
  unusable), and adapter body-decode failures (unparseable JSON, unreadable
  stream) carry `keyword: parse` so they stay distinct from a genuinely empty
  body), the `GESSO_BASELINE_GENERATE` environment variable, and the
  `baseline_file` / `baseline_stale` extension parameters (`baseline_stale`
  values: `off` | `note` | `fail`, default `note`). The enforcement semantics
  are part of the contract too: with `baseline_file` configured, a failing
  assertion is suppressed only when **every** one of its violations is
  baselined, and entries that no longer occur during a full run are reported
  as stale per `baseline_stale`. Unknown `baseline_version` values are
  rejected.
- The coverage baseline file (`coverage_baseline_version` 1): the
  `uncovered_responses` entry fields (`spec`, `method`, `path`, `status`,
  `content_type`) and what they identify — one declared response the run did
  not validate, at `(method, path, status, content-type)` granularity, where
  `status` is the spec response key and `content_type` is `*` for responses
  without a `content` block; fixed HTTP methods normalize to uppercase while
  OpenAPI 3.2 custom `additionalOperations` methods stay case-sensitive. The
  `coverage_baseline_file` / `coverage_baseline_stale` extension parameters
  (same values as `baseline_stale`), the matching
  `--coverage-baseline-file` / `--coverage-baseline-stale` merge flags, and
  the shared `GESSO_BASELINE_GENERATE` generation switch are covered too.
  The enforcement semantics are part of the contract: responses that were not
  validated — including responses skipped by `skip_response_codes` — are
  baselined, an uncovered response absent from the file fails the run, and
  entries that are covered now are reported per the stale mode. Unknown
  `coverage_baseline_version` values are rejected.
- Named contract checks: `ContractCheck` names and default expected statuses
  and status classes, the `OpenApiContractChecks`/`ContractCheckPlan` fluent
  surface, and the
  `ContractCheckSummary`/`ContractCheckFailure`/`ContractCheckSkip` shapes.
  Failure/skip prose is not a contract, and neither is the placeholder value
  an `ignored_auth` invalid-credential probe writes.
- CLI surfaces by major (commands, flags, exit codes, and versioned inputs and
  output where applicable):
  - v1.x: `bin/openapi-contract`, `bin/openapi-coverage-merge`, and the v1.10
    `bin/gesso` entry point
  - v2.x: the `doctor`, `coverage:merge`, `coverage:gate`, and `stubs`
    subcommands of `bin/gesso`; the legacy standalone binaries are not shipped
- The Laravel `gesso:routes` and `gesso:stubs` command surfaces (flags, exit codes, and versioned JSON output)
- The four environment variables Gesso owns — `GESSO_VALIDATION_FORMAT`,
  `GESSO_CONSOLE_OUTPUT`, `GESSO_BASELINE_GENERATE`, and `GESSO_SIDECAR_TOKEN`
  — and the values each accepts. `TEST_TOKEN` and `GITHUB_STEP_SUMMARY` are
  read but belong to paratest and GitHub Actions; they are not Gesso's to
  version.
- The `OpenApiCoverageExtension` PHPUnit configuration parameters (`spec_base_path`, `strip_prefixes`, `specs`, `output_file`, `console_output`, `validation_output`, `baseline_file`, `coverage_baseline_file`, `strict_required`, `strict_additional_properties`, `strict_additional_properties_per_call`, …)
- The Laravel `ValidatesOpenApiSchema` trait's public methods
- The category prefixes used in `E_USER_WARNING` messages (`[security]`, `[OpenAPI Schema]`, and the `[OpenAPI 3.2 ...]` categories) and in `E_USER_DEPRECATED` messages (`[Gesso deprecation]`). Adding a category is a minor change; renaming or removing one is major.

### Versioned sidecar compatibility

The PHP methods that produce and consume sidecar state (`exportState()` and
`importState()`) are `@internal`; their signatures are not a public PHP API.
The persisted payloads accepted by the released merge CLI are nevertheless a
compatibility surface because workers and the merge step can run different
installed versions during an upgrade.

The v1.9 writer emits a sidecar envelope with `envelopeVersion: 2`, containing
coverage state `version: 1` and strict-required state `version: 2`. The v1.9
reader accepts that envelope and the legacy bare coverage state `version: 1`.
It recognises `part-*.json` sidecars and `failed-*.json` failure markers.

The baseline-generation protocol introduced `envelopeVersion: 3` — the v2
shape plus a `baseline` key holding the violation-baseline document
(`baseline_version: 1`). It remains an accepted compatibility input.

The current writer emits `envelopeVersion: 8` for plain worker runs. It carries
coverage state `version: 1`, strict-required state `version: 2`,
strict-additional-properties state `version: 1`, SDK exercise state
`version: 1`, and deprecation state `version: 1`. A baseline-generation worker
emits `envelopeVersion: 9`, which adds the `baseline` document to the v8
tracker halves.

The reader accepts envelopes 2–9 and the legacy bare coverage state. Versions
4/5 are the older strict-additional-properties shapes; versions 2/3 contribute
no strict-additional-properties state, versions 2–5 contribute no SDK
exercise state, and versions 2–7 contribute no deprecation state — for which
the merge reports that its count is a lower bound rather than treating a
missing half as "this worker used none". A plain envelope carrying a `baseline` key or a baseline
envelope missing one is rejected as malformed; `coverage:merge
--baseline-file` refuses to write a union when any sidecar lacks the baseline
half. Likewise, a strict parallel SDK exercise threshold rejects any worker
sidecar without the SDK half instead of treating an old/mixed fleet as complete
evidence. Warn-only SDK gating reports the missing-worker count and evaluates
the available observations.

`--strict-additional-properties=fail` fails loudly when any worker lacks that
state, or when no worker exported an evaluation, instead of treating an
incomplete/old-fleet merge as clean. Unknown envelope versions and unknown
inner coverage, strict, baseline, or SDK tracker versions are rejected; they
are never guessed or partially imported.

The compatibility rules are:

- A newer merge reader keeps support for the explicitly documented older
  payloads within the same major line.
- A format owner bumps its version for an incompatible shape change. Envelope,
  coverage state, strict-required state, strict-additional-properties state,
  and SDK exercise state versions evolve independently.
- An older reader is not required to accept payloads written by a future
  version. Unknown versions and unrecognised shapes fail loudly instead of
  being guessed or partially merged.
- A legacy payload may be rejected when accepting it would silently lose data.
  In particular, strict-required state `version: 1` is not accepted by the
  v1.9 reader because it cannot represent nested pointer observations from
  `version: 2`.

For the Gesso v2 migration, the v2 merge reader will retain the v1.9 inputs
listed above. Any later major-version cutoff must be called out in that
version's upgrade guide; it must not appear as an unversioned shape change.

These rules apply to the worker-to-merge protocol only. The coverage report
written by `json_output` has its own `schema_version` contract documented in
[`coverage-json-schema.md`](coverage-json-schema.md).

## What's NOT covered by SemVer

- Anything marked `@internal` — including the `Internal\` and `Validation\Support\` namespaces, the per-validator helpers under `Validation\Request\` / `Validation\Response\`, `Spec\OpenApiSchemaConverter` / `Spec\OpenApiPathMatcher` / `Spec\OpenApiRefResolver` / `Spec\OpenApiPathSuggester`, the PHPUnit `CoverageReportSubscriber`, `Cli\DoctorCommand`, `Cli\CoverageGateCommand`, `Cli\StubsCommand`, the `Stubs\` namespace, `Coverage\CoverageMergeCommand` (the `gesso doctor`, `gesso coverage:gate`, `gesso coverage:merge`, and `gesso stubs` CLI surfaces remain covered — these classes are their implementation details), and test seams (`*::resetWarningStateForTesting()`, `OpenApiSpecLoader::reset()`, `OpenApiCoverageTracker::reset()` / `exportState()` / `importState()`).
- Validator error message wording (we may improve them; assert on presence/category, not on exact strings).
- The text-mode assertion failure layout — the header line, the error list,
  and the trailing `Reproduce:` curl line (including its redaction details).
  Machine consumers should select the json output mode, whose failure shape
  and document schema are covered above.
- The set of `format` keywords delegated to opis — we follow opis upstream, so a new format is added when opis adds it.
- Behaviour of bug-fix releases that close a documented silent-pass case. A test that passed only because of the silent pass may start failing — that's the fix doing its job, not a SemVer break.

`@internal` is enforced statically. Our CI runs PHPStan (pinned to `^2.1.13`) with the `bleedingEdge` ruleset enabled so that `new.internalClass` / `method.internalClass` / `staticMethod.internalClass` / `return.internalClass` / `parameter.internalClass` / `classConstant.internalClass` / `catch.internalClass` violations fail the build. The boundary is the **root namespace** — any code outside `Studio\` that instantiates, calls, type-hints against, or accesses constants on an `@internal` symbol will surface as a PHPStan error. Downstream consumers who enable bleedingEdge in their own PHPStan setup get the same enforcement automatically. Inheritance (`extends`/`implements`) of `@internal` classes is **not** enforced by these rules — that ships under a separate bleedingEdge rule we have not opted into yet. The `bin/gesso` CLI script is the only place inside this repository that crosses the boundary by design (it lives in the global namespace and instantiates `Cli\DoctorCommand` and `Coverage\CoverageMergeCommand`); it is excluded from PHPStan's `paths` so it does not pollute the analysis.

See [UPGRADING.md](https://github.com/studio-design/gesso/blob/main/UPGRADING.md) for migration notes between versions.

## Deprecation policy

SemVer requires a minor bump when public API is deprecated and a major bump for
its incompatible removal. This project adds one standing rule on top:

> A surface covered by this document is removed or renamed in a major release
> only if a deprecation for it shipped in a minor release of the preceding
> major. The last minor of a major is therefore the last chance to deprecate
> anything for the next one.

The rule covers every surface listed under "What's covered by SemVer" — not
just PHP symbols, but configuration keys, CLI flags, environment variables, and
Artisan command names. It does not apply to `@internal` symbols or to anything
in the "NOT covered by SemVer" list.

Deprecations are announced at runtime, not only in a docblock. Each one emits a
one-shot `E_USER_DEPRECATED` carrying the `[Gesso deprecation]` prefix, the
replacement, and the removal version; the PHPUnit extension writes a single
end-of-run STDERR line counting how many deprecated surfaces the suite still
uses. Under paratest that line comes from `gesso coverage:merge`, summed over
the worker sidecars. See [diagnostic
channels](supported-features.md#diagnostic-channels-e_user_warning-and-e_user_deprecated).

**If a run on the final minor of a major reports zero Gesso deprecations,
upgrading to the next major requires no consumer code, configuration, or CLI
changes.** That is what the rule buys: the deprecation report, not the upgrade
guide, is the authoritative answer to "am I ready?".

For the v2 → v3 transition, the deprecation-carrying release is the final v2
minor. Its number is not chosen by hand — release-please derives it from
Conventional Commit types — so the constraint is an ordering one: every v2
release must ship before the first breaking commit reaches `main`, because that
commit turns the pending release into `3.0.0` and no further v2 release can be
cut from the branch.

### The rename checklist

Two fixtures carry the v2 → v3 transition, and they are checked from opposite
directions because each one is blind to the other's failure mode.

| Fixture | Scanned from | Catches |
| --- | --- | --- |
| `tests/fixtures/compatibility/v2-deprecations.json` | `src/`, by `DeprecationRegistryTest` | a `Deprecations::notice()` call nobody registered |
| `tests/fixtures/compatibility/v3-renames.json` | [ADR 0005](adr/0005-v3-configuration-and-cli-naming.md), by `V3RenameRegistryTest` | a rename that ships no notice at all |

The first cannot see the second. A rename that simply never calls
`Deprecations::notice()` emits nothing, so the scan finds nothing and the
registry stays as correct — and as empty — as it was.

`v3-renames.json` therefore starts from the ADR: it lists every old spelling
ADR 0005's two tables name, the v3 spelling that replaces it, the channel it
uses, and the deprecation id once one is staged. Each replacement is compared
byte-for-byte against the right of the matching `→` in the ADR, which is why
a row replacing several spellings at once writes one `→` per spelling —
otherwise the fixture could name the wrong member of the right key.
**A PR that renames a configuration key or a CLI flag updates this fixture in
the same change.** Its `unstaged_count` is a ratchet —
staging a deprecation lowers it, and the test fails in both directions, so
neither an unstaged addition nor a stale number can sit unnoticed. Zero means
every v3 rename has shipped its notice and the final v2 minor can be cut.

The three channels a spelling can take:

- **`deprecation`** — the spelling stops working. `E_USER_DEPRECATED` through
  `Studio\Gesso\Internal\Deprecations`, with an entry in `v2-deprecations.json`.
- **`accepted-spelling`** — the spelling keeps working. The `[Gesso]` warning
  channel through `Studio\Gesso\Internal\LegacyIdentity`, described below.
- **`unchanged-spelling`** — nothing is removed; listed only so the gate can
  account for every ADR row. This is the one channel that stages nothing and
  counts toward nothing, so membership is a hand-written list in
  `V3RenameRegistryTest`, not a property of the entry. Keeping a *name* is not
  enough: `baseline_stale` and the two `--strict-*` flags keep theirs while
  replacing the value they accept, which is a removal to anyone who wrote the
  old value down, so they take the `deprecation` channel.

### Renamed spellings still accepted

A rename that keeps the old spelling working is not a removal, so it does not go
through the `E_USER_DEPRECATED` channel above: a suite running PHPUnit's
`failOnDeprecation` would fail for spelling a name that still works. These names
warn once per process on STDERR instead, under the `[Gesso]` prefix, and each
warning names its replacement and its removal version.

| Accepted spelling | Use instead | Accepted through | Removed in |
| --- | --- | --- | --- |
| `OPENAPI_VALIDATION_OUTPUT` | `GESSO_VALIDATION_FORMAT` | v3.x | v4.0 |
| `OPENAPI_CONSOLE_OUTPUT` | `GESSO_CONSOLE_OUTPUT` | v3.x | v4.0 |
| `OPENAPI_BASELINE_GENERATE` | `GESSO_BASELINE_GENERATE` | v3.x | v4.0 |
| `openapi:routes` | `gesso:routes` | v3.x | v4.0 |
| `openapi:stubs` | `gesso:stubs` | v3.x | v4.0 |

The current spelling wins when both are set, and setting only the accepted one
resolves to the same value with no behaviour difference. The map lives in
`Studio\Gesso\Internal\LegacyIdentity`; its removal is tracked by
[#523](https://github.com/studio-design/gesso/issues/523).

## Support policy

| Component | Supported |
| --- | --- |
| PHP runtime | v2.x — CI: 8.3, 8.4, 8.5; Composer: `^8.3`. v1.x — CI: 8.2, 8.3, 8.4; Composer: `^8.2`, subject to the lifecycle below. |
| PHPUnit | v2.x: 12.x, 13.x. v1.x: 11.x, 12.x, 13.x. Each line is covered by its branch CI matrix. |
| `opis/json-schema` | `^2.6` for v1.x and v2.x. A jump to `^3` would be a SemVer-major. |
| Laravel (optional adapter) | Whatever `orchestra/testbench` `^9 \|\| ^10 \|\| ^11` supports. |

After v2 stable, v2 bug fixes and security updates land on its latest minor.
Each line follows its maintenance lifecycle below. Neither major maintains
older minor branches; upgrade to the latest minor of the selected major to
receive fixes.

## v2 maintenance lifecycle

v3.0 has no scheduled date: its window opens on a condition rather than a
calendar deadline, recorded in
[ADR 0004](adr/0004-v3-consistency-policy-and-protected-core.md#2026-08-12--the-v30-window-opens-on-a-condition-not-a-date).
This line's support is therefore anchored to the v3.0.0 release rather than to
fixed dates.

| Period | Ends | Accepted changes |
| --- | --- | --- |
| Active maintenance | 12 months after v3.0.0 is released, and no earlier than 2028-07-01 | Security fixes, backward-compatible bug fixes, and critical ecosystem interoperability fixes |
| Security maintenance | 6 months after active maintenance ends | Security fixes only; non-security changes require evidence that they are necessary to ship a security fix safely |
| End of life | when security maintenance ends | No releases or support commitment |

**Adopting v2 today does not carry an unknown support horizon.** The earliest
v3.0.0 can be released is 2027-07-01, because the ADR 0004 window cannot open
before v1 reaches end of life. Twelve months after that is 2028-07-01, so that
is the earliest date active maintenance can end, and 2029-01-01 the earliest
end of life.

The 2028-07-01 floor in the table restates that derived date as a commitment
of this document. It is not there to bind the relative rule — it cannot, since
the two are equal by construction today — but to make the guarantee
independent: shortening it requires changing this table, so amending ADR 0004
cannot quietly shorten how long v2 is supported.

The window is longer than the one v1 received — v1 entered security
maintenance about five months after v2.0.0 shipped — and the difference is
deliberate. v1 was the pre-rename line under a package that is now
[marked abandoned][composer-abandoned], and its migration path is a package
swap documented in [migration/v2.md](migration/v2.md). v2 is the line adopters
are asked to build on.

The anchor is relative because a calendar one decouples from the release it
describes: the v1 table below carries a note that its dates do not move
automatically if the v2 schedule changes, which is a manual obligation this
line avoids by construction. When v3.0.0 ships, the resolved dates are
recorded in this section by the same PR that closes the v3 window.

## v1 maintenance lifecycle

v1.10.0 is the final planned feature minor of the original
`studio-design/openapi-contract-testing` package. It may add backward-compatible
migration aids and deprecations for Gesso 2.0. Under the [deprecation
policy](#deprecation-policy), v1.10.0 is the last release that can carry a
deprecation for a surface v2 removes. After v1.10.0, the v1 line receives
patches from the `1.x` maintenance branch:

V1.10.0 also provides lazy `Studio\Gesso\` aliases for all non-`@internal`
public PHP types. Consumers should use these aliases to migrate imports before
changing Composer packages. The aliases do not cover configuration, wire
formats, or literal class-name identity, and v2 does not provide a reverse
legacy namespace shim. V1.10 adds `gesso doctor` and `gesso coverage:merge` so
command invocations can move before the package switch; the v1 standalone
binaries remain supported until v1 EOL and are removed in v2. Follow the
[staged v2 migration guide](migration/v2.md) for these boundaries.

| Period | Dates | Accepted changes |
| --- | --- | --- |
| Active maintenance | through 2026-12-31 | Security fixes, backward-compatible bug fixes, and critical ecosystem interoperability fixes |
| Security maintenance | 2027-01-01 through 2027-06-30 | Security fixes only; non-security changes require evidence that they are necessary to ship a security fix safely |
| End of life | from 2027-07-01 | No releases or support commitment; migrate to `studio-design/gesso` |

These dates are calendar deadlines in UTC and do not move automatically if the
v2 schedule changes. If v2 stable is not available before active maintenance
ends, the maintainers must publish a revised calendar before 2026-12-31 rather
than silently extending or shortening support.

The v1 Composer PHP constraint remains `^8.2`; a patch release does not raise
the declared floor. [PHP 8.2 reaches upstream security EOL][php-support] on
2026-12-31.
Compatibility checks may continue during v1 security maintenance, but Gesso
cannot provide fixes for vulnerabilities in an EOL PHP runtime. Consumers
should run v1 on a PHP branch still supported by the PHP project.

The `1.x` branch is created from the v1.10.0 release commit only after its tag
and GitHub release have been produced by release-please. `main` then becomes the
v2 development line. Both branches use release-please independently; v1 patch
tags remain `v1.10.z` and v2 tags use `v2.y.z`.

Changes that affect both majors land on `main` first, then are cherry-picked
from the squashed commit into a new branch based on `1.x` and reviewed in a
separate PR targeting `1.x`. A v1-only compatibility or security fix may target
`1.x` directly when no equivalent v2 change is needed. Never merge `main` into
`1.x`, and never combine unrelated backports. The maintenance PR must preserve
the v1 public compatibility surface and pass the same required checks as a
`main` PR.

The old Composer package remains installable throughout this lifecycle. It is
[marked abandoned][composer-abandoned] with `studio-design/gesso` as its
suggested replacement only after the v2 stable package is installable and its
migration path has been verified. Abandonment is a migration signal, not
permission to remove existing v1 tags. The package remains supported according
to the dates above even if the abandonment notice is added earlier.

[composer-abandoned]: https://getcomposer.org/doc/04-schema.md#abandoned
[php-support]: https://www.php.net/supported-versions.php

## Release checklist

Before publishing a release:

- [ ] Run the supported PHP / PHPUnit / framework matrix in CI.
- [ ] Review `UPGRADING.md` and the generated release notes for SemVer accuracy.
- [ ] For a v2 beta or stable promotion, complete the tag, GitHub Release,
  release manifest, Packagist metadata, clean-install, and published-artifact
  checks in the
  [Gesso 2.0 release procedure][v2-release-procedure].
- [ ] If the README feature comparison was last checked three or more months ago, verify every competitor version and linked capability against its tagged official README and `composer.json`, update the checked date, and keep competitor strengths as well as gaps.

[v2-release-procedure]: https://github.com/studio-design/gesso/blob/main/CONTRIBUTING.md#gesso-20-beta-and-stable-promotion
