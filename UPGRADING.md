# Upgrade Guide

This file documents non-trivial upgrades between releases. Patches with
no behaviour change are not listed here — see `CHANGELOG.md` for the
full record.

Sections are ordered newest-first. If you are jumping multiple minors,
read each intermediate section in order — behavioural changes compose.

## Within v1.x

The v1.x line is covered end-to-end by SemVer (see "v0.x → v1.0.0"
below for the surface contract). Minor releases are additive by default.
Two behavioural changes exist so far: v1.3.0 (gated on an already-opt-in
flag) and v1.8.0 (`discriminator.mapping` enforcement, default-on with an
opt-out flag — see directly below).

### From v1.7.0 → v1.8.0

- **`discriminator.mapping` is now enforced** (#262). Previously the
  converter stripped `discriminator` and emitted a one-shot
  `E_USER_WARNING`; the underlying `oneOf` / `anyOf` was validated only as
  a plain union, so a polymorphic body that lied about its type passed as
  long as it matched any branch. The converter now lowers `discriminator` +
  `mapping` into Draft-07 `if`/`then` conditionals so the discriminator
  value steers validation toward a single branch.
  - **Behaviour change**: a body whose discriminator value routes to a
    branch it does not satisfy (e.g. `kty: RSA` carrying EC-only fields, or
    an unknown discriminator value) now **fails** where it previously
    passed. This is the contract bug the warning only narrated.
  - **The `discriminator.mapping` `E_USER_WARNING` is removed.** This also
    fixes Laravel consumers, whose `HandleExceptions` turned that advisory
    warning into a fatal `ErrorException` on the first polymorphic contract
    test. No per-consumer `set_error_handler` boilerplate is needed any
    more.
  - **Opt out**: set `enforce_discriminator: false` (Laravel
    `config/openapi-contract-testing.php`) or
    `<parameter name="enforce_discriminator" value="false"/>` (the PHPUnit
    `OpenApiCoverageExtension`; `0` / `no` also work) to keep the old
    strip-without-enforce behaviour (now also warning-free).
  - **Malformed `discriminator`** blocks (missing/non-string
    `propertyName`, non-array `mapping`, non-string mapping value,
    unresolvable mapping pointer, non-object target) now surface as a loud
    validation failure under enforcement, instead of being silently
    dropped.
  - **Known limitation**: self-referential discriminator chains (a subtype
    that re-contains the same base discriminator via `allOf` + `$ref`) are
    enforced at the first recursion level; the inner re-appearance is
    stripped without re-lowering (the outer branch already enforces it).
    See `docs/supported-features.md` → "Schema features" → `discriminator`.

### From v1.3.0 → v1.4.0

- No source-code changes required.
- **New `AssertsNoEnumDrift` PHPUnit trait** (#186). Wraps
  `EnumDriftAsserter::assertNoDrift()` so drift tests increment PHPUnit's
  assertion counter and stop being flagged risky under PHPUnit 13's
  `beStrictAboutTestsThatDoNotTestAnything=true` default. Drop-in for
  existing drift tests:

  ```php
  use Studio\OpenApiContractTesting\PHPUnit\AssertsNoEnumDrift;

  final class EnumDriftTest extends TestCase
  {
      use AssertsNoEnumDrift;

      #[Test]
      public function no_drift(): void
      {
          $this->assertNoEnumDrift([StatusEnum::class, RoleEnum::class]);
      }
  }
  ```

  The static `EnumDriftAsserter::assertNoDrift()` API is unchanged —
  non-PHPUnit drift CI scripts keep working as-is.
- **Internal move**: `StackTraceFilter` moved from
  `Studio\OpenApiContractTesting\Laravel\Internal\` to
  `Studio\OpenApiContractTesting\Internal\`. The class is `@internal` and
  outside the SemVer surface; the move is mentioned only for the rare
  consumer who imported it directly (which the `@internal` marker said
  not to do).

### From v1.2.0 → v1.3.0

- No source-code changes required.
- **`auto_validate_request: true` now downgrades documented 4xx failures
  to `Skipped`** (#182). When request validation is enabled AND the
  response status matches `skip_request_validation_response_codes`
  (default `['422', '400']`) AND the spec documents that status for the
  operation, the request-validation `Failure` becomes `Skipped`. This
  removes false-fails for dataProvider tests that intentionally send
  invalid input to verify documented 4xx behaviour.

  - Undocumented 4xx responses still fail loudly (real spec gap).
  - Successful responses are never demoted.
  - Tests asserting that the request validator returns `Failure` for
    invalid input + documented 422 will need updating — assert
    `Skipped` (or `isSkipped() === true`) instead.
  - To restore strict pre-v1.3 behaviour, set
    `skip_request_validation_response_codes => []` in your Laravel
    config. The downgrade is gated on `auto_validate_request: true`, so
    suites that never enabled auto-validation are unaffected.
- **New `auto_inject_dummy_credentials` flag** (#180). Superset of the
  existing `auto_inject_dummy_bearer`: also fills dummy values for
  `apiKey` (header / cookie / query) schemes in the validator's view
  when the test did not supply a real credential. Off by default; the
  legacy bearer-only flag still works and is bypassed when the new flag
  is on. No migration required unless you opt in.

### From v1.1.0 → v1.2.0

- No source-code changes required.
- **New `enum_spec_base_path` PHPUnit extension parameter** (#171).
  Optional secondary root used only for `#[BoundToOpenApiEnum]` path
  resolution. Set it when per-enum JSON sources live outside
  `spec_base_path` (e.g. `openapi/_shared/...` while bundles live in
  `openapi/bundled/`). Single-root projects: omit it — behaviour is
  bit-for-bit identical to v1.1.x. See README → "Enum drift detection"
  for the bundled-external layout recipe.
- **Markdown coverage output formatting** (#176). The Markdown renderer
  now emits a blank line between each endpoint heading and its response
  table. Visual-only fix; only relevant if a downstream consumer parses
  the Markdown by line offsets.

### From v1.0.0 → v1.1.0

- No source-code changes required.
- **New enum drift detection surface** (#166). Static set-membership
  comparison between PHP backed enums and their bound OpenAPI `enum:`
  arrays — catches PHP-only cases the runtime never observes AND
  spec-only values the implementation cannot produce. New public symbols
  (all `final`, additive, covered by v1.x SemVer):

  - `Attribute\BoundToOpenApiEnum(string $specPath)` — bind a PHP enum
    to its spec file. Path resolves relative to
    `OpenApiSpecLoader::getBasePath()`.
  - `Schema\EnumDriftAsserter::assertNoDrift(array $enumFqcns, bool $failOnDrift = true)`
    — fatal by default; `failOnDrift: false` demotes to a single
    `E_USER_WARNING` per drifting binding.
  - `Schema\EnumDriftAsserter::detectAll()` — non-throwing inspection
    seam returning `EnumDriftReport[]`.
  - `Schema\EnumDriftReport`, `Exception\EnumDriftException`,
    `Exception\EnumBindingException` + `EnumBindingReason`.

  Adopting is opt-in — without `#[BoundToOpenApiEnum]` on any enum,
  nothing runs.
- **Opt-in auto-discovery via the PHPUnit extension** (#168). Three new
  parameters scan PSR-4 namespaces at bootstrap and run drift checks
  before any test executes:

  ```xml
  <parameter name="enum_drift_enabled" value="true"/>
  <parameter name="enum_drift_scan_namespaces" value="App\Enums,App\Domain\Enums"/>
  <parameter name="enum_drift_fail_on_drift" value="true"/>
  ```

  Defaults: `enum_drift_enabled=false` (master opt-in),
  `enum_drift_fail_on_drift=true` (FATAL + `exit(1)` on drift).
  Misconfiguration (unresolvable namespace, missing Composer
  `ClassLoader`, `EnumBindingException`) always FATALs regardless of
  `enum_drift_fail_on_drift` — setup errors must not hide drift signals.

## v0.x → v1.0.0

v1.0.0 is the API stability commitment. Anything not marked `@internal`
in v1.0.0 is covered by SemVer for the v1.x line.

### What's frozen by SemVer in v1.x

- Public class names and namespaces
- Public method signatures
- Public constants and their values
- Enum cases (additions are SemVer-minor; removals are SemVer-major)
- The `OpenApiValidationResult` shape
- The CLI surface of `bin/openapi-coverage-merge`
- The `OpenApiCoverageExtension` PHPUnit configuration parameters
- The Laravel `ValidatesOpenApiSchema` trait's public methods

### What's NOT frozen (will not break SemVer when changed)

- Anything marked `@internal`. This includes
  `OpenApiSpecLoader::clearCache/evict/reset`,
  `OpenApiCoverageTracker::reset/exportState/importState`,
  `ValidatesOpenApiSchema::resetValidatorCache`, and
  `OpenApiSchemaConverter::resetWarningStateForTesting`.
- The shape of paratest sidecar JSON (versioned via `STATE_FORMAT_VERSION`).
- Internal helper classes under `Internal/`, `Validation/Support/` (the
  classes themselves are not user-callable).
- Error messages from validators (we may improve them).
- The set of `format` keywords delegated to opis (we follow opis).

### What's NOT covered by SemVer at all

- OpenAPI features explicitly marked "not supported" or "presence-only"
  in README "Supported features and known limitations"
- Behaviour of bug-fix releases that close a documented silent-pass case
  (a test that passed only because of the silent pass may start failing —
  that's the bug fix doing its job, not a SemVer break)

### Migration notes by source version

There are **no breaking source-code changes** from any v0.16+ release to
v1.0.0. v1.0.0 is a stability promotion of v0.19.0 — every fix from the
v0.16 → v0.19 dogfood cycle is in v1.0.0 unchanged. The behavioural
changes listed below ship as part of those minors and may surface when
upgrading; review the section that matches your starting version and
forward.

If you are on v0.14.0 or older, apply the v0.14.0 → v0.15.0 namespace
migration first (see the table below), then read this section top-to-bottom.

#### Common to all v0.x → v1.0 upgrades — new `E_USER_WARNING`s

The library uses `trigger_error(..., E_USER_WARNING)` as its v1.0 official
silent-pass channel (see README → "Warning channel"). Tests configured
with PHPUnit's `failOnWarning="true"` (the default in this repo's
`phpunit.xml.dist`) will fail on first encounter. The categories that
ship between v0.16.0 and v1.0.0 are:

| Category prefix | Source | Warns on |
|---|---|---|
| `[security]` | `SecurityValidator` | `oauth2`, `openIdConnect`, `mutualTLS`, `http-basic`, `http-digest` schemes (silently passed before) |
| `[OpenAPI Schema]` | `OpenApiSchemaConverter` | `unevaluatedProperties` / `unevaluatedItems` (Draft 07 has no equivalent), `discriminator.mapping` (stripped — mapping does not steer validation), unknown / malformed `format` values |

Each warning is dedup'd per-process and prefixed with the category tag so
you can filter them mechanically. To suppress one category, install a
`set_error_handler` that matches the prefix; do NOT blanket-suppress
`E_USER_WARNING`. To stay green without filtering, set
`failOnWarning="false"` in your project's `phpunit.xml.dist`.

#### From v0.15.0 → v1.0.0

- No source-code changes required.
- All warnings in the table above apply; previously these were silent passes.
- v0.15.0 already required updating `use Studio\OpenApiContractTesting\X`
  imports per the v0.14.0 → v0.15.0 migration table — that is unchanged.

#### From v0.16.0 → v1.0.0

- No source-code changes required.
- **`discriminator.mapping`** now warns on first encounter (#147). Specs
  with non-empty `mapping` previously silently dropped the keyword;
  validation behaviour is unchanged (the underlying `oneOf` / `anyOf` is
  still validated as a union).
- **Unknown / malformed `format` keywords** now warn (#151). A typo like
  `format: emial` previously silently passed every string; the converter
  now emits a one-shot warning per unknown format value.

#### From v0.17.0 → v1.0.0

- No source-code changes required.
- **Multi-JSON-per-status schema selection** behaves differently (#152).
  When a spec declares multiple JSON keys for the same status (e.g.
  `application/json` AND `application/problem+json`) and the response
  carries a JSON-flavoured `Content-Type`, the validator now prefers the
  spec key that exactly matches the actual `Content-Type` before falling
  back to the first JSON key. Pre-v0.17 behaviour was first-JSON-wins —
  problem-details bodies served as `application/problem+json` were judged
  against the success-shape `application/json` schema. Single-JSON specs
  and vendor `+json` suffixes the spec doesn't enumerate are unaffected.

#### From v0.18.0 → v1.0.0

- No source-code changes required.
- **`additionalProperties: false` cascade dedup** strips the pseudo-error
  that named declared properties as not-allowed when any sub-property
  failed (#159). A 1-error failure that previously inflated to 2 errors
  collapses back to 1. If a test was asserting the exact error count or
  the cascade message, update the assertion.

#### From v0.19.0 → v1.0.0

- No source-code changes required.
- **Cascade dedup now walks across array boundaries** (#161). Cascades
  through `{ data: [Item] }`-shaped envelopes — including the shape
  `OpenApiSchemaConverter` lowers OAS 3.1 `prefixItems` to — collapse the
  same way as the root-level dedup did in v0.18.0. Same assertion-update
  caveat as v0.18.0.

## v0.14.0 → v0.15.0

The "v1.0 prep" release. Twenty-two classes moved into focused
sub-namespaces. No compat shim — pre-1.0 breaking changes are the
contract for this release line.

See the v0.15.0 entry in `CHANGELOG.md` for the full migration table.
The mechanical fix is updating `use Studio\OpenApiContractTesting\X`
imports per the table.
