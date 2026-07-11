# Versioning and support policy

This library follows [Semantic Versioning 2.0](https://semver.org/). v1.0.0 is the API stability commitment: anything not marked `@internal` in v1.0.0 is covered by SemVer for the entire v1.x line.

- [What's covered by SemVer in v1.x](#whats-covered-by-semver-in-v1x)
- [What's NOT covered by SemVer](#whats-not-covered-by-semver)
- [Support policy](#support-policy)
- [Release checklist](#release-checklist)

## What's covered by SemVer in v1.x

- Public class names and namespaces (anything not marked `@internal`)
- Public method signatures (parameters, return types, visibility)
- Public constants and their values
- Enum cases (additions are minor; removals or renames are major)
- The `OpenApiValidationResult` shape (`outcome()`, `errors()`, `matchedPath()`, `skipReason()`, `isValid()`, `isSkipped()`)
- The CLI surface of `bin/openapi-coverage-merge` (flags, exit codes, sidecar JSON wire format via `STATE_FORMAT_VERSION`)
- The Laravel `openapi:routes` command surface (flags, exit codes, and versioned JSON output)
- The `OpenApiCoverageExtension` PHPUnit configuration parameters (`spec_base_path`, `strip_prefixes`, `specs`, `output_file`, `console_output`, …)
- The Laravel `ValidatesOpenApiSchema` trait's public methods
- The category prefixes used in `E_USER_WARNING` messages (`[security]`, `[OpenAPI Schema]`, and the `[OpenAPI 3.2 ...]` categories)

## What's NOT covered by SemVer

- Anything marked `@internal` — including the `Internal\` and `Validation\Support\` namespaces, the per-validator helpers under `Validation\Request\` / `Validation\Response\`, `Spec\OpenApiSchemaConverter` / `Spec\OpenApiPathMatcher` / `Spec\OpenApiRefResolver` / `Spec\OpenApiPathSuggester`, the PHPUnit `CoverageReportSubscriber`, `Coverage\CoverageMergeCommand` (the `bin/openapi-coverage-merge` CLI itself remains covered — the class is the implementation detail behind it), and test seams (`*::resetWarningStateForTesting()`, `OpenApiSpecLoader::reset()`, `OpenApiCoverageTracker::reset()` / `exportState()` / `importState()`).
- Validator error message wording (we may improve them; assert on presence/category, not on exact strings).
- The set of `format` keywords delegated to opis — we follow opis upstream, so a new format is added when opis adds it.
- Behaviour of bug-fix releases that close a documented silent-pass case. A test that passed only because of the silent pass may start failing — that's the fix doing its job, not a SemVer break.

`@internal` is enforced statically. Our CI runs PHPStan (pinned to `^2.1.13`) with the `bleedingEdge` ruleset enabled so that `new.internalClass` / `method.internalClass` / `staticMethod.internalClass` / `return.internalClass` / `parameter.internalClass` / `classConstant.internalClass` / `catch.internalClass` violations fail the build. The boundary is the **root namespace** — any code outside `Studio\` that instantiates, calls, type-hints against, or accesses constants on an `@internal` symbol will surface as a PHPStan error. Downstream consumers who enable bleedingEdge in their own PHPStan setup get the same enforcement automatically. Inheritance (`extends`/`implements`) of `@internal` classes is **not** enforced by these rules — that ships under a separate bleedingEdge rule we have not opted into yet. The `bin/openapi-coverage-merge` CLI script is the only place inside this repository that crosses the boundary by design (it lives in the global namespace and instantiates `Coverage\CoverageMergeCommand`); it is excluded from PHPStan's `paths` so it does not pollute the analysis.

See [UPGRADING.md](https://github.com/studio-design/gesso/blob/main/UPGRADING.md) for migration notes between versions.

## Support policy

| Component | Supported |
| --- | --- |
| PHP runtime | 8.2, 8.3, 8.4 (CI matrix). PHP 8.2 is supported until its security-EOL (2026-12); a SemVer-major bump may drop it after that. |
| PHPUnit | 11.x, 12.x, 13.x (CI matrix). New stable PHPUnit majors are added to the matrix; older majors are dropped in a SemVer-major bump. |
| `opis/json-schema` | `^2.6` for v1.x. A jump to `^3` would be a SemVer-major. |
| Laravel (optional adapter) | Whatever `orchestra/testbench` `^9 || ^10 || ^11` supports. |

Bug fixes and security updates land on the latest minor of v1.x. There is no LTS branch for older minors — upgrade to the latest minor to receive fixes.

## Release checklist

Before publishing a release:

- [ ] Run the supported PHP / PHPUnit / framework matrix in CI.
- [ ] Review `UPGRADING.md` and the generated release notes for SemVer accuracy.
- [ ] If the README feature comparison was last checked three or more months ago, verify every competitor version and linked capability against its tagged official README and `composer.json`, update the checked date, and keep competitor strengths as well as gaps.
