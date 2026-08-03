# SDK exercise coverage design

## Context

The response explorer and spec-wide SDK round-trip plan can feed
branch-complete, spec-derived payloads into generated SDK decoders. Their test
results expose decode and fidelity defects, but Gesso does not retain a
run-level view of which response schemas were attempted. A newly added response
schema can therefore remain outside the SDK harness without appearing in the
normal coverage outputs or an independent threshold gate.

Issue #449 adds SDK-exercise observations at `(method, path, status,
content-type)` granularity. The state must survive paratest sidecars, merge
without losing observations, reject unknown wire versions, appear in the
existing report formats, and optionally gate PHPUnit and `gesso
coverage:merge` runs.

The SDK coverage state remains separate from HTTP validation coverage. A
response can be validated by server-side contract tests without being sent to
an SDK decoder, and it can be attempted by the SDK harness even when the
decoder rejects the generated payload. Combining the two states in one tracker
would make both meanings ambiguous and would prevent the SDK state from owning
its wire-format version.

## Observation semantics

An SDK response schema is **exercised** when Gesso invokes the user-supplied
decoder callback for at least one generated case. Coverage records execution,
not success. Decode, encode, or round-trip failures continue to fail through
the existing assertion path; they do not erase the fact that the decoder was
attempted.

The two supported automatic observation points are:

- `GeneratedResponseCases::each()` records immediately before invoking its
  callback. The documented callback represents the direct explorer's SDK
  exercise boundary.
- `OpenApiResponseSpecExploration::assertRoundTrips()` records immediately
  before invoking a registered `mapResponse()` decoder.

Merely generating or iterating cases does not record SDK exercise. In
particular, `getIterator()` cannot infer that user code passes the yielded case
to an SDK. Direct consumers that want automatic coverage use the documented
`each()` path; the spec-wide plan is automatic by construction.

Named component exploration is excluded because it has no operation, status,
or content-type identity and therefore cannot satisfy the issue's coverage
granularity.

## Tracker and state

Add an internal `Coverage\SdkExerciseCoverageTracker` following the existing
run-level tracker pattern:

- a `current()` / `setCurrent()` / `resetCurrent()` lifecycle owned by the
  PHPUnit extension;
- observations keyed by spec, normalized endpoint, declared response status
  key, and declared content-type key;
- monotonic hit counts so reports can show repeated decoder attempts;
- instance `recordOn()`, `exportStateOn()`, and `importStateOn()` operations,
  with static compatibility facades only where the repository's tracker
  conventions require them;
- `STATE_FORMAT_VERSION = 1`, stamped on every export and checked before any
  import mutation;
- strict structural validation, stable key ordering on export, additive merge
  semantics, and loud rejection of unknown versions or malformed rows.

`OpenApiResponseExplorer` already receives `statusKey`, matched path, and
resolved content type from `ResponseSchemaResolution`. That canonical metadata
is carried privately by each generated operation-response case so range and
`default` responses are recorded under their declared response key rather than
the representative wire status. Component cases carry no SDK coverage
identity.

The tracker is implementation-only and marked `@internal`. No new production
dependency or public mutation API is introduced.

## Eligible response schemas and report model

An internal SDK coverage report builder loads each configured spec and derives
the denominator through the existing operation and response-schema semantics.
One eligible row corresponds to a declared `(method, path, status-key,
content-type-key)` whose media type is JSON-compatible and whose schema resolves
successfully for the response explorer.

The denominator excludes no-content responses, non-JSON media types, missing
schemas, non-JSON schemas, and OpenAPI 3.2 `itemSchema` streams. Those outcomes
cannot be fed to the current JSON SDK round-trip harness and must not depress a
gate users cannot satisfy. Malformed `paths`, Path Item, operation, `responses`,
response, content, or media-type nodes fail loudly through the same resolver and
preflight rules used by runtime exploration.

Each spec report contains:

- `responseTotal`, `responseExercised`, and `responseUnexercised`;
- stable rows with method, path, operation ID, status key, content-type key,
  exercised state, and hits.

Orphan observations that no longer match an eligible live-spec row are not
counted as exercised. They remain visible as unexpected observations in
machine-readable and detailed outputs so a spec edit during a parallel run is
diagnosable instead of silently discarded.

The SDK report stays a separate result alongside `OpenApiCoverageTracker`'s
HTTP result. Renderers compose both results at their output boundary rather
than adding SDK flags to HTTP validation response rows.

## Renderers and JSON contract

All existing report formats receive SDK coverage where their structure permits
it:

- console output adds a per-spec SDK response summary and, in detailed modes,
  exercised/unexercised schema rows;
- Markdown and HTML add an SDK response coverage summary and response-schema
  table;
- JUnit represents eligible response schemas as SDK coverage test cases so CI
  consumers can distinguish exercised and unexercised rows;
- JSON adds aggregate and per-spec `sdk_exercise` objects with totals, rows,
  and unexpected observations.

The JSON report schema version is bumped from `2` to `3` as explicitly required
by issue #449, and `docs/coverage-json-schema.md` documents every new field,
state value, and compatibility rule. Existing HTTP coverage fields retain their
v2 meanings.

Reports still render normal HTTP coverage when no SDK response schema has been
exercised. The SDK section therefore degrades visibly as a spec gains a new
eligible row.

## Parallel sidecars and merge

Current plain and baseline sidecar envelopes gain an `sdkExercise` half. The
new writer emits envelope version `6` for plain workers and version `7` for
baseline-generation workers, while the reader continues accepting the
documented legacy bare coverage payload and envelope versions 2 through 5.
Versions 6 and 7 otherwise preserve the v4/v5 halves respectively; version 7
requires the same baseline document that distinguishes v5 from v4.

The parsed envelope returns nullable SDK state for legacy inputs. New envelope
versions require an array-shaped `sdkExercise` half and reject a missing,
misplaced, or scalar value. `SdkExerciseCoverageTracker::importStateOn()` owns
the inner state-version check, so envelope and tracker formats continue to
evolve independently. Unknown envelope or tracker versions fail before report
generation or sidecar cleanup.

`gesso coverage:merge` unions every available SDK tracker half and passes the
merged report to the same renderers as sequential PHPUnit. A mixed fleet may
render the observations contributed by new workers, but a strict SDK threshold
cannot treat missing worker state as complete evidence.

## Threshold gate

The PHPUnit extension adds `min_sdk_exercise_coverage`, a percentage in the
inclusive range `0..100`. The merge CLI exposes the matching
`--min-sdk-exercise-coverage` option so parallel runs can enforce the same gate.
The existing `min_coverage_strict` switch controls warn-versus-fail behavior,
matching endpoint and response threshold configuration.

The percentage is `responseExercised / responseTotal`, aggregated across the
configured specs. A configured gate fails loudly when no eligible SDK response
schemas exist or no usable report can be computed; it never treats an empty
denominator as 100%. Partial PHPUnit runs skip the gate with the existing
coverage-threshold NOTE because a filtered suite cannot prove suite-wide SDK
exercise.

In parallel strict mode, the merge fails if any worker sidecar lacks the SDK
tracker half. Warn-only mode reports the incomplete worker-state warning and
evaluates the available observations. Unknown versions and malformed state are
fatal regardless of threshold mode.

## Error handling and compatibility

- Tracker imports validate the complete payload before mutating accumulated
  state.
- Sidecar parse or tracker import failures preserve sidecars for diagnosis and
  retry, following the existing merge behavior.
- Sequential sidecar write failures remain warnings so test results are not
  replaced by an infrastructure error.
- Existing envelope versions remain accepted according to
  `docs/versioning.md`; new writers never emit an older envelope that would
  silently discard SDK observations.
- The new PHPUnit parameter and merge CLI flag are documented compatibility
  surfaces. Renderer PHP signatures remain internal, while JSON, CLI behavior,
  and persisted wire formats follow their version policies.

## Test strategy

Implementation follows red-green-refactor. Focused tests establish:

- direct `each()` and spec-wide decoder attempts record exact, range, and
  `default` response keys before decoder success is known;
- generation, iteration without `each()`, and component exploration do not
  record operation-response coverage;
- tracker state exports deterministically, merges hit counts, rejects malformed
  input, rejects unknown versions, and remains unchanged after failed imports;
- denominator discovery includes every eligible JSON schema, excludes
  unsupported outcomes, and fails on malformed spec nodes;
- console, Markdown, HTML, JUnit, and JSON distinguish exercised and
  unexercised rows, with JSON schema version `3`;
- sequential and merged threshold gates pass, warn, fail, skip partial runs,
  and reject empty evidence as specified;
- new sidecar envelopes round-trip SDK state, documented legacy versions remain
  readable, missing required halves fail, and unknown envelope/tracker versions
  fail before cleanup;
- extension parameters and merge CLI options parse valid boundaries and reject
  invalid values consistently;
- documentation and versioning text match the emitted formats.

After focused suites pass, PHP-CS-Fixer checks, PHPStan, PHPUnit, and the full
`composer ci` gate provide repository-wide verification.
