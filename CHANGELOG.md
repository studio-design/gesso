# Changelog

All notable changes to this project will be documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This project follows [Semantic Versioning 2.0](https://semver.org/). Anything
not marked `@internal` is covered by SemVer for the entire v1.x line; see the
[Versioning and support policy](README.md#versioning-and-support-policy) in
the README for the full surface contract.

## Unreleased

## [1.4.0](https://github.com/studio-design/openapi-contract-testing/compare/v1.3.0...v1.4.0) (2026-05-11)


### Features

* **phpunit:** add AssertsNoEnumDrift trait for PHPUnit-aware enum drift assertions ([#186](https://github.com/studio-design/openapi-contract-testing/issues/186)) ([655ef1a](https://github.com/studio-design/openapi-contract-testing/commit/655ef1abe05574286cde602be1eb950998dfa790))


### Reverts

* ci(release-please): use GitHub App token to trigger required checks ([#183](https://github.com/studio-design/openapi-contract-testing/issues/183)) ([#184](https://github.com/studio-design/openapi-contract-testing/issues/184)) ([0bf8fe5](https://github.com/studio-design/openapi-contract-testing/commit/0bf8fe5d9603e8149ec02a5b692862e7cd12c6f4))

## [1.3.0](https://github.com/studio-design/openapi-contract-testing/compare/v1.2.0...v1.3.0) (2026-05-08)


### Features

* **request:** auto-inject dummy credentials for non-bearer auth schemes ([#180](https://github.com/studio-design/openapi-contract-testing/issues/180)) ([14e560f](https://github.com/studio-design/openapi-contract-testing/commit/14e560fffe652c04647ad8bf1cf8bcfdf6fa7606))
* **request:** downgrade validation to skipped on documented 4xx response ([#182](https://github.com/studio-design/openapi-contract-testing/issues/182)) ([3ea6089](https://github.com/studio-design/openapi-contract-testing/commit/3ea6089550db6fd79a6c1463755a17d86dae8674))

## [1.2.0](https://github.com/studio-design/openapi-contract-testing/compare/v1.1.0...v1.2.0) (2026-05-08)


### Features

* **schema:** resolve #[BoundToOpenApiEnum] from optional enum_spec_base_path ([#171](https://github.com/studio-design/openapi-contract-testing/issues/171)) ([d0bc6fd](https://github.com/studio-design/openapi-contract-testing/commit/d0bc6fd6c2d79e05edb917deefd22b8dc1b84b0b))


### Bug Fixes

* **coverage:** blank line between endpoint heading and response table ([#176](https://github.com/studio-design/openapi-contract-testing/issues/176)) ([f2a9710](https://github.com/studio-design/openapi-contract-testing/commit/f2a97107ddda16006fcb835a49c2e059c593ff89)), closes [#174](https://github.com/studio-design/openapi-contract-testing/issues/174)

## [1.1.0](https://github.com/studio-design/openapi-contract-testing/compare/v1.0.0...v1.1.0) (2026-05-07)


### Features

* **extension:** opt-in auto-discovery of #[BoundToOpenApiEnum] enums ([#168](https://github.com/studio-design/openapi-contract-testing/issues/168)) ([95e34a9](https://github.com/studio-design/openapi-contract-testing/commit/95e34a939b032cc6f5e0ffa1ec3eec3c1c6cdee4))
* **schema:** enum drift detection between OpenAPI spec and bound PHP enums ([#166](https://github.com/studio-design/openapi-contract-testing/issues/166)) ([7910f09](https://github.com/studio-design/openapi-contract-testing/commit/7910f09674d9ebbe20adc2b0a251e19373e075b5))

## v1.0.0 — 2026-05-01

API stability commitment release.

There is no behaviour change vs v0.19.0 — v1.0.0 is a stability promotion,
not a feature release. Every fix from the dogfood cycle
(v0.17.0 → v0.18.0 → v0.19.0) is in v1.0.0 unchanged. The internal-product
suite that surfaced #159 (root cascade dedup) and #161 (array-boundary
cascade dedup) now passes against v0.19.0 with no remaining noise; v1.0.0
freezes that surface.

What v1.0.0 commits to:

- Anything not marked `@internal` is covered by SemVer for the entire v1.x
  line. The public surface was audited end-to-end (#157), and PHPStan's
  bleedingEdge `*.internalClass` rules enforce the boundary in CI (#158)
  against the root namespace `Studio\`. Any code outside that namespace
  that constructs / calls / type-hints against / accesses constants on an
  `@internal` symbol fails the build. Downstream consumers who enable
  bleedingEdge in their own PHPStan setup get the same enforcement.
- Supported runtimes: PHP 8.2–8.4 (CI matrix), PHPUnit 11–13,
  `opis/json-schema ^2.6`, Laravel via `orchestra/testbench`
  `^9 || ^10 || ^11`. The full matrix and the policies for adding / dropping
  versions are in the README's "Versioning and support policy" section.
- Bug fixes and security updates land on the latest minor of v1.x. There is
  no LTS branch for older minors — upgrade to the latest minor to receive
  fixes.

See [UPGRADING.md](UPGRADING.md) for v0.x → v1.0.0 migration notes (in
practice: only relevant if you were calling `@internal` symbols; all
documented public surface is unchanged).

## v0.19.0 — 2026-05-01

Second dogfood-driven UX fix release. The internal-product run of v0.18.0
confirmed that the original cascade dedup landed cleanly at the document
root, but the same suite surfaced a follow-up: any `{ data: [Item] }`-shaped
envelope still produced a tower of `additionalProperties` cascade lines
because the walker bailed at the first array index. v0.19.0 extends the
walker to descend through `items` (single-schema form and Draft 07 tuple
form, including the shape `OpenApiSchemaConverter` lowers OAS 3.1
`prefixItems` to) and adds six regression tests covering tuple
out-of-range, composition above an array, mixed `properties` + `items`,
digit-only property names, empty array data, and mixed-boolean tuples.
The conservative bail-out contract is unchanged: composition keywords,
`additionalProperties: <schema>`, `patternProperties`, `additionalItems`,
and boolean schemas at item level still preserve the original message,
so a real additional-property violation is never silently swallowed.

### Fixed

- **Schema validator — `additionalProperties: false` cascade dedup now
  applies across array boundaries**. v0.18.0's dedup walked the schema
  only via `properties.<name>`, so a data path that crossed an `items`
  boundary (`{ data: [Item] }`-shaped envelopes) bailed at the array
  index and the cascade message at `[/data/0]` / `[/data/0/<inner>]`
  re-inflated the failure surface back up to the root. The walker now
  descends through `items` for int segments — both the single-schema
  form (`items: <schema>`) and the Draft 07 tuple form (`items: [...]`,
  also the shape `OpenApiSchemaConverter` lowers OAS 3.1 `prefixItems`
  to). Composition keywords, `additionalProperties: <schema>`,
  `patternProperties`, `additionalItems`, boolean schemas at item level,
  and out-of-range tuple indices still bail safely so a real
  additional-property violation is never silently swallowed.
  Closes #161.

## v0.18.0 — 2026-05-01

Dogfood-driven UX fix release. The internal-product run of v0.17.0
surfaced a single but high-impact issue: opis's `additionalProperties:
false` keyword cascaded a pseudo-error naming declared properties as
"not allowed" whenever any sub-property failed its schema, doubling
the failure count in a 1280-test suite and routinely misdirecting
contributors to the wrong fix. v0.18.0 ships the structural dedup
along with regression coverage for edge cases discovered during the
review (property names with commas, empty-string keys, leading
whitespace, JSON-Pointer-escape characters).

### Fixed

- **Schema validator — `additionalProperties: false` cascading pseudo-error
  is now stripped**. opis's `PropertiesKeyword::validate()` skips its
  `addCheckedProperties()` call whenever any sub-property fails its
  schema, leaving the validation context without `$checked`. The
  follow-on `additionalProperties: false` keyword then sees every
  property the data carries as "unchecked" and reports declared
  properties as not-allowed — a single enum failure on one property
  silently inflated into two errors, the second of which read as
  "these declared properties are not allowed by the schema", the
  opposite of what the schema actually said. The validator now walks
  opis's `ValidationError` tree, reads the raw list of "additional"
  property names from `args()['properties']`, and filters out names
  that ARE declared in the schema's `properties` keyword at that path.
  Names that survive the filter are genuinely additional; if every
  listed name was a cascade artifact the whole message is dropped.
  Mixed cases (declared failure + genuine extra) keep the real extra
  in the rewritten message. The property-name comparison is fully
  structural — raw arrays from opis + raw path segments from
  `DataInfo::fullPath()` — so property names containing commas,
  whitespace, empty strings, or JSON-Pointer-escape-worthy characters
  all compare correctly. Schemas the dedup can't resolve (composition
  keywords routing data through `oneOf` / `allOf`, missing `properties`
  keyword) keep the original message untouched. Closes #159.

## v0.17.0 — 2026-05-01

The dogfood-driven v1.0.0 release candidate. Internal-product testing of
v0.16.0 surfaced one behavioural gap (multi-JSON content-type per-status
schema selection — `application/problem+json` bodies were silently judged
against the success-shape `application/json` schema) plus three v1.0
contract items (warning channel pinning, API surface audit, `@internal`
static enforcement) that were already on the prep list. This release
lands all four. The shipped public API is now byte-equivalent to the
planned v1.0.0 contract — the only remaining v1.0.0 work is the tag bump
and the README Stability badge swap.

### Changed

- **Response validator — per-content-type schema selection for multi-JSON
  specs**. When a response carries a JSON-flavoured `Content-Type` and the
  spec declares multiple JSON keys for the same status (e.g.
  `application/json` AND `application/problem+json`), the validator now
  prefers the spec key that exactly matches the actual `Content-Type` before
  falling back to the first JSON key. Previously the first JSON key always
  won, so a problem-details body served as `application/problem+json` would
  be judged against the success-shape `application/json` schema — silently
  passing if the body happened to satisfy the success shape, or wrongly
  failing if it didn't. This is a behavioural change for users relying on
  the legacy first-JSON-wins semantics with multi-JSON specs; single-JSON
  specs and vendor `+json` suffixes the spec doesn't enumerate are
  unaffected. Closes #152.

### Documentation

- **Versioning and support policy** documented in README. New section
  pins SemVer commitments for v1.x: which classes/methods/configs are
  frozen, which are explicitly `@internal` (and therefore not covered),
  and the support matrix for PHP / PHPUnit / `opis/json-schema`. Refs
  #113.
- **Warning channel contract** is now documented in README under
  "Supported features and known limitations". Pins
  `trigger_error(..., E_USER_WARNING)` with category-tagged messages
  (`[security]`, `[OpenAPI Schema]`) as the v1.0 official API surface
  for silent-pass signals. Includes consumption recipes (PHPUnit
  `failOnWarning`, programmatic capture via `set_error_handler`,
  per-category suppression) and an explicit deferral note for the
  structured-channel alternatives (typed exceptions, PSR-3 logger,
  `OpenApiValidationResult` warnings array) — those can be added in
  v1.x as additive enhancements without breaking the v1.0 contract.
  Closes #149.

### Internal

- **`@internal` is now enforced statically**. The CI PHPStan run includes
  `bleedingEdge.neon` so `new.internalClass` / `method.internalClass` /
  `staticMethod.internalClass` / `return.internalClass` /
  `parameter.internalClass` / `classConstant.internalClass` /
  `catch.internalClass` violations fail the build. The boundary is the
  **root namespace** (`Studio`) — any code outside it that instantiates,
  calls, type-hints against, or accesses constants on an `@internal`
  symbol surfaces as a PHPStan error. Downstream consumers who enable
  bleedingEdge in their own PHPStan setup get the same enforcement
  automatically. Two third-party `@internal` exemptions are configured
  (PHPUnit `AssertionFailedError` / `Exception` and `TestCase::name()`)
  — those are PHPUnit's own contract concerns, not ours, and removing
  them would block our test code from doing perfectly normal
  exception-boundary assertions. The `phpstan/phpstan` `require-dev`
  constraint was bumped from `^2.0` to `^2.1.13` (the floor where
  these rules ship) so a lockfile downgrade cannot silently disable
  the enforcement. A regression probe at
  `tests/Fixtures/Phpstan/InternalEnforcementProbe.php` lives in the
  `Acme\PhpstanProbe` namespace with deliberate boundary crossings +
  inline `@phpstan-ignore` directives; if the rules ever stop firing,
  bleedingEdge's `reportUnmatchedIgnoredErrors` flips the suppressions
  into hard CI failures. Closes #148.
- **API surface audit for v1.0**: 28 implementation-detail classes
  picked up class-level `@internal` docblock markers so the v1.0.0
  SemVer contract excludes them. Covers the per-validator helpers
  under `Validation\Request\` / `Validation\Response\` / `Validation\Support\`,
  the `Spec\` machinery (`OpenApiSchemaConverter`, `OpenApiPathMatcher`,
  `OpenApiPathSuggester`, `OpenApiRefResolver`, `RefResolutionContext`),
  the PHPUnit `CoverageReportSubscriber`, `Coverage\CoverageMergeCommand`
  (the `bin/openapi-coverage-merge` CLI surface remains covered — the
  class itself is the implementation detail behind it), and
  `Fuzz\SchemaDataGenerator`. The `ConsoleOutput` enum is intentionally
  NOT marked `@internal` because it appears on the public
  `Coverage\ConsoleCoverageRenderer::render()` signature; its string
  values (`default`, `all`, `uncovered_only`, `active_only`) remain part
  of the v1.0 SemVer surface as the documented `console_output` config
  parameter. Wording on the existing `Internal\` and
  `Laravel\Internal\StackTraceFilter` markers normalised to the same
  boilerplate sentence. No behavioural change — the public entry points
  (`OpenApiResponseValidator`, `OpenApiRequestValidator`,
  `OpenApiCoverageTracker`, `Coverage\*` renderers,
  `Fuzz\OpenApiEndpointExplorer`, `Laravel\*`, attributes, exceptions,
  enums, `OpenApiSpecLoader`) remain user-callable. Refs #113.

### Fixed

- **Schema converter — `discriminator.mapping` silent-strip** now emits a
  one-shot `E_USER_WARNING` when first encountered. The keyword is OAS-only
  and was already stripped before handing the schema to opis, but the
  silent strip meant a polymorphic body with the wrong `discriminator`
  value would pass as long as it satisfied any branch — masking serialiser
  bugs. The warning surfaces this so users notice the unenforced mapping.
  The strip behaviour itself is unchanged. Closes #147.
- **Schema converter — unknown `format` keywords now warn**. opis silently
  accepts any string for unrecognised `format` values, so a typo like
  `format: emial` (instead of `email`) was passing every string. The
  converter now emits a one-shot `E_USER_WARNING` per unknown format value
  on conversion. Allowlist covers all 19 opis Draft 06+ formats plus the
  7 OAS advisory hints (`int32`, `int64`, `float`, `double`, `byte`,
  `binary`, `password`) which are deliberately not enforced and do not
  warn. Closes #151.

## v0.16.0 — 2026-05-01

The "v1.0.0 hardening" release: a competitive review against Spectator,
league/openapi-psr7-validator, osteel, and kirschbaum surfaced six
silent-pass bugs in the OAS-to-Draft-07 conversion plus a content-type
range matching gap. All six are fixed below, and the security validator
now warns loudly when oauth2 / openIdConnect / mutualTLS / http-basic /
http-digest schemes are encountered (previously silent — the worst-class
failure mode for a contract-testing tool). The API surface picks up
`@internal` markers on test/serialisation helpers so v1.0.0 can freeze
the public API without those leaking into the SemVer contract. The
release also lands tag-driven release automation, CI hardening, and
end-to-end fixture coverage for every README-claimed schema feature.

### Fixed

- **Schema converter — `nullable: true` next to `enum`** now appends `null` to
  the enum so opis Draft 07 actually accepts null values. Previously only
  `type` was rewritten, causing `enum: ["a", "b"]` + `nullable: true` to
  reject `null` against the enum constraint even though OAS 3.0 considers
  null a valid value.
- **Schema converter — `examples` (Draft 2020-12 array form)** is now
  stripped from OAS 3.0 schemas as well as 3.1. Singular `example` was
  already removed for both versions; `examples` was inconsistently kept on
  3.0, leaving an unrecognised Draft 07 keyword in the output.
- **Schema converter — OAS 3.1 `const`** is now lowered to `enum: [value]`.
  Draft 07 has no native `const`, so the keyword silently passed before —
  a `const: "fixed"` schema would accept any value of the right type.
- **Schema converter — `unevaluatedProperties` / `unevaluatedItems`** now
  emit a one-shot `E_USER_WARNING` when first encountered. opis Draft 07
  has no implementation for these 2019-09 keywords, so the constraint
  silently dropped before — a contract test relying on `unevaluatedProperties:
  false` for object closure would always pass. Earlier work in this cycle
  also wrongly listed `patternProperties`, `contentMediaType`, and
  `contentEncoding` as unsupported; opis Draft 06+ implements all three
  natively, so warning about them was misinformation. The warning set is
  now trimmed to the two keywords genuinely dropped.
- **Schema converter — `$schema` is now stripped** from converter output.
  An OAS 3.1 author who declared `$schema: ".../draft/2020-12/schema"` on
  an inline schema would force opis to interpret our already-Draft-07-
  lowered schema under 2020-12, where the array-form `items` we emit for
  `prefixItems` is invalid. Stripping keeps the validator draft consistent
  with what the converter actually produces.
- **Validator was running against opis Draft 2020-12 instead of Draft 07**.
  `OpenApiSchemaConverter` lowers OAS 3.1 keywords to Draft 07 (e.g.
  `prefixItems` → array-form `items`, valid Draft 07 tuple validation but
  rejected by 2020-12). The runner did not pin opis to a draft version,
  so opis used its 2020-12 default and rejected populated `prefixItems`
  shapes with `InvalidKeywordException`. Surface only because no fixture
  posted real data through a tuple shape — `OpenApiSchemaConverterTest`
  unit tests verified the conversion result but not that opis could
  validate against it. Now the runner explicitly calls
  `parser()->setDefaultDraftVersion('07')`.
- **Content-type matcher — wildcard ranges**. `findContentTypeKey()` now
  resolves with most-specific-first priority: exact match → `<type>/*` →
  `*/*`. `findJsonContentType()` resolves with literal `application/json`
  / `+json` first, falling back to `application/*` only — `text/*`,
  `image/*`, `multipart/*`, and `*/*` are intentionally NOT returned as
  JSON-acceptable, since routing those through JSON schema validation
  would re-introduce the silent-pass class this fix is meant to eliminate.
  Specs using OpenAPI 3.x §4.7.10 media-type ranges previously skipped
  JSON schema validation silently because the matcher only compared
  literally.
- **Security validator — `oauth2` / `openIdConnect` / `mutualTLS` / `http`
  (non-`bearer`) silent-pass** now emits a one-shot `E_USER_WARNING` per
  scheme name on first encounter. The validator continues to silently pass
  requirements containing only these schemes (false-negative avoidance —
  blocking a test for a spec we cannot evaluate is worse than letting it
  through), but the warning surfaces the limitation so a green test against
  an unauthenticated request does not stay invisible. This is the
  worst-class silent failure for a contract-testing tool, and matches the
  schema-converter precedent of warning loudly when a constraint is not
  being enforced. Closes #146.

### Changed

- **Public-API hygiene** — `OpenApiSpecLoader::clearCache()` / `evict()` /
  `reset()`, `OpenApiCoverageTracker::reset()` / `exportState()` /
  `importState()`, and `ValidatesOpenApiSchema::resetValidatorCache()` are
  now annotated `@internal`. They remain `public` for the PHPUnit extension
  / paratest sidecar protocol but are no longer part of the user-facing
  surface that v1.0.0 will freeze.

### Documentation

- README gains a "Supported features and known limitations" section that
  pins down body-validation media types, parameter style support,
  security-scheme coverage, schema feature handling, HTTP-method coverage,
  and spec features that are not consulted (webhooks, callbacks, links,
  server URL templates, etc.). Important for v1.0.0 expectation-setting:
  the library favours loud failures or explicit `Skipped` outcomes over
  silent passes, but where features are out of scope it now says so
  explicitly rather than leaving users to discover it through silently
  green tests.
- New `CONTRIBUTING.md`, `SECURITY.md`, `UPGRADING.md`, GitHub issue
  templates, and pull request template. v1.0.0 will draw a wider audience
  than v0.x; structured templates reduce triage cost and pin the SemVer /
  security-disclosure expectations explicitly.

### Release infrastructure

- **CI hardening**. Added `composer-validate` and `composer-audit` jobs.
  Added a `--prefer-lowest` matrix leg so constraint changes that
  accidentally require a newer minor of opis/json-schema or PHPUnit are
  caught instead of silently breaking downstream pin-pinned consumers.
- **Tag-driven release pipeline**. Pushing a `v*` tag now runs the full
  CI matrix once more and then publishes a GitHub Release with
  auto-generated notes via `gh release create --generate-notes`. Notes
  are grouped by PR labels per `.github/release.yml`. Eliminates the
  "tagged but forgot to publish the Release" footgun.
- **PR title convention enforcement** via `amannn/action-semantic-pull-request`.
  History was already conventional-style; the workflow formalises it so
  the squash-merge commit history stays parseable for changelog
  automation.
- **`composer.json` polish** — added `scripts` (`composer test` /
  `stan` / `cs` / `cs-check` / `ci`), `support` URLs, and `archive.exclude`
  so Packagist tarballs ship without `tests/`, `.github/`, dotfiles, etc.
- **Renovate** now runs Mondays Asia/Tokyo, auto-merges runtime *patch*
  updates as well as the existing dev minor/patch group. Runtime *minor*
  stays manual because `opis/json-schema` semantics may shift.

### Internal

- **End-to-end fixture coverage filled in for every README-claimed
  schema feature.** Three new fixtures (`composition.json` covering
  `oneOf` / `anyOf` / `not` / three forms of `additionalProperties`;
  `formats-and-numeric.json` covering eight format keywords plus
  numeric constraints; `petstore-3.1.json` extended with eight new
  endpoints for OAS 3.1-specific surface) plus `FixtureCoverageTest`,
  a new integration test file that round-trips each new fixture
  through the validator pipeline. Before this work, every covered
  feature had unit-only coverage in `OpenApiSchemaConverterTest`
  with inline schemas — a regression at the spec-loader / ref-resolver
  / converter / opis-runner boundary would have escaped. Test count
  in this release line moved from 1052 to over 1080. `discriminator`
  is included in the fixture for documentation purposes only — the
  README already states it is silently stripped, and the new tests
  pin that the underlying `oneOf` union still validates correctly.

## v0.15.0 — 2026-04-30

The "v1.0 prep" release: namespaces reorganised so the public surface
reads cleanly, `Coverage/` extracted out of `PHPUnit/` so future Pest
and Symfony adapters can share it without dragging the PHPUnit runner
in, plus schema-driven request fuzzing (`ExploresOpenApiEndpoint`),
a `min_coverage` CI gate (#135), and trimmed failure stack traces
(#131). Breaking only on FQCN imports — see the migration table below.

### Changed (breaking)

- **Namespace reorganisation — top-level cleanup ahead of v1.0.0**. Twenty-two
  classes moved out of the `Studio\OpenApiContractTesting\` top-level namespace
  into focused subnamespaces (`Attribute\`, `Coverage\`, `Exception\`, `Spec\`),
  and six coverage classes moved out of `PHPUnit\` into the new `Coverage\`.
  PHPUnit/ slims down to just the runner-facing extension, subscriber, and
  console output enum.

  No compat shim — pre-1.0 breaking changes are the contract for this
  release line (see the project banner above). Update any `use` statement
  pointing at a moved class.

  | Old FQCN | New FQCN |
  | --- | --- |
  | `Studio\OpenApiContractTesting\OpenApiSpec` | `Studio\OpenApiContractTesting\Attribute\OpenApiSpec` |
  | `Studio\OpenApiContractTesting\SkipOpenApi` | `Studio\OpenApiContractTesting\Attribute\SkipOpenApi` |
  | `Studio\OpenApiContractTesting\InvalidOpenApiSpecException` | `Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException` |
  | `Studio\OpenApiContractTesting\InvalidOpenApiSpecReason` | `Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason` |
  | `Studio\OpenApiContractTesting\SpecFileNotFoundException` | `Studio\OpenApiContractTesting\Exception\SpecFileNotFoundException` |
  | `Studio\OpenApiContractTesting\OpenApiSpecLoader` | `Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader` |
  | `Studio\OpenApiContractTesting\OpenApiSpecResolver` | `Studio\OpenApiContractTesting\Spec\OpenApiSpecResolver` |
  | `Studio\OpenApiContractTesting\OpenApiRefResolver` | `Studio\OpenApiContractTesting\Spec\OpenApiRefResolver` |
  | `Studio\OpenApiContractTesting\RefResolutionContext` | `Studio\OpenApiContractTesting\Spec\RefResolutionContext` |
  | `Studio\OpenApiContractTesting\OpenApiSchemaConverter` | `Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter` |
  | `Studio\OpenApiContractTesting\OpenApiPathMatcher` | `Studio\OpenApiContractTesting\Spec\OpenApiPathMatcher` |
  | `Studio\OpenApiContractTesting\OpenApiPathSuggester` | `Studio\OpenApiContractTesting\Spec\OpenApiPathSuggester` |
  | `Studio\OpenApiContractTesting\OpenApiCoverageTracker` | `Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker` |
  | `Studio\OpenApiContractTesting\EndpointCoverageState` | `Studio\OpenApiContractTesting\Coverage\EndpointCoverageState` |
  | `Studio\OpenApiContractTesting\ResponseCoverageState` | `Studio\OpenApiContractTesting\Coverage\ResponseCoverageState` |
  | `Studio\OpenApiContractTesting\InvalidThresholdConfigurationException` | `Studio\OpenApiContractTesting\Coverage\InvalidThresholdConfigurationException` |
  | `Studio\OpenApiContractTesting\PHPUnit\ConsoleCoverageRenderer` | `Studio\OpenApiContractTesting\Coverage\ConsoleCoverageRenderer` |
  | `Studio\OpenApiContractTesting\PHPUnit\MarkdownCoverageRenderer` | `Studio\OpenApiContractTesting\Coverage\MarkdownCoverageRenderer` |
  | `Studio\OpenApiContractTesting\PHPUnit\CoverageMergeCommand` | `Studio\OpenApiContractTesting\Coverage\CoverageMergeCommand` |
  | `Studio\OpenApiContractTesting\PHPUnit\CoverageThresholdEvaluator` | `Studio\OpenApiContractTesting\Coverage\CoverageThresholdEvaluator` |
  | `Studio\OpenApiContractTesting\PHPUnit\CoverageSidecarReader` | `Studio\OpenApiContractTesting\Coverage\CoverageSidecarReader` |
  | `Studio\OpenApiContractTesting\PHPUnit\CoverageSidecarWriter` | `Studio\OpenApiContractTesting\Coverage\CoverageSidecarWriter` |

  Unchanged FQCNs (kept for stability or because they live with framework code):
  the public `OpenApiResponseValidator` / `OpenApiRequestValidator` entry
  points, the top-level enums (`HttpMethod`, `OpenApiVersion`,
  `OpenApiValidationOutcome`, `SchemaContext`), `OpenApiValidationResult`,
  `SkipOpenApiResolver`, everything under `Laravel\`, `Fuzz\`, `Internal\`,
  and `Validation\`, plus the runner-facing
  `PHPUnit\{OpenApiCoverageExtension, CoverageReportSubscriber, ConsoleOutput}`.

  `phpunit.xml.dist` users: the bootstrap class
  `Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension` does **not**
  change — that one stays in `PHPUnit\`.

### Added

- **#136 — Schema-driven request fuzzing (`ExploresOpenApiEndpoint` trait)**.
  Generates N happy-path request inputs for a single (method, path) operation
  directly from the OpenAPI spec — the first PHP library to ship Schemathesis
  / property-based contract testing as a built-in primitive. Spec-name
  resolution mirrors `ValidatesOpenApiSchema` (method/class `#[OpenApiSpec]`
  attribute → `openApiSpec()` override → `default_spec` config). Each generated
  case is wrapped in an `ExploredCase` DTO (body / query / headers / pathParams)
  and the returned `ExplorationCases` collection exposes both `foreach` and a
  fluent `each(callable)` helper:
  ```php
  $this->exploreEndpoint('POST', '/v1/pets', cases: 50)
      ->each(fn ($input) => $this->postJson('/api/v1/pets', $input->body)
          ->assertSuccessful());
  ```
  The HTTP path through Laravel keeps using the existing `ValidatesOpenApiSchema`
  auto-assert hook, so response validation and coverage tracking are unchanged.
  When `fakerphp/faker` is installed (already transitive via
  `orchestra/testbench`), generation produces realistic strings (email, uuid,
  URLs) and a `seed:` argument locks output for deterministic CI; without
  faker, the generator falls back to deterministic primitives that still pass
  the schema. Out-of-scope for this slice (tracked separately): boundary
  values, negative-case generation, `oneOf`/`anyOf`/`allOf` composition, regex
  `pattern`, and full-spec auto-exploration.

- **#135 — `min_coverage` threshold gate for CI**. New PHPUnit extension
  parameters `min_endpoint_coverage` / `min_response_coverage` (percent,
  optional) and `min_coverage_strict` (default `false` → warn-only, set to
  `true` to exit non-zero on a miss) make contract coverage gateable the
  same way PHPUnit's own `--coverage-threshold` works. The gate aggregates
  over every spec listed in `specs=` (raw covered/total counts are summed
  across specs, then a single percent is compared). The merge CLI gains
  matching `--min-endpoint-coverage` / `--min-response-coverage` /
  `--min-coverage-strict` flags so paratest workflows can gate too. Both
  paths emit a `[OpenAPI Coverage] FAIL: …` line to stderr on a strict
  miss (or `WARN:` when warn-only).
  - Strict mode **fails fast on an unevaluable gate**, never silently passes:
    a non-numeric / out-of-range threshold throws
    `InvalidThresholdConfigurationException` (extension) or exits 2 (merge
    CLI), and an empty test run with a configured threshold exits 1 with
    `no contract test coverage was recorded`. Warn-only mode keeps the
    historical exit codes and only logs.

### Fixed

- **#131 — Clean stack trace for contract validation failures**. PHPUnit's
  failure block no longer surfaces this library's internal frames or
  Laravel's `MakesHttpRequests` / `TestResponse` testing-concern frames
  before the user's test line. The trait intercepts the
  `AssertionFailedError` raised at its own failure sites and re-throws
  with a trimmed trace. Behavior change is trace-only — exception type,
  message, and the user-visible test line are unchanged. No user-side
  configuration is required.

## v0.14.0 — 2026-04-28

Parallel-runner support unblocks `pest --parallel` / paratest in
downstream CI. Non-breaking minor release: sequential PHPUnit behaviour
is unchanged; the new behaviour is gated on paratest's `TEST_TOKEN`
environment variable.

### Added

- **#129 — paratest / Pest `--parallel` support**. Coverage state is
  per-process, so under paratest each worker previously produced a
  partial report that either overwrote (`output_file`) or stacked
  (`GITHUB_STEP_SUMMARY`) the others. The extension now detects
  paratest workers via `TEST_TOKEN` and writes per-worker JSON sidecars
  instead of rendering. A new `vendor/bin/openapi-coverage-merge` CLI
  combines the sidecars into a single report after the parallel run
  finishes — same workflow shape as `phpunit/php-code-coverage` +
  `phpcov merge`.
- New optional `sidecar_dir` parameter on `OpenApiCoverageExtension`
  (default `sys_get_temp_dir()/openapi-coverage-sidecars`). Workers
  drop sidecars here; the merge CLI reads from the same path.
- New public APIs: `OpenApiCoverageTracker::exportState()` /
  `importState()` expose the JSON-safe state and union-merge primitives
  for callers building custom aggregators. `STATE_FORMAT_VERSION = 1`
  is stamped on every payload.
- New `vendor/bin/openapi-coverage-merge` CLI. Flags:
  `--spec-base-path`, `--specs`, `--strip-prefixes`, `--sidecar-dir`,
  `--output-file`, `--github-step-summary`, `--console-output`,
  `--no-cleanup`, `--help`.
- **Worker write failures now fail the merge loudly.** When a worker
  cannot persist its sidecar it drops a `failed-<token>.json` marker
  in the sidecar dir; the merge CLI exits non-zero (`FATAL`) when any
  markers are present, since a missing worker would silently
  under-count coverage.

### Documentation

- README adds a "Parallel test runners" section covering the workflow,
  sidecar directory defaults, the full CLI flag table, and notes on
  failure-marker handling, stale sidecars across runs, and the merge
  CLI's inability to set `allowRemoteRefs` (consumers using HTTP `$ref`
  must pre-bundle or wrap the merge step).

### Migration

- Sequential PHPUnit / Pest runs need no changes — the new code paths
  are gated on `TEST_TOKEN` (set only by paratest).
- Parallel runs require an extra step: after `pest --parallel` /
  `paratest`, run `vendor/bin/openapi-coverage-merge --spec-base-path=…
  --specs=…` to combine the sidecars into a single coverage report.

Install via `composer require --dev studio-design/openapi-contract-testing:^0.14`.

## v0.13.0 — 2026-04-25

Spec compliance + critical bug fix surfaced by the v0.12.0 dogfood
session against a real Laravel 12 project.

### Fixed

- **#124 — Empty `{}` body validates against `type: object`** (was the
  v1.0 blocker from dogfood). PHP's `json_decode('{}', true) === []`
  collided with the body validator preserving `[]` as a JSON array, so
  the natural Laravel `$response->json()` path failed against a spec
  declaring `type: object`. The body validator now coerces `[]` to
  `stdClass` when the schema's top-level type explicitly accepts an
  object (`type: object` or `type: ["object", ...]` in OAS 3.1).
  Composition keywords (`oneOf` / `anyOf` / `allOf`) intentionally do
  NOT trigger coercion — type-mismatch errors still surface for
  `type: array` schemas where the empty-array body is genuinely wrong.

### Added

- **#125 — `default` response key support**. The validator now
  consults `responses.default` when the literal status code isn't
  declared. Validation still runs the schema; the matched key is
  reported as `"default"` in `OpenApiValidationResult::matchedStatusCode()`
  and in coverage rows.
- **#126 — `1XX`/`2XX`/`3XX`/`4XX`/`5XX` range key support** (case-insensitive
  per spec; both `5XX` and `5xx` accepted). The validator now matches
  range keys when no exact status key is declared. The matched spec
  key (preserving the spec author's casing) flows through to coverage.
- Lookup priority is **exact > range > default** (the conventional
  resolution shared by major OpenAPI tooling — the spec describes the
  three forms but does not normatively rank them). Specs that declare
  both `503` and `5XX` resolve `503` to the explicit entry; `599` falls
  through to `5XX`; `418` falls through to `default` if declared.
- `tests/fixtures/specs/spec-fallback.json` exercises every priority
  branch in the new lookup.

### Changed

- **`OpenApiValidationResult::matchedStatusCode()` semantics shift**:
  pre-v0.13, this always returned the literal HTTP status string (e.g.
  `"503"`). With the new fallback, it returns the **spec key** the
  validator actually matched — so a spec declaring only `5XX` now
  reports `matchedStatusCode() === "5XX"` for a 503 response. Callers
  building `503 → row` maps from the result will see the key change
  for any status that resolves via fallback. Skipped responses still
  report the literal status (skip happens before key resolution).

### Documentation

- **#123** — README comparison table now correctly shows `✅` for
  response header validation (was `❌` despite shipping in v0.12.0).
- **#127** — README Installation section calls out the
  `symfony/yaml` requirement for YAML specs as a dedicated callout
  block, not buried in the Requirements list.

## v0.12.0 — 2026-04-25

This release closes Sprint A — every issue scheduled before v1.0 except the
monorepo split (#114). Big-ticket items: response header validation, external
`$ref` resolution (local files + opt-in HTTP(S)), and a fully overhauled
coverage tracker that records at `(status, content-type)` granularity. See
each section below for migration notes.

### Sprint A highlights

- **#110 — Response header validation**: `OpenApiResponseValidator::validate()`
  now accepts an optional `?array $responseHeaders` and validates them against
  the spec's `headers:` block on the matched response. Errors use the
  `[response-header.<Name>]` prefix. The Laravel trait wires this in
  automatically by passing `$response->headers->all()`.
- **#108 — External `$ref` resolution**: relative file refs
  (`./schemas/pet.yaml#/Pet`) now resolve transparently at spec load time;
  cycles, broken paths, and missing files surface as structured errors rather
  than crashes. Opt-in HTTP(S) ref resolution is available by passing a
  PSR-18 `ClientInterface` + PSR-17 `RequestFactoryInterface` to
  `OpenApiSpecLoader::configure()` with `allowRemoteRefs: true` —
  Guzzle / Symfony HttpClient both work. See `composer.json` `suggest`.
- **#112 — README competitor comparison**: full feature matrix vs Spectator,
  league/openapi-psr7-validator, hkulekci/...

### Breaking — coverage granularity (#111)

- **Coverage granularity expanded** to `(method, path, statusCode, contentType)`
  ([#111](https://github.com/studio-design/openapi-contract-testing/issues/111)).
  - `OpenApiCoverageTracker::record()` is **removed**. Use the new
    `recordRequest(spec, method, path)` and
    `recordResponse(spec, method, path, statusKey, contentTypeKey, schemaValidated, skipReason?)`.
    Library-internal call sites (the Laravel trait) are updated automatically;
    direct callers must migrate. `$contentTypeKey` is `?string`: pass `null`
    when no content-type lookup applies (e.g. the response was skipped before
    content negotiation, or it is a 204-style entry) — null is internally
    stored under the `*` sentinel and reconciled against spec declarations
    that have no `content` block.
  - `OpenApiCoverageTracker::__construct` is now `private`. The class was
    static-only in practice but you could previously call `new` on it; that
    now raises `Error`. Use the static API directly.
  - `OpenApiCoverageTracker::computeCoverage()` returns a new shape with
    per-endpoint sub-rows (one per declared `(status, content-type)` pair),
    response-level totals, an `EndpointSummary['state']` field
    (`all-covered` | `partial` | `uncovered` | `request-only`), and an
    `unexpectedObservations` list. Old keys (`covered`, `uncovered`, `total`,
    `coveredCount`, `skippedOnly`, `skippedOnlyCount`) are gone.
  - `OpenApiCoverageTracker::getCovered()` is retained as a diagnostic shim
    returning `array<spec, array<"METHOD path", true>>`. Prefer
    `hasAnyCoverage(spec): bool` for presence checks and `computeCoverage()`
    for full results.
  - `MarkdownCoverageRenderer` and `ConsoleCoverageRenderer` produce
    visibly different output (per-endpoint sub-rows, partial/skipped
    markers). Consumers that scrape these outputs may need updates.
  - `OpenApiResponseValidator::validate()` now returns
    `OpenApiValidationResult::skipped()` (was: silent `success()`) when the
    spec declares only non-JSON content types and the caller did not supply
    a `responseContentType` — there is no schema engine to validate against,
    so coverage records the response as `skipped` instead of crediting it
    as validated. Callers that pass the actual response Content-Type
    continue to be marked `validated` whenever the type is in the spec.
  - Coverage rates are now reported at **two granularities** — endpoint
    (`endpointFullyCovered / endpointTotal`) and response definition
    (`responseCovered / responseTotal`).

### Added

- `OpenApiValidationResult::matchedStatusCode()` and `matchedContentType()`
  expose which spec response key and media-type key the validator selected,
  so coverage tracking can record per-pair granularity. For skipped
  responses, `matchedStatusCode()` is the **literal HTTP status string**
  (e.g. `"503"`) and is reconciled to spec range keys (`5XX` / `5xx` /
  `default`) at compute time.
- `OpenApiCoverageTracker::ANY_CONTENT_TYPE` (`"*"`) sentinel for responses
  recorded before content-type lookup (skipped, 204, non-JSON-only).
- `Validation/Response/ResponseBodyValidationResult` DTO carries the body
  validator's errors plus the matched spec content-type key. Replaces the
  bare `string[]` return.
- New fixture `tests/fixtures/specs/range-keys.json` exercising spec-side
  `5XX` and `default` response keys for reconciliation tests.
- `CoverageReportSubscriber` extracted from the inline anonymous subscriber
  in `OpenApiCoverageExtension` so PHPStan can resolve the imported
  `CoverageResult` shape end-to-end.

### Changed

- `Validation/Response/ResponseBodyValidator::validate()` returns
  `ResponseBodyValidationResult` instead of `string[]`. Internal class —
  external callers were not expected, but if you instantiated it directly,
  unwrap `->errors` from the new return value.
- `Validation/Support/ContentTypeMatcher::findContentTypeKey()` is added
  to surface the spec's literal media-type key (with original casing)
  matching a normalized content-type. Used by the body validator and
  coverage tracker so the spec author's casing is preserved in reports.

### Notes

- Spec-undefined statuses observed at runtime (e.g. real `503` against a
  spec that declares neither `503` nor `5XX`) now appear in
  `EndpointSummary['unexpectedObservations']` rather than silently inflating
  endpoint coverage. Behavior change worth flagging for users that relied
  on the old loose counting.
- Skip-by-status-code remains opt-in via `skip_response_codes`; matched
  literal statuses (e.g. `"503"`) reconcile against any spec range key
  (`5XX` / `5xx` / `default`) declared on the same endpoint at compute
  time, so a `5\d\d` skip on an endpoint declaring `5XX: application/json`
  surfaces as `skipped` rather than `uncovered`.
- A spec that declares both `default` and `5XX` for the same content-type
  on the same endpoint is treated as **two distinct response definitions**
  in the totals — a single `503` recording credits both. Spec authors
  rarely write both; if they do, response-level totals reflect that.
- `OpenApiCoverageTracker::computeCoverage()` emits `E_USER_WARNING`
  (visible to PHPUnit) when the spec contains structurally invalid
  branches inside `paths` (e.g. `responses: 200` as a scalar). Coverage
  proceeds with the malformed entries omitted; without the warning the
  user would silently see a smaller `responseTotal` than reality.

### Other changes since v0.11.0

40 PRs landed in this window. Highlights not already covered above:

- Auto-validate-request hook (#69 — Laravel trait validates the request
  body, query, headers, cookies, and security against the spec on every
  HTTP test call when `auto_validate_request: true`)
- Auto-inject-dummy-bearer for `actingAs()` tests (#75)
- `withoutValidation()` / `withoutResponseValidation()` /
  `withoutRequestValidation()` per-test scoped opt-outs
- `skipResponseCode()` per-test fluent API on top of the config-level
  `skip_response_codes` (#76)
- `#[SkipOpenApi]` attribute for class- and method-level opt-outs (#55)
- `#[OpenApiSpec]` attribute for class- and method-level spec selection
- `OpenApiValidationOutcome` enum — exhaustive `match` over
  `Success` / `Failure` / `Skipped` instead of the two prior bool predicates
- Per-sub-validator error boundary (#92) — opis `RuntimeException`
  thrown from a body or header validator no longer aborts the whole
  orchestrator; surfaces as a structured `[response-body]` /
  `[response-header]` error string with the original `getPrevious()`
  chain preserved
- YAML spec loading via `symfony/yaml` (#80)
- Internal `$ref` resolution (#77)
- readOnly / writeOnly enforcement on response / request bodies (#52)
- Vendor-suffix JSON content types (`application/problem+json`,
  `application/vnd.api+json`, ...) treated as JSON-compatible
- Strip-prefixes (`/api`) for path matching
- `console_output` modes: `default` / `all` / `uncovered_only`
  (overridable via `OPENAPI_CONSOLE_OUTPUT` env var)

For the complete commit list:
`git log v0.11.0..v0.12.0 --oneline`.
