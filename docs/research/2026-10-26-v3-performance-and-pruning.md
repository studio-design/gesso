# v3 research: performance and pruning

- Date: 2026-10-26
- Status: research input for [ADR 0007](../adr/0007-v3-scope-and-release-window.md)
  (Proposed). Internal; not shipped in the package.
- Baseline: `origin/main` at `6f2c26c`.

## Method and caveats

The largest checked-in fixture, `petstore-3.1.json`, is only 32 KB, so the
measurements used **synthetic specs** produced by a small generator (not
committed): N resources × 5 operations, with shared component schemas, nested
`$ref`, and `allOf`.

| Spec | Operations | Size |
| --- | --- | --- |
| big10 | 50 | 94 KB |
| big100 | 500 | 0.9 MB |
| big400 | 2000 | 3.7 MB |

- PHP 8.5.5 NTS, `opcache.enable_cli=Off`, no xdebug or pcov, on a developer
  laptop. Absolute numbers will differ on CI runners and with opcache on; the
  ratios are the result.
- One scenario per process. Timings are **best of 5**, because single runs varied
  by up to ±40%. Some cells are single runs; see the note under the baseline
  table.
- `peak` is `memory_get_peak_usage()`; `retained` is the `memory_get_usage()`
  delta across the scenario.
- Response scenarios alternate a 10-item list body (`GET /v1/resN`) with a
  single-item body (`GET /v1/resN/{id}`). Request scenarios alternate a POST JSON
  body with a GET carrying a query and a header.
- Separately, a per-stage microbenchmark timed each step of one response
  validation, and a real `vendor/bin/phpunit` run with one empty test measured
  boot cost with the extension enabled.
- Prototypes of the changes below were applied in a scratch checkout to measure
  them; the PR-A prototype was run against the Unit and Integration suites.

## 1. Baseline

| Scenario | big10 | big100 | big400 |
| --- | --- | --- | --- |
| Cold load (decode + `$ref` resolve + normalize) | 7.1 ms / peak 6.5 MB / retained 1.6 MB | **50.9 ms / 34.4 MB / 13.1 MB** | **253 ms / 127.4 MB / 51.5 MB** |
| 1k response validations, one validator reused | 507 ms | 493 ms | 645 ms |
| 1k response validations, **new validator each call** (what a PHPUnit suite does, see 2.2) | 942 ms | **1012 ms** | 9.6 s per 5k (≈1.9 s per 1k) |
| 1k request validations, reused | 85 ms | 86 ms | n/m |
| 1k request validations, new validator each call | 278 ms | **466 ms** | n/m |
| Coverage tracker, 100k request + response observations | n/m | n/m | 200 ms, retained 1.7 MB (bounded by operation count) |
| `exportState()` + `CoverageSidecarWriter::write` | n/m | n/m | 1.6 ms, 116 KB |
| `computeCoverage()` | n/m | 3.1 ms | 8.1 ms |
| 20k validations, memory growth check | n/m | n/m | retained flat at 22.4 MB (no leak) |

Single runs: the big10 and big400 validation cells, and the coverage-tracker,
sidecar, `computeCoverage()`, memory-growth, and boot rows. Every other big100
cell and every load cell is best of 5.

**Copies of the spec held in memory.** For big100, `json_decode(assoc)` alone
retains 4.0 MB, and the loader cache retains 13.1 MB, about 3.3× the raw decode.
With the PR-A prototype it retains 4.8 MB (about 1.2×). No other component keeps
its own copy: `ResponseSchemaResolution`, the coverage tracker, and the strict
trackers all read `OpenApiSpecLoader::load()` by value through copy-on-write. The
bloat comes entirely from how the loader builds its one cached array (§2.1).

Per-stage cost of one response validation (big100, 10-item list body, µs per op):

| Stage | µs |
| --- | --- |
| `resolveOperation` + `resolveResponseSchema` (warm matcher) | 4 |
| `new ResponseSchemaResolver` + first resolve (path-matcher compile, 200 templates) | **178** |
| `OpenApiSchemaConverter::convert` | 39 |
| `ObjectConverter::convert` schema / body | 8 / 16 |
| `serialize` + `sha256` canonical fingerprint | 17 |
| opis `validate`, warm, 2020-12 dialect | **461** (Draft 07: 344) |
| opis on a **fresh** runner (parse + validate) | **847** |
| `StrictRequiredBodyWalker::collectPointers` | **70** |
| `StrictAdditionalPropertiesInspector::inspect` | **187** |
| full `validate()`, reused validator | 839 |

Boot (real `vendor/bin/phpunit`, one empty test, extension at default settings;
single runs):

| Config | Wall | Memory at test | Gesso classes loaded |
| --- | --- | --- | --- |
| No extension | 0.21 s | 19.3 MB | 0 |
| Extension + big10 | 0.21 s | 21.2 MB | 41 |
| Extension + big100 | 0.25 s | **33.5 MB** (25.3 MB with PR-A) | 41 |

## 2. Top bottlenecks

### 2.1 Spec load holds about 3.3 copies of the document (memory and cold time)

1. **`$ref` targets are re-walked at every reference site.**
   `src/Spec/OpenApiRefResolver.php:1610` (`resolveFragmentIn`) calls `walk()` on
   a fresh lookup of the target each time the target is referenced. `walk()`
   iterates by reference (`:1177`, `foreach ($node as $key => &$child)`), which
   separates every array it touches. A schema referenced 500 times becomes 500
   physical copies.
2. **The normalizer rebuilds every array.**
   `src/Internal/OpenApiDocumentShapeNormalizer.php:271-287` (`normalizeGeneric`)
   writes `$normalized[$key] = …` for every node, including nodes it does not
   change. That undoes copy-on-write sharing, so fixing (1) alone does not lower
   retained memory.

The extension calls `OpenApiSpecLoader::load()` eagerly at bootstrap
(`src/PHPUnit/OpenApiCoverageExtension.php:198`), so every run pays this cost,
and every forked paratest worker pays it again.

### 2.2 Validator-scoped caches are rebuilt for every test (time)

The adapters cache validators on the TestCase instance: Laravel
`src/Laravel/ValidatesOpenApiSchema.php:768,953` (plus `buildOneOffValidator`,
`:967`, which builds a new one every time) and Symfony
`src/Symfony/OpenApiAssertions.php:124,357,366`. PHPUnit creates a new TestCase
object per test method, so in practice each test builds a new validator. Each
new validator constructs a new `SchemaValidatorRunner`
(`src/OpenApiResponseValidator.php:82`, `src/OpenApiRequestValidator.php:81`),
which brings a new opis `Validator` with an empty parsed-schema cache and an
empty canonical cache. It also constructs a new `ResponseSchemaResolver`, whose
`$pathMatchers` (`src/Validation/Response/ResponseSchemaResolver.php:94`) start
empty. Every test therefore re-parses each schema in opis (+386 µs over warm) and
re-compiles the path index (178 µs at 200 templates, linear in path count). The
"new validator each call" rows in §1 show the effect: about 2× slower for
responses and 5.4× slower for requests.

### 2.3 Strict-mode work runs when strict mode is off (time and memory)

`src/OpenApiResponseValidator.php:396` and `:406` call
`maybeRecordStrictRequired` and `maybeRecordStrictAdditionalProperties` on every
successful response. They walk the body (70 µs) and run the
additional-properties inspector over body and schema (187 µs), then record into
run-level trackers that hold their data for the whole run. None of this depends
on `strict_required` or `strict_additional_properties`, both of which default to
`off`. The docblock at `src/Validation/Strict/StrictRequiredMode.php:14` ("off —
observations are not recorded") is false. This costs 31% of a reused-validator
response validation. Request validation is unaffected.

### 2.4 Per-call schema conversion and fingerprinting (time, minor)

`src/Validation/Response/ResponseBodyValidator.php:93,101`, then
`SchemaValidatorRunner::canonicalSchema()`
(`src/Validation/Support/SchemaValidatorRunner.php:363`). Each call converts the
schema, converts it to objects, then serializes and SHA-256-hashes it, only to
find a schema opis has already parsed: 64 µs/op, or 8% of a reused-validator
call. The canonical cache clears completely at 1024 entries (`:54`, `:370`).
That is fine while the runner is per-test, but it will thrash once the runner is
shared across a suite with more than 1024 distinct schemas (big400 already has
about 1000).

### 2.5 Not bottlenecks (measured)

- **Coverage tracking.** 2 µs per observation pair, bounded by
  `(method, path, status, content-type)`. Sidecar export is under 2 ms.
- **Deprecation channel.** `src/Internal/Deprecations.php:92-98` deduplicates by
  id and calls `trigger_error` once per id per process. The 18
  `LegacyConfigurationKeys` sites fire only at boot.
- **Boot class loading.** 41 classes and about 2 MB over no extension, under
  50 ms. Enum drift, fuzz, Arazzo, and stubs are not loaded when off. Pruning
  classes would be cosmetic; the real boot cost is the eager spec load (§2.1).
- **opis itself** takes 55% of a call (461 µs for 10 items under 2020-12).
  Replacing the engine is out of scope: it would touch protected-core dialect
  behaviour.

## 3. Concrete changes

| # | Change | Files | Expected gain | Evidence |
| --- | --- | --- | --- | --- |
| **P1** | Memoize the walked `$ref` target per resolution, keyed by `chainKey`, `targetIsSchema`, the target context's `schemaDeclaration`, and `sourceFile`. Save and restore the memo around re-entrant `resolve()` calls. This is sound for cycle detection: a target that resolved once has an acyclic closure, and any cycle through it would have been thrown on its first walk. Make `normalizeGeneric` return its input unchanged when no child changes, so copy-on-write sharing survives. | `src/Spec/OpenApiRefResolver.php`, `src/Internal/OpenApiDocumentShapeNormalizer.php` | big100: 50.9 → **25.8 ms**, peak 34.4 → **13.9 MB**, retained 13.1 → **4.8 MB**. big400: 253 → **121 ms**, 127 → **45 MB**, 51.5 → **18.0 MB**. Extension boot with big100: 33.5 → 25.3 MB. | **Prototyped and measured.** Unit (3808 tests) and Integration (291, including `tests/Integration/Conformance`: 4 tests and 270 assertions) green; PHPStan clean. The one PHPUnit warning, in `ContractNamespaceSeparateProcessTest`, also appears on clean main. Either half alone is worse: the memo alone gives 26 ms but still 13.1 MB retained; the normalizer alone gives 44 ms but 14.8 MB retained. |
| **P2** | Skip both strict recordings when the run-level mode and the per-call mode are both off. Give each tracker an `enabled` bit (`StrictRequiredTracker::isRecording()`, same on `StrictAdditionalPropertiesTracker`). The extension sets it when `strict.required.run` / `per_call` is not `off`; the bit defaults to on for directly constructed trackers, so explicit direct callers keep today's behaviour. The validator checks it before calling the walker or inspector. Fix the `StrictRequiredMode` docblock. Cost of staying non-breaking in 2.x: users of the Laravel trait or the bare validator *without* the PHPUnit extension see no gain until v3 flips the default to off. | `src/OpenApiResponseValidator.php` (2 guards), `src/Validation/Strict/StrictRequiredTracker.php`, `src/Validation/Strict/StrictAdditionalPropertiesTracker.php`, `src/PHPUnit/OpenApiCoverageExtension.php` (sets the bit) | Reused validator: 493 → **321 ms** per 1k (−35%). New validator each call: 1012 → 840 ms. No strict observations retained when off. | Measured with an environment-gated prototype. |
| **P3** | Pool process-wide state inside the classes that own it, so public constructors stay unchanged. (a) `SchemaValidatorRunner` keeps a static pool of `{opis Validator, canonical cache}` keyed by `maxErrors`, reused by `new SchemaValidatorRunner($n)`. (b) The path matcher moves out of the per-resolver `$pathMatchers` into a static map keyed by `specName` and `stripPrefixes`. (c) Replace the `serialize` + `sha256` fingerprint with identity: cache the converted schema object per `(specName, method, path, statusKey, contentType, context, version, dialect, DiscriminatorEnforcement::isEnabled())`. **The enforcement flag must be in the key**, because Laravel toggles the process-global gate per validator build (`applyDiscriminatorEnforcementConfig`); leaving it out would serve schemas converted under the other setting. Use a bounded LRU instead of the clear-all at 1024. **Both pooled structures must hang off the loader cache's lifetime and clear in `OpenApiSpecLoader::clearCache()` / `reset()`**: the suite reloads different documents under the same spec name. Add `resetForTesting()` per `AGENTS.md`. | `src/Validation/Support/SchemaValidatorRunner.php`, `src/Validation/Response/ResponseSchemaResolver.php`, `OpenApiSpecLoader::clearCache` hook | Response, new validator each call: 1012 → **489 ms** (−52%); with P2, **327 ms** (−68%, 3.1×). Request, new validator each call: 466 → **82 ms** (5.7×). big400, 5k responses: 9.6 s → 2.8 s. Memory unchanged. (c) is expected to add about −8% (64 µs/op of fingerprinting). | (a) and (b) measured; (c) estimated from the stage table. |
| P4 | A separate cache of converted schemas per `(spec, pointer)`, in addition to the opis cache. | n/a | −35 ms per 1k (−10%) for **+6 MB retained** | Measured. **Recommend against**: it trades the memory goal for time, and its useful part is folded into P3(c). |
| P5 | `SpecDocumentDecoder::decodeJson` is 4× `json_decode(assoc)` (8 ms vs 2 ms on big100) because it decodes to objects and then flattens them; after P1 that is about 30% of load time. Possible approach: fold the flattening into a single recursive pass that only special-cases empty objects. Keep it only if it measures better. | `src/Internal/SpecDocumentDecoder.php` | about −6 ms of 26 ms (big100) | Estimated, not prototyped. Low priority. |
| P6 | Drop the per-call `array_keys($spec['paths'])` at `src/Validation/Response/ResponseSchemaResolver.php:93` when the matcher is already cached. | same as P3 | under 1%; removes allocations | Free with P3. |

Combined effect for a typical suite (one or two validations per test, 500-op
spec): about **3× faster validation** and **about 60% lower peak memory per
worker**, from the first test. These are synthetic-spec, opcache-off figures;
confirm them on a real downstream suite before quoting them publicly.

## 4. Dead-weight inventory (deprecated or duplicated surfaces)

| Item | Where | src LOC removed | Note |
| --- | --- | --- | --- |
| 18 legacy PHPUnit parameter spellings (`strict_required`, `min_*_coverage`, `*_output`, `baseline_*`, …) and their notice map | `src/Config/LegacyConfigurationKeys.php` | 202 | Deprecated in v2, `removed_in 3.0` (`tests/fixtures/compatibility/v2-deprecations.json`). |
| Per-parameter string readers in the extension (`resolveBaselineFileParameter` … `resolveMode`) | `src/PHPUnit/OpenApiCoverageExtension.php:600-1167` | about 280 of 568 | ADR 0005: the extension takes a single `config` parameter. Thresholds, status lists, and modes come from `GessoConfig`'s typed accessors. `detectPartialRun` / `readTestSuiteList` stay. |
| `ConfigurationBridge` (typed config turned back into strings for the string parsers) and `ExtensionParameters` | `src/Config/ConfigurationBridge.php`, `src/PHPUnit/ExtensionParameters.php` | 99 + about 60 of 96 | Exists only because two configuration sources coexist. |
| Fuzz→Contract class alias loader (7 aliases) | `src/Contract/Autoload.php` + `composer.json` `autoload.files` | 115 | `removed_in 3.0`. Also removes an autoload `files` entry that runs on every request. |
| Legacy sidecar envelopes 2–9 and bare v1 state | `src/Coverage/CoverageSidecarEnvelope.php:88-95`, `:163-212` (v1 branch), `:301-373`, plus the lower-bound paths in `CoverageMergeCommand::reportDeprecations` (`:909-960`) | about 150 | Sidecars are written and merged within one CI run by the same Gesso version, so cross-major reading has no real user. Needs a `docs/versioning.md` "Versioned sidecar compatibility" edit, because 2.x promised the reader. |
| `enum_spec_base_path`, `configure(enumBasePath:)`, `getEnumBasePath`, `BoundToOpenApiEnum::$specPath` | `OpenApiCoverageExtension.php:842-913`, `OpenApiSpecLoader` | about 110 | `removed_in 3.0` (#508). |
| Single-method deprecations (`hasAnyCoverage`, `EnumBindingException::forBinding`, `SkipOpenApiResolver::shouldSkipOpenApi`, `auto_inject_dummy_bearer`) | various | about 60 | `removed_in 3.0`. |
| **Duplicated run gates**: strict-required, strict additional properties, threshold, coverage baseline, and deprecation reporting are implemented twice | `src/PHPUnit/CoverageReportSubscriber.php:294-447, 636-658, 755-833` vs `src/Coverage/CoverageMergeCommand.php:909-1264` | about 300 (extract one internal `RunGates`) | Maintenance win, not performance. |
| **Duplicated config parsers**: thresholds, status-code lists, and modes are each parsed at 4 sites | (1) `GessoConfig::leafValue` / `percentValue` / `assertStatusCodePatterns` `:432-603`; (2) extension `resolveThresholdParameter:986`, `resolveStatusCodeListParameter:1083`, `resolveMode:1124`; (3) `CoverageMergeCommand::parseArgv:149`, `withSharedDefaults:811`, `resolveThreshold:1264`; (4) Laravel trait `config()` readers `ValidatesOpenApiSchema.php:1018,1052,1178` | counted in the rows above, plus about 80 in merge and Laravel | Collapse to `GessoConfig` plus the one ADR 0005 string grammar. |
| `LegacyIdentity` (old environment variables and Artisan names) | `src/Internal/LegacyIdentity.php` + 5 call sites | 170 | **Keep in v3.** The code says `REMOVED_IN = '4.0.0'`, and `v3-renames.json` marks it `accepted-spelling`, so it is promised through v3. |

Dead-weight subtotal (v3-eligible): **about 1,500 src LOC**, plus tests and
fixtures.

## 5. Feature-level candidates (remove or extract to an optional package)

These rows are proposals. Each conflicts with the current policy in some way
(ADR 0004 "What v3 does not delete" and its package-split scope boundary;
ADR 0007 rules 4 and 5 as proposed), so they are listed as **open decisions** in
the [release plan](2026-10-26-v3-release-plan.md#open-decisions), not as planned
work.

| Feature | src LOC | Demand evidence | Protected core? | Recommendation |
| --- | --- | --- | --- | --- |
| **Arazzo** workflow execution | 3,053 (+3,245 tests) | weak | No | **Extract** to a separate package. Nothing in the tree consumes `src/Arazzo` (it only consumes the public validators), so it is a clean cut. ADR 0002 would need a superseding note. |
| **SDK exercise coverage** (tracker, report, `min_sdk_exercise_coverage`, sidecar half) | 399 core + about 200 threaded through 5 renderers, `CoverageThresholdEvaluator`, `CoverageMergeCommand`, `CoverageReportWriter`, the envelope | weak | No | **Remove** (or extract with Arazzo). It widens every renderer signature (`$sdkResults`) and the sidecar. Coverage JSON is a compatibility surface, so this needs a v3 format bump. ADR 0004 recorded keeping it. |
| **SDK round-trip harness** (`OpenApiResponseExplorer`, `OpenApiResponseSpecExploration`, `GeneratedResponseCase(s)`) | about 970 | weak | No, but `OpenApiFakeResponse` depends on `OpenApiResponseExplorer` | Keep the explorer for `OpenApiFakeResponse`. Optionally drop `OpenApiResponseSpecExploration` (399). |
| **Fuzz breadth**: spec-wide exploration, link chains, failure reducer (`OpenApiSpecExplorer`, `OpenApiSpecExploration`, `LinkFollower`, `LinkChainCheck`, `FailureReducer`, `SpecExplorationSummary`, `ExplorationSkip`) | 904 | weak | No | **Remove or extract.** The Laravel `ExploresOpenApiEndpoint` spec-wide entry points go with it. Note that link following during whole-spec exploration shipped recently (#634). |
| **Fuzz core** (`SchemaDataGenerator` 2,145, `OpenApiEndpointExplorer` 762, mutation and choice-point enumeration) | ≤ 6,266 (upper bound from a name-based dependency closure; it *contains* the round-trip files above, so the rows do not add up) | n/a | **Yes, indirectly**: the named contract checks (ADR 0004 row 4) are built on `OpenApiEndpointExplorer` and `ExploredCase` (`src/Contract/ContractCheckPlan.php:10-15`) | **Keep in core.** Extracting it means moving Contract too, which relocates protected-core behaviour and changes the install story; that needs a superseding ADR, not a pruning PR. |
| Stubs generator (`src/Stubs`, `Cli/StubsCommand`, Artisan `gesso:stubs`) | 1,829 + 471 + 141 | unknown | No | Extraction candidate only after download or issue evidence. `CoverageGateCommand` and `OpenApiCoverageTracker` also reference `StubGenerator`, so those reverse dependencies must be cut first. |
| Per-call strict modes (`*_per_call`) | about 500 | unknown | No | Keep; they become free when off once P2 lands. |
| Report formats: HTML (375), JUnit (387), Markdown (251) | about 1,000 | unknown | No | Keep for now; not on the hot path. |
| 3.1 validation, coverage gates, Laravel adapter, route parity | n/a | strong | partly | Keep. P1–P3 target exactly these paths. |

Feature-extraction subtotal if Arazzo, SDK exercise, and fuzz breadth go:
**about 4,550 src LOC**, plus about 5k test LOC.

## 6. Proposed PR split

Each PR keeps the ADR 0004 verification set green (the conformance delta,
dialect, discriminator default, and doctor ⇄ runtime tests).

| PR | Title | Files | Gate / proof |
| --- | --- | --- | --- |
| **A** | [#651](https://github.com/studio-design/gesso/pull/651) | `src/Spec/OpenApiRefResolver.php`, `src/Internal/OpenApiDocumentShapeNormalizer.php`, `tests/Unit/Spec/OpenApiRefResolverTest.php` (memo isolation across `resolve()` calls, re-entrancy through external documents), a spec-load benchmark under `benchmarks/` | Assert the `memory_get_usage` delta in a benchmark, not a unit test. |
| **B** | [#653](https://github.com/studio-design/gesso/pull/653) | `src/OpenApiResponseValidator.php`, `src/Validation/Strict/StrictRequiredTracker.php`, `src/Validation/Strict/StrictAdditionalPropertiesTracker.php`, `src/Validation/Strict/StrictRequiredMode.php` (docblock), `src/PHPUnit/OpenApiCoverageExtension.php`, tests under `tests/Unit/Validation/Strict/` | Regression: `off` records nothing; direct callers with an explicit tracker still record. |
| **C** | `perf(validation): pool opis validators and path matchers across validator instances` | `src/Validation/Support/SchemaValidatorRunner.php`, `src/Validation/Response/ResponseSchemaResolver.php`, `src/Spec/OpenApiSpecLoader.php` (cache-clear hook only), `src/Validation/Response/ResponseBodyValidator.php` (identity key, P3c), tests for discriminator-toggle invalidation and `stripPrefixes` changes. Validator constructors stay untouched, so no overlap with B. | Tests that toggle `DiscriminatorEnforcement` between validators; a test-isolation reset wired into the existing test bootstrap. |
| **D** | `refactor(config)!: read the extension's settings only from gesso config` | delete `src/Config/LegacyConfigurationKeys.php` and `src/Config/ConfigurationBridge.php`; `src/PHPUnit/ExtensionParameters.php`, `src/PHPUnit/OpenApiCoverageExtension.php`, `src/Config/GessoConfig.php`, compat fixtures | After B (both touch the extension). |
| **E** | `refactor(coverage)!: drop legacy sidecar envelopes and share run gates` | `src/Coverage/CoverageSidecarEnvelope.php`, `src/Coverage/CoverageMergeCommand.php`, `src/PHPUnit/CoverageReportSubscriber.php`, new `src/Internal/RunGates.php`, `docs/versioning.md` (sidecar section), the sidecar compatibility fixtures | `docs/versioning.md` must state the v3 wire floor. |
| **F** | `refactor!: remove v2 deprecated aliases and methods` | delete `src/Contract/Autoload.php`; `composer.json` (`autoload.files`), `src/Coverage/OpenApiCoverageTracker.php` (`hasAnyCoverage` only), `src/Exception/EnumBindingException.php`, `src/SkipOpenApiResolver.php`, enum-base-path surfaces in `src/Spec/OpenApiSpecLoader.php` and `src/Attribute/BoundToOpenApiEnum.php`, `tests/fixtures/compatibility/v2-public-api.json` | Conflicts with C on `OpenApiSpecLoader.php`; land after C, or split the enum-base-path part into its own PR. |
| **G** | `refactor(coverage)!: remove SDK exercise coverage` | `src/Coverage/SdkExercise*.php`, the 5 renderers, `CoverageThresholdEvaluator`, `src/Internal/CoverageReportWriter.php`, `docs/sdk-roundtrip.md`, `tests/fixtures/compatibility/v3-coverage-report.json` | **Open decision.** After E (both touch the envelope and the merge command). |
| **H** | `refactor!: extract arazzo into a separate package` | `src/Arazzo/**`, `tests/Unit/Arazzo/**`, the Arazzo fixtures, `docs/arazzo.md`, an ADR 0002 note, public-API baseline entries | **Open decision.** Fully disjoint. |
| **I** | `refactor(fuzz)!: remove spec-wide exploration and link chains` | the 7 fuzz-breadth files, `src/Laravel/ExploresOpenApiEndpoint.php` (spec-wide methods), `docs/fuzzing.md` | **Open decision.** Contract checks and their tests stay untouched. |

Order: A, B, C first (performance, non-breaking, can ship in 2.x minors). Then
D, E, F as the v3 breaking set, and G, H, I only if their open decisions are
resolved in favour. The conflict pairs are B/D (extension), C/F (loader), and
E/G (envelope); the ordering serialises each pair. D, E, and F overlap with the
breaking-PR list in the [release plan](2026-10-26-v3-release-plan.md#2-v3-breaking-changes);
that list is the consolidated one.

## 7. Open points

- P1 memo key: dialect-sensitive targets (`$schema` inside a component) are
  covered by `schemaDeclaration` in the key, and `preserveResourceDialect` stays
  outside the memo. PR A should add a fixture with one target referenced under
  two different document dialects (a 3.1 root plus a `$schema`-declaring
  external file).
- P3 shared state across tests: Laravel's per-validator
  `applyDiscriminatorEnforcementConfig()` makes the enforcement flag a cache-key
  component. Any other process-global gate that changes conversion output must
  join the key too; today `DiscriminatorEnforcement` is the only one found.
- Stubs demand data was not available. Decide extraction after a usage check.
