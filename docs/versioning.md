# Versioning and support policy

This library follows [Semantic Versioning 2.0](https://semver.org/). v1.0.0 is the API stability commitment: anything not marked `@internal` in v1.0.0 is covered by SemVer for the entire v1.x line.

- [What's covered by SemVer](#whats-covered-by-semver)
- [What's NOT covered by SemVer](#whats-not-covered-by-semver)
- [Support policy](#support-policy)
- [v1 maintenance lifecycle](#v1-maintenance-lifecycle)
- [Release checklist](#release-checklist)

## What's covered by SemVer

- Public class names and namespaces (anything not marked `@internal`)
- Public method signatures (parameters, return types, visibility)
- Public constants and their values
- Enum cases (additions are minor; removals or renames are major)
- The `OpenApiValidationResult` shape (`outcome()`, `errors()`, `matchedPath()`, `skipReason()`, `isValid()`, `isSkipped()`)
- CLI surfaces by major (commands, flags, exit codes, and versioned inputs and
  output where applicable):
  - v1.x: `bin/openapi-contract`, `bin/openapi-coverage-merge`, and the v1.10
    `bin/gesso` entry point
  - v2.x: the `doctor` and `coverage:merge` subcommands of `bin/gesso`; the
    legacy standalone binaries are not shipped
- The Laravel `openapi:routes` command surface (flags, exit codes, and versioned JSON output)
- The `OpenApiCoverageExtension` PHPUnit configuration parameters (`spec_base_path`, `strip_prefixes`, `specs`, `output_file`, `console_output`, …)
- The Laravel `ValidatesOpenApiSchema` trait's public methods
- The category prefixes used in `E_USER_WARNING` messages (`[security]`, `[OpenAPI Schema]`, and the `[OpenAPI 3.2 ...]` categories)

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

The compatibility rules are:

- A newer merge reader keeps support for the explicitly documented older
  payloads within the same major line.
- A format owner bumps its version for an incompatible shape change. Envelope,
  coverage state, and strict-required state versions evolve independently.
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

- Anything marked `@internal` — including the `Internal\` and `Validation\Support\` namespaces, the per-validator helpers under `Validation\Request\` / `Validation\Response\`, `Spec\OpenApiSchemaConverter` / `Spec\OpenApiPathMatcher` / `Spec\OpenApiRefResolver` / `Spec\OpenApiPathSuggester`, the PHPUnit `CoverageReportSubscriber`, `Cli\DoctorCommand`, `Coverage\CoverageMergeCommand` (the `gesso doctor` and `gesso coverage:merge` CLI surfaces remain covered — these classes are their implementation details), and test seams (`*::resetWarningStateForTesting()`, `OpenApiSpecLoader::reset()`, `OpenApiCoverageTracker::reset()` / `exportState()` / `importState()`).
- Validator error message wording (we may improve them; assert on presence/category, not on exact strings).
- The set of `format` keywords delegated to opis — we follow opis upstream, so a new format is added when opis adds it.
- Behaviour of bug-fix releases that close a documented silent-pass case. A test that passed only because of the silent pass may start failing — that's the fix doing its job, not a SemVer break.

`@internal` is enforced statically. Our CI runs PHPStan (pinned to `^2.1.13`) with the `bleedingEdge` ruleset enabled so that `new.internalClass` / `method.internalClass` / `staticMethod.internalClass` / `return.internalClass` / `parameter.internalClass` / `classConstant.internalClass` / `catch.internalClass` violations fail the build. The boundary is the **root namespace** — any code outside `Studio\` that instantiates, calls, type-hints against, or accesses constants on an `@internal` symbol will surface as a PHPStan error. Downstream consumers who enable bleedingEdge in their own PHPStan setup get the same enforcement automatically. Inheritance (`extends`/`implements`) of `@internal` classes is **not** enforced by these rules — that ships under a separate bleedingEdge rule we have not opted into yet. The `bin/gesso` CLI script is the only place inside this repository that crosses the boundary by design (it lives in the global namespace and instantiates `Cli\DoctorCommand` and `Coverage\CoverageMergeCommand`); it is excluded from PHPStan's `paths` so it does not pollute the analysis.

See [UPGRADING.md](https://github.com/studio-design/gesso/blob/main/UPGRADING.md) for migration notes between versions.

## Support policy

| Component | Supported |
| --- | --- |
| PHP runtime | 8.2, 8.3, 8.4 (CI matrix). PHP 8.2 is supported until its security-EOL (2026-12); a SemVer-major bump may drop it after that. |
| PHPUnit | 11.x, 12.x, 13.x (CI matrix). New stable PHPUnit majors are added to the matrix; older majors are dropped in a SemVer-major bump. |
| `opis/json-schema` | `^2.6` for v1.x. A jump to `^3` would be a SemVer-major. |
| Laravel (optional adapter) | Whatever `orchestra/testbench` `^9 \|\| ^10 \|\| ^11` supports. |

Bug fixes and security updates land on the latest minor of v1.x. There is no LTS branch for older minors — upgrade to the latest minor to receive fixes.

## v1 maintenance lifecycle

v1.10.0 is the final planned feature minor of the original
`studio-design/openapi-contract-testing` package. It may add backward-compatible
migration aids and deprecations for Gesso 2.0. SemVer requires a minor bump when
public API is deprecated and a major bump for its incompatible removal. This
project additionally requires migration deprecations to ship in v1.10.0 before
removal in v2. After v1.10.0, the v1 line receives patches from the `1.x`
maintenance branch:

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
- [ ] If the README feature comparison was last checked three or more months ago, verify every competitor version and linked capability against its tagged official README and `composer.json`, update the checked date, and keep competitor strengths as well as gaps.
