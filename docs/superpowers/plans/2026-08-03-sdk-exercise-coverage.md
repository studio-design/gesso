# SDK Exercise Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Report and optionally gate which OpenAPI response schemas were attempted against generated SDK decoders, including correct sequential and paratest aggregation.

**Architecture:** A new internal `SdkExerciseCoverageTracker` owns versioned decoder-attempt observations independently from HTTP validation coverage. Explorer callbacks feed it canonical resolved response identities; `SdkExerciseCoverageReportBuilder` reconciles those observations against eligible JSON response schemas from the live spec, and existing renderers consume the HTTP and SDK result sets side by side. Sidecar envelopes v6/v7 carry tracker state, while PHPUnit and `coverage:merge` share percentage evaluation and fail-loud compatibility rules.

**Tech Stack:** PHP 8.3+, PHPUnit 12/13 APIs, opis/json-schema through the existing response resolver, Composer scripts, PHPStan level 6, PHP-CS-Fixer, Markdown/DOM renderers.

## Global Constraints

- Keep code compatible with PHP 8.3 and begin every PHP file with `declare(strict_types=1);`.
- Do not add a production dependency; framework, YAML, remote-reference, faker, and Pest packages remain optional.
- Keep SDK observations independent from `OpenApiCoverageTracker` state and give them `STATE_FORMAT_VERSION = 1`.
- Record a schema immediately before invoking the documented decoder callback; decode or round-trip success is not required for coverage.
- Use `(spec, normalized method, matched path, declared status key, declared content-type key)` as the canonical observation identity.
- Count only JSON-compatible response media types with a resolvable `schema`; exclude no-content, non-JSON, missing-schema, non-JSON-schema, and OpenAPI 3.2 `itemSchema` outcomes.
- Preserve OpenAPI 3.2 custom `additionalOperations` case sensitivity through `OpenApiOperationResolver::normalizeMethodForKey()`.
- Unknown envelope versions, unknown SDK state versions, and malformed state fail before mutation, output writing, or sidecar cleanup.
- Continue accepting the documented legacy bare coverage sidecar and envelope versions 2–5.
- Bump coverage JSON `schema_version` from 2 to 3 and document the complete new shape.
- Preserve unrelated untracked `.claude/` and `docs/adr/0002-arazzo-workflow-execution.md` files.

---

## File Structure

**New production files**

- `src/Validation/Response/ResponseStatusTargetEnumerator.php` — derives deterministic wire statuses for exact, range, and default response keys.
- `src/Coverage/SdkExerciseCoverageTracker.php` — owns run-level observations and versioned export/import state.
- `src/Coverage/SdkExerciseCoverageReportBuilder.php` — discovers eligible live-spec response schemas and reconciles tracker observations.

**New test files and fixture**

- `tests/Unit/Validation/Response/ResponseStatusTargetEnumeratorTest.php`
- `tests/Unit/Coverage/SdkExerciseCoverageTrackerTest.php`
- `tests/Unit/Coverage/SdkExerciseCoverageReportBuilderTest.php`
- `tests/fixtures/specs/sdk-exercise-coverage.json`

**Modified explorer files**

- `src/Fuzz/GeneratedResponseCases.php`
- `src/Fuzz/OpenApiResponseExplorer.php`
- `src/Fuzz/OpenApiResponseSpecExploration.php`
- `tests/Unit/Fuzz/GeneratedResponseCasesTest.php`
- `tests/Unit/Fuzz/OpenApiResponseExplorerTest.php`
- `tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

**Modified reporting and protocol files**

- `src/Coverage/{Console,Markdown,Html,JUnit,Json}CoverageRenderer.php`
- `src/Coverage/CoverageSidecarEnvelope.php`
- `src/Coverage/CoverageThresholdEvaluator.php`
- `src/Coverage/CoverageMergeCommand.php`
- corresponding tests under `tests/Unit/Coverage/`

**Modified PHPUnit integration files**

- `src/PHPUnit/OpenApiCoverageExtension.php`
- `src/PHPUnit/CoverageReportSubscriber.php`
- `tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php`
- `tests/Unit/PHPUnit/CoverageReportSubscriber{Threshold,WorkerMode,PartialRun}Test.php`

**Modified documentation**

- `docs/coverage.md`
- `docs/coverage-json-schema.md`
- `docs/sdk-roundtrip.md`
- `docs/versioning.md`
- `docs/setup.md`

---

### Task 1: Share deterministic declared-response status targets

**Files:**
- Create: `src/Validation/Response/ResponseStatusTargetEnumerator.php`
- Create: `tests/Unit/Validation/Response/ResponseStatusTargetEnumeratorTest.php`
- Modify: `src/Fuzz/OpenApiResponseSpecExploration.php`
- Test: `tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

**Interfaces:**
- Consumes: `array<array-key, mixed> $responses` from `ResponseOperationResolution::responses`.
- Produces: `ResponseStatusTargetEnumerator::enumerate(array $responses): list<array{declaredStatusKey: string, selector: string, wireStatus: ?int}>`.

- [ ] **Step 1: Write failing enumerator tests**

Cover exact keys, lowercase range keys, `default`, extension keys, shadowed exact statuses, and an unreachable response range:

```php
public function exact_range_and_default_targets_preserve_declared_keys(): void
{
    $targets = ResponseStatusTargetEnumerator::enumerate([
        200 => [],
        '2xx' => [],
        'default' => [],
        'x-note' => [],
    ]);

    $this->assertSame('200', $targets[0]['declaredStatusKey']);
    $this->assertSame('2xx', $targets[1]['declaredStatusKey']);
    $this->assertSame('2XX', $targets[1]['selector']);
    $this->assertSame(201, $targets[1]['wireStatus']);
    $this->assertSame('default', $targets[2]['selector']);
    $this->assertSame(100, $targets[2]['wireStatus']);
}
```

- [ ] **Step 2: Run the new test and verify the missing-class failure**

Run: `vendor/bin/phpunit tests/Unit/Validation/Response/ResponseStatusTargetEnumeratorTest.php`

Expected: FAIL because `ResponseStatusTargetEnumerator` does not exist.

- [ ] **Step 3: Implement the static enumerator**

Move the status normalization and representative-status rules out of `OpenApiResponseSpecExploration` without changing its public summaries:

```php
/**
 * @param array<array-key, mixed> $responses
 * @return list<array{declaredStatusKey: string, selector: string, wireStatus: ?int}>
 */
public static function enumerate(array $responses): array
{
    $exact = [];
    $ranges = [];

    foreach (array_keys($responses) as $rawStatus) {
        if (is_string($rawStatus) && str_starts_with($rawStatus, 'x-')) {
            continue;
        }

        $selector = self::normalizeSelector($rawStatus);
        if (preg_match('/^[1-5][0-9]{2}$/', $selector) === 1) {
            $exact[(int) $selector] = true;
        } elseif (preg_match('/^[1-5]XX$/', $selector) === 1) {
            $ranges[(int) $selector[0]] = true;
        }
    }

    $targets = [];
    foreach (array_keys($responses) as $rawStatus) {
        if (is_string($rawStatus) && str_starts_with($rawStatus, 'x-')) {
            continue;
        }

        $declaredStatusKey = (string) $rawStatus;
        $selector = self::normalizeSelector($rawStatus);
        $wireStatus = match (true) {
            preg_match('/^[1-5][0-9]{2}$/', $selector) === 1 => (int) $selector,
            preg_match('/^([1-5])XX$/', $selector, $matches) === 1 => self::representativeRangeStatus((int) $matches[1], $exact),
            default => self::representativeDefaultStatus($exact, $ranges),
        };
        $targets[] = compact('declaredStatusKey', 'selector', 'wireStatus');
    }

    return $targets;
}
```

Keep invalid response keys loud with the existing message:

```php
throw new InvalidArgumentException(sprintf(
    "Invalid response key '%s': expected an exact HTTP status, range status, or default.",
    $declaredStatusKey,
));
```

- [ ] **Step 4: Replace the spec-wide plan's private target helpers**

Use `selector` for `mapResponse()` lookup and existing summary/status output, `wireStatus` for resolver calls, and leave `declaredStatusKey` available to later coverage code. Remove `statusTargets()`, `normalizeDeclaredStatus()`, `representativeRangeStatus()`, and `representativeDefaultStatus()` from the plan.

- [ ] **Step 5: Run focused response-plan tests**

Run: `vendor/bin/phpunit tests/Unit/Validation/Response/ResponseStatusTargetEnumeratorTest.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

Expected: PASS with existing exact/range/default behavior unchanged.

- [ ] **Step 6: Commit the reusable status-target boundary**

```bash
git add src/Validation/Response/ResponseStatusTargetEnumerator.php src/Fuzz/OpenApiResponseSpecExploration.php tests/Unit/Validation/Response/ResponseStatusTargetEnumeratorTest.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php
git commit -m "refactor(coverage): share response status targets"
```

### Task 2: Add the independent versioned SDK exercise tracker

**Files:**
- Create: `src/Coverage/SdkExerciseCoverageTracker.php`
- Create: `tests/Unit/Coverage/SdkExerciseCoverageTrackerTest.php`

**Interfaces:**
- Consumes: canonical spec, method, matched path, declared status key, and content-type key strings.
- Produces:
  - `recordOn(string $specName, string $method, string $path, string $statusKey, string $contentTypeKey): void`
  - `exportStateOn(): array{version: int, observations: array<string, array<string, array<string, array<string, int>>>>}`
  - `importStateOn(array<string, mixed> $state): void`
  - `observationsForSpecOn(string $specName): array<string, array<string, array<string, int>>>`
  - locator methods `current()`, `setCurrent()`, and `resetCurrent()`.

- [ ] **Step 1: Write failing tracker behavior and state tests**

Pin normalization, hit accumulation, instance isolation, deterministic export ordering, merge semantics, unknown versions, invalid hit counts, malformed nesting, and atomic failed imports:

```php
public function import_rejects_unknown_version_without_mutating_existing_state(): void
{
    $tracker = new SdkExerciseCoverageTracker();
    $tracker->recordOn('front', 'GET', '/pets', '200', 'application/json');

    try {
        $tracker->importStateOn(['version' => 99, 'observations' => []]);
        $this->fail('Expected invalid state version.');
    } catch (InvalidArgumentException $e) {
        $this->assertStringContainsString('unsupported SDK exercise state version', $e->getMessage());
    }

    $this->assertSame(1, $tracker->observationsForSpecOn('front')['GET /pets']['200']['application/json']);
}
```

- [ ] **Step 2: Run the tracker tests and verify the missing-class failure**

Run: `vendor/bin/phpunit tests/Unit/Coverage/SdkExerciseCoverageTrackerTest.php`

Expected: FAIL because `SdkExerciseCoverageTracker` does not exist.

- [ ] **Step 3: Implement tracker recording and locator lifecycle**

Use this state shape and normalize methods only through the operation resolver:

```php
/** @var array<string, array<string, array<string, array<string, int>>>> */
private array $observations = [];

public function recordOn(
    string $specName,
    string $method,
    string $path,
    string $statusKey,
    string $contentTypeKey,
): void {
    $endpoint = OpenApiOperationResolver::normalizeMethodForKey($method) . ' ' . $path;
    $this->observations[$specName][$endpoint][$statusKey][$contentTypeKey] =
        ($this->observations[$specName][$endpoint][$statusKey][$contentTypeKey] ?? 0) + 1;
}
```

`setCurrent()` must emit `[OpenAPI SDK Exercise]` `E_USER_WARNING` before replacing a non-empty installed tracker.

- [ ] **Step 4: Implement deterministic, atomic state export/import**

Set `STATE_FORMAT_VERSION = 1`, deep-sort a copy for export, validate the complete payload into a local normalized array, require positive integer hits, then merge hits only after validation succeeds.

- [ ] **Step 5: Run tracker tests and static analysis for the new file**

Run: `vendor/bin/phpunit tests/Unit/Coverage/SdkExerciseCoverageTrackerTest.php`

Run: `vendor/bin/phpstan analyse src/Coverage/SdkExerciseCoverageTracker.php tests/Unit/Coverage/SdkExerciseCoverageTrackerTest.php --no-progress`

Expected: both PASS.

- [ ] **Step 6: Commit the tracker**

```bash
git add src/Coverage/SdkExerciseCoverageTracker.php tests/Unit/Coverage/SdkExerciseCoverageTrackerTest.php
git commit -m "feat(coverage): track SDK decoder exercise"
```

### Task 3: Record direct and spec-wide decoder attempts

**Files:**
- Modify: `src/Fuzz/GeneratedResponseCases.php`
- Modify: `src/Fuzz/OpenApiResponseExplorer.php`
- Modify: `src/Fuzz/OpenApiResponseSpecExploration.php`
- Modify: `tests/Unit/Fuzz/GeneratedResponseCasesTest.php`
- Modify: `tests/Unit/Fuzz/OpenApiResponseExplorerTest.php`
- Modify: `tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

**Interfaces:**
- Consumes: `SdkExerciseCoverageTracker::current()->recordOn(string $specName, string $method, string $path, string $statusKey, string $contentTypeKey)` from Task 2.
- Produces: an optional private `Closure` hook on `GeneratedResponseCases` that `each()` invokes before the user callback.

- [ ] **Step 1: Write failing observation-boundary tests**

Establish that generation and plain iteration record nothing, direct `each()` records before a throwing callback, all generated cases increment hits, component exploration records nothing, and a spec-wide decode failure still records its schema:

```php
public function each_records_before_a_decoder_failure(): void
{
    $tracker = new SdkExerciseCoverageTracker();
    SdkExerciseCoverageTracker::setCurrent($tracker);

    $cases = OpenApiResponseExplorer::explore('sdk-roundtrip', 'POST', '/oauth/introspect', 200);

    try {
        $cases->each(static fn(GeneratedResponseCase $case): never => throw new RuntimeException('decoder failed'));
    } catch (RuntimeException) {
    }

    $this->assertNotSame([], $tracker->observationsForSpecOn('sdk-roundtrip'));
}
```

- [ ] **Step 2: Run the three focused suites and confirm zero observations fail**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/GeneratedResponseCasesTest.php tests/Unit/Fuzz/OpenApiResponseExplorerTest.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

Expected: FAIL because no SDK tracker hook is installed.

- [ ] **Step 3: Add the optional pre-callback hook to `GeneratedResponseCases`**

Preserve the existing one-argument constructor call with an optional private promoted property:

```php
public function __construct(
    public array $cases,
    private ?Closure $beforeEach = null,
) {
    if ($cases === []) {
        throw new InvalidArgumentException(
            'GeneratedResponseCases must contain at least one GeneratedResponseCase; an empty SDK exercise would assert nothing.',
        );
    }
}

public function each(callable $callback): self
{
    foreach ($this->cases as $case) {
        if ($this->beforeEach !== null) {
            ($this->beforeEach)();
        }
        $callback($case);
    }

    return $this;
}
```

- [ ] **Step 4: Install canonical operation-response hooks in the explorer**

After a resolved response, pass a closure capturing `$specName`, normalized method, matched path, `$resolution->statusKey`, and `$resolution->contentType` into `buildCases()`. Component exploration passes no hook. Assert non-null resolved metadata before creating the closure.

- [ ] **Step 5: Route the spec-wide plan through `GeneratedResponseCases::each()`**

Replace the plan's raw `foreach ($cases as $case)` with `each()` while retaining per-case decode/round-trip exception aggregation and counters. The hook then fires once per attempted generated decoder case on both public paths.

- [ ] **Step 6: Run focused tests and the public API baseline**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/GeneratedResponseCasesTest.php tests/Unit/Fuzz/OpenApiResponseExplorerTest.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php tests/Unit/Compatibility/PublicApiBaselineTest.php`

Expected: PASS; the optional private constructor parameter remains backward compatible.

- [ ] **Step 7: Commit observation integration**

```bash
git add src/Fuzz/GeneratedResponseCases.php src/Fuzz/OpenApiResponseExplorer.php src/Fuzz/OpenApiResponseSpecExploration.php tests/Unit/Fuzz/GeneratedResponseCasesTest.php tests/Unit/Fuzz/OpenApiResponseExplorerTest.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php
git commit -m "feat(fuzz): record SDK response exercise"
```

### Task 4: Reconcile observations with eligible live-spec response schemas

**Files:**
- Create: `src/Coverage/SdkExerciseCoverageReportBuilder.php`
- Create: `tests/Unit/Coverage/SdkExerciseCoverageReportBuilderTest.php`
- Create: `tests/fixtures/specs/sdk-exercise-coverage.json`
- Modify: `tests/bootstrap.php` only if the fixture registry requires an explicit entry.

**Interfaces:**
- Consumes: `ResponseStatusTargetEnumerator::enumerate()`, `ResponseSchemaResolver`, and `SdkExerciseCoverageTracker::observationsForSpecOn()`.
- Produces `SdkExerciseCoverageReportBuilder::build(string $specName, SdkExerciseCoverageTracker $tracker): SdkExerciseCoverageResult`, where:

```php
/**
 * @phpstan-type SdkExerciseResponseRow array{
 *   endpoint: string, method: string, path: string, operationId: ?string,
 *   statusKey: string, contentTypeKey: string, exercised: bool, hits: int
 * }
 * @phpstan-type SdkExerciseUnexpected array{
 *   endpoint: string, statusKey: string, contentTypeKey: string, hits: int
 * }
 * @phpstan-type SdkExerciseCoverageResult array{
 *   responses: list<SdkExerciseResponseRow>, responseTotal: int,
 *   responseExercised: int, responseUnexercised: int,
 *   unexpectedObservations: list<SdkExerciseUnexpected>
 * }
 */
```

- [ ] **Step 1: Add a focused OpenAPI fixture**

Include exact `200`, range `2XX`, and `default` JSON schemas, multiple JSON-compatible media types, a `text/plain` media type, a 204 response without content, a missing-schema media type, and an OpenAPI 3.2 `itemSchema` response. Give operations stable `operationId` values.

- [ ] **Step 2: Write failing report-builder tests**

Assert eligible totals and order, observation/hit reconciliation, lowercase/custom content types, custom additional-operation method identity, excluded unsupported outcomes, unexpected orphan observations, and malformed response/content/media-type failures.

```php
public function build_lists_exercised_and_unexercised_json_response_schemas(): void
{
    $tracker = new SdkExerciseCoverageTracker();
    $tracker->recordOn('sdk-exercise-coverage', 'GET', '/pets', '200', 'application/json');

    $report = SdkExerciseCoverageReportBuilder::build('sdk-exercise-coverage', $tracker);

    $this->assertGreaterThan(1, $report['responseTotal']);
    $this->assertSame(1, $report['responseExercised']);
    $this->assertSame($report['responseTotal'] - 1, $report['responseUnexercised']);
}
```

- [ ] **Step 3: Run the report-builder suite and verify the missing-class failure**

Run: `vendor/bin/phpunit tests/Unit/Coverage/SdkExerciseCoverageReportBuilderTest.php`

Expected: FAIL because the builder does not exist.

- [ ] **Step 4: Implement discovery through the shared resolver**

Load the named spec, enumerate operations via `OpenApiOperationResolver::declaredOperations()`, resolve each operation with `ResponseSchemaResolver::resolveOperation()`, enumerate status targets from Task 1, and resolve each JSON-compatible content key. Only `ResponseSchemaResolutionOutcome::Resolved` becomes a denominator row. Throw the resolver message for `MalformedSpec`, `MalformedResponse`, or `MalformedContent`; skip deliberate unsupported outcomes.

```php
foreach (ResponseStatusTargetEnumerator::enumerate($operationResolution->responses) as $target) {
    if ($target['wireStatus'] === null) {
        continue;
    }

    $responseSpec = $operationResolution->responses[$target['declaredStatusKey']];
    foreach (self::jsonContentTypes($responseSpec) as $contentType) {
        $resolution = $resolver->resolveResponseSchema(
            $operationResolution,
            $target['wireStatus'],
            $contentType === 'application/*' ? null : $contentType,
        );
        self::throwIfMalformed($resolution);
        if ($resolution->outcome !== ResponseSchemaResolutionOutcome::Resolved) {
            continue;
        }

        $rows[] = self::responseRow($operation, $resolution, $observations);
    }
}
```

- [ ] **Step 5: Implement stable reconciliation and unexpected observations**

Match exact canonical endpoint/status/content keys, sum hits, sort rows by endpoint/status/content, and emit any tracker entries not consumed by a live row as `unexpectedObservations`. Derive totals from the final row list.

- [ ] **Step 6: Run builder, resolver, and spec-wide plan tests**

Run: `vendor/bin/phpunit tests/Unit/Coverage/SdkExerciseCoverageReportBuilderTest.php tests/Unit/Validation/Response/ResponseSchemaResolverTest.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

Expected: PASS.

- [ ] **Step 7: Commit live-spec reporting**

```bash
git add src/Coverage/SdkExerciseCoverageReportBuilder.php tests/Unit/Coverage/SdkExerciseCoverageReportBuilderTest.php tests/fixtures/specs/sdk-exercise-coverage.json
git commit -m "feat(coverage): compute SDK response coverage"
```

### Task 5: Render SDK exercised and unexercised schemas in every report format

**Files:**
- Modify: `src/Coverage/ConsoleCoverageRenderer.php`
- Modify: `src/Coverage/MarkdownCoverageRenderer.php`
- Modify: `src/Coverage/HtmlCoverageRenderer.php`
- Modify: `src/Coverage/JUnitCoverageRenderer.php`
- Modify: `src/Coverage/JsonCoverageRenderer.php`
- Modify: `tests/Unit/Coverage/ConsoleCoverageRendererTest.php`
- Modify: `tests/Unit/Coverage/MarkdownCoverageRendererTest.php`
- Modify: `tests/Unit/Coverage/MarkdownCoverageRendererLintTest.php`
- Modify: `tests/Unit/Coverage/HtmlCoverageRendererTest.php`
- Modify: `tests/Unit/Coverage/JUnitCoverageRendererTest.php`
- Modify: `tests/Unit/Coverage/JsonCoverageRendererTest.php`

**Interfaces:**
- Consumes: `array<string, SdkExerciseCoverageResult> $sdkResults` keyed by spec.
- Produces optional SDK-result parameters:
  - `ConsoleCoverageRenderer::render(array $results, ConsoleOutput $mode = ConsoleOutput::DEFAULT, array $sdkResults = []): string`
  - `MarkdownCoverageRenderer::render(array $results, array $sdkResults = []): string`
  - `HtmlCoverageRenderer::render(array $results, array $sdkResults = []): string`
  - `JUnitCoverageRenderer::render(array $results, array $sdkResults = []): string`
  - `JsonCoverageRenderer::render(array $results, ?DateTimeImmutable $generatedAt = null, array $sdkResults = []): string`.

- [ ] **Step 1: Write failing format-specific tests**

Use one exercised and one unexercised SDK row plus an unexpected observation. Assert:

- console summary `SDK responses: 1/2 exercised (50%)` and detailed row markers;
- Markdown/HTML summary and response-schema table;
- JUnit SDK cases with an unexercised `<failure>` and `classname="front.sdk-exercise"`;
- JSON schema version 3 and the exact nested shape below.

```json
{
  "aggregate": {
    "sdk_exercise": {
      "response_total": 2,
      "response_exercised": 1,
      "response_unexercised": 1
    }
  },
  "specs": {
    "front": {
      "sdk_exercise": {
        "response_total": 2,
        "response_exercised": 1,
        "response_unexercised": 1,
        "responses": [],
        "unexpected_observations": []
      }
    }
  }
}
```

- [ ] **Step 2: Run all renderer suites and verify the new assertions fail**

Run: `vendor/bin/phpunit tests/Unit/Coverage/ConsoleCoverageRendererTest.php tests/Unit/Coverage/MarkdownCoverageRendererTest.php tests/Unit/Coverage/HtmlCoverageRendererTest.php tests/Unit/Coverage/JUnitCoverageRendererTest.php tests/Unit/Coverage/JsonCoverageRendererTest.php`

Expected: FAIL because renderers ignore SDK results and JSON remains version 2.

- [ ] **Step 3: Implement console and Markdown SDK sections**

Render the SDK summary for each spec in the union of HTTP and SDK result keys. In `ALL` and `UNCOVERED_ONLY`, list SDK response rows; `UNCOVERED_ONLY` omits exercised rows. Keep `ACTIVE_ONLY` from hiding a spec with SDK observations. Escape Markdown cells with the existing helpers.

- [ ] **Step 4: Implement HTML and JUnit SDK sections**

Reuse existing escaping and anchor allocation. JUnit adds SDK response cases to each spec suite, includes hits/operation ID in `system-out`, and counts unexercised rows as failures without changing HTTP case semantics.

- [ ] **Step 5: Implement JSON schema version 3 serialization**

Set `SCHEMA_VERSION = 3`, add aggregate `sdk_exercise`, add each spec's detailed `sdk_exercise` object, serialize `exercised` as boolean and `hits` as integer, and preserve all existing v2 HTTP fields unchanged.

- [ ] **Step 6: Run renderer and generated Markdown lint tests**

Run: `env NPM_CONFIG_CACHE=/tmp/gesso-npm-cache vendor/bin/phpunit tests/Unit/Coverage/ConsoleCoverageRendererTest.php tests/Unit/Coverage/MarkdownCoverageRendererTest.php tests/Unit/Coverage/MarkdownCoverageRendererLintTest.php tests/Unit/Coverage/HtmlCoverageRendererTest.php tests/Unit/Coverage/JUnitCoverageRendererTest.php tests/Unit/Coverage/JsonCoverageRendererTest.php`

Expected: PASS.

- [ ] **Step 7: Commit renderer support**

```bash
git add src/Coverage/ConsoleCoverageRenderer.php src/Coverage/MarkdownCoverageRenderer.php src/Coverage/HtmlCoverageRenderer.php src/Coverage/JUnitCoverageRenderer.php src/Coverage/JsonCoverageRenderer.php tests/Unit/Coverage/ConsoleCoverageRendererTest.php tests/Unit/Coverage/MarkdownCoverageRendererTest.php tests/Unit/Coverage/MarkdownCoverageRendererLintTest.php tests/Unit/Coverage/HtmlCoverageRendererTest.php tests/Unit/Coverage/JUnitCoverageRendererTest.php tests/Unit/Coverage/JsonCoverageRendererTest.php
git commit -m "feat(coverage): render SDK exercise coverage"
```

### Task 6: Version SDK state into worker sidecars

**Files:**
- Modify: `src/Coverage/CoverageSidecarEnvelope.php`
- Modify: `src/PHPUnit/OpenApiCoverageExtension.php`
- Modify: `src/PHPUnit/CoverageReportSubscriber.php`
- Modify: `tests/Unit/Coverage/CoverageSidecarEnvelopeTest.php`
- Modify: `tests/Unit/Coverage/CoverageSidecarTest.php`
- Modify: `tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php`
- Modify: `tests/Unit/PHPUnit/CoverageReportSubscriberWorkerModeTest.php`
- Modify: `tests/Unit/PHPUnit/CoverageReportSubscriberBaselineTest.php`

**Interfaces:**
- Consumes: `SdkExerciseCoverageTracker::exportStateOn()`.
- Produces envelope constants `ENVELOPE_VERSION_WITH_SDK_EXERCISE = 6` and `ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE = 7`; parsed envelopes gain `sdkExercise: array<string, mixed>|null`.

- [ ] **Step 1: Write failing v6/v7 envelope tests**

Assert build/parse round trips for plain and baseline envelopes, v6/v7 require `sdkExercise`, v6 forbids baseline, v7 requires baseline, versions 2–5 parse with `sdkExercise => null`, legacy bare state rejects a stray `sdkExercise`, and unknown version 8 fails.

```php
$envelope = CoverageSidecarEnvelope::build(
    coverageState: $coverage,
    strictRequiredState: $strictRequired,
    strictAdditionalPropertiesState: $strictAdditional,
    sdkExerciseState: ['version' => 1, 'observations' => []],
);
$this->assertSame(6, $envelope['envelopeVersion']);
```

- [ ] **Step 2: Run sidecar suites and verify v6 expectations fail**

Run: `vendor/bin/phpunit tests/Unit/Coverage/CoverageSidecarEnvelopeTest.php tests/Unit/Coverage/CoverageSidecarTest.php tests/Unit/PHPUnit/CoverageReportSubscriberWorkerModeTest.php`

Expected: FAIL because envelopes have no SDK half.

- [ ] **Step 3: Implement envelope v6/v7 build and parse rules**

Add optional `?array $sdkExerciseState = null` to `build()`. Existing tests/callers that omit it continue producing v4/v5 fixtures; production worker wiring passes it and emits v6/v7. Validate required and forbidden keys by version before returning parsed halves.

- [ ] **Step 4: Install and inject a fresh SDK tracker in the PHPUnit extension**

Reset/set `SdkExerciseCoverageTracker` beside the three existing trackers, inject the exact instance into `CoverageReportSubscriber`, and add a readonly subscriber property resolved eagerly from the locator when omitted in tests.

- [ ] **Step 5: Export SDK state from every worker**

Pass `sdkExerciseState: $this->sdkExerciseCoverageTracker->exportStateOn()` to `CoverageSidecarEnvelope::build()`, independent of threshold configuration, so future merge invocations always have complete worker evidence.

- [ ] **Step 6: Run sidecar and extension suites**

Run: `vendor/bin/phpunit tests/Unit/Coverage/CoverageSidecarEnvelopeTest.php tests/Unit/Coverage/CoverageSidecarTest.php tests/Unit/PHPUnit/CoverageReportSubscriberWorkerModeTest.php tests/Unit/PHPUnit/CoverageReportSubscriberBaselineTest.php tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php`

Expected: PASS, including legacy envelope inputs.

- [ ] **Step 7: Commit the protocol update**

```bash
git add src/Coverage/CoverageSidecarEnvelope.php src/PHPUnit/OpenApiCoverageExtension.php src/PHPUnit/CoverageReportSubscriber.php tests/Unit/Coverage/CoverageSidecarEnvelopeTest.php tests/Unit/Coverage/CoverageSidecarTest.php tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php tests/Unit/PHPUnit/CoverageReportSubscriberWorkerModeTest.php tests/Unit/PHPUnit/CoverageReportSubscriberBaselineTest.php
git commit -m "feat(coverage): carry SDK exercise sidecars"
```

### Task 7: Integrate sequential reports and the SDK threshold gate

**Files:**
- Modify: `src/Coverage/CoverageThresholdEvaluator.php`
- Modify: `src/PHPUnit/OpenApiCoverageExtension.php`
- Modify: `src/PHPUnit/CoverageReportSubscriber.php`
- Modify: `tests/Unit/Coverage/CoverageThresholdEvaluatorTest.php`
- Modify: `tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php`
- Modify: `tests/Unit/PHPUnit/CoverageReportSubscriberThresholdTest.php`
- Modify: `tests/Unit/PHPUnit/CoverageReportSubscriberPartialRunTest.php`
- Modify: `tests/Unit/PHPUnit/CoverageReportSubscriberWorkerModeTest.php`

**Interfaces:**
- Consumes: per-spec SDK results from Task 4.
- Produces PHPUnit parameter `min_sdk_exercise_coverage` and evaluator signature:

```php
public static function evaluate(
    array $results,
    array $sdkResults,
    ?float $minEndpointCoverage,
    ?float $minResponseCoverage,
    ?float $minSdkExerciseCoverage,
    bool $strict,
): array;
```

- [ ] **Step 1: Write failing evaluator tests**

Cover SDK-only pass/miss, combined HTTP+SDK messages, 0 and 100 boundaries, and an empty SDK denominator reported as an unevaluable miss when configured.

- [ ] **Step 2: Write failing subscriber and extension tests**

Assert parameter parsing severity mirrors existing thresholds, SDK-only activity renders reports even without HTTP observations, zero attempts list unexercised schemas, strict misses call the exit seam, warn misses do not, partial runs emit one NOTE, and persistent outputs receive both result sets.

- [ ] **Step 3: Run focused threshold suites and verify failures**

Run: `vendor/bin/phpunit tests/Unit/Coverage/CoverageThresholdEvaluatorTest.php tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php tests/Unit/PHPUnit/CoverageReportSubscriberThresholdTest.php tests/Unit/PHPUnit/CoverageReportSubscriberPartialRunTest.php`

Expected: FAIL because the SDK parameter and gate do not exist.

- [ ] **Step 4: Extend threshold aggregation and messages**

Roll up `responseExercised` / `responseTotal`, label the line `SDK exercise`, use the existing `FAIL`/`WARN` message framing, and return a failed line for total zero rather than computing 100%.

- [ ] **Step 5: Compute SDK results once in the subscriber**

For every configured loadable spec call `SdkExerciseCoverageReportBuilder::build()`. Keep HTTP and SDK arrays separate, render when either result set is non-empty, pass both arrays to console/persistent/GitHub Summary renderers, and keep mid-run missing-spec warnings aligned with current HTTP behavior.

- [ ] **Step 6: Wire `min_sdk_exercise_coverage` through bootstrap and subscriber**

Resolve it via `resolveThresholdParameter()`, add `?float $minSdkExerciseCoverage` to the subscriber constructor, include it in empty-result and partial-run decisions, and evaluate it through the shared threshold evaluator after report writing.

- [ ] **Step 7: Run sequential reporting and gate suites**

Run: `env NPM_CONFIG_CACHE=/tmp/gesso-npm-cache vendor/bin/phpunit tests/Unit/Coverage/CoverageThresholdEvaluatorTest.php tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php tests/Unit/PHPUnit/CoverageReportSubscriberThresholdTest.php tests/Unit/PHPUnit/CoverageReportSubscriberPartialRunTest.php tests/Unit/PHPUnit/CoverageReportSubscriberWorkerModeTest.php`

Expected: PASS.

- [ ] **Step 8: Commit sequential reporting and gate support**

```bash
git add src/Coverage/CoverageThresholdEvaluator.php src/PHPUnit/OpenApiCoverageExtension.php src/PHPUnit/CoverageReportSubscriber.php tests/Unit/Coverage/CoverageThresholdEvaluatorTest.php tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php tests/Unit/PHPUnit/CoverageReportSubscriberThresholdTest.php tests/Unit/PHPUnit/CoverageReportSubscriberPartialRunTest.php tests/Unit/PHPUnit/CoverageReportSubscriberWorkerModeTest.php
git commit -m "feat(phpunit): gate SDK exercise coverage"
```

### Task 8: Merge SDK observations and enforce complete parallel evidence

**Files:**
- Modify: `src/Coverage/CoverageMergeCommand.php`
- Modify: `tests/Unit/Coverage/CoverageMergeCommandTest.php`
- Modify: `bin/gesso` only if command-option dispatch is duplicated there.

**Interfaces:**
- Consumes: parsed nullable `sdkExercise` state and `SdkExerciseCoverageReportBuilder`.
- Produces merge option `--min-sdk-exercise-coverage=<pct>` / `min_sdk_exercise_coverage?: float|string`.

- [ ] **Step 1: Write failing CLI parse and usage tests**

Assert numeric and non-numeric values survive `parseArgv()`, usage names the new flag, invalid values warn or return exit 2 according to `--min-coverage-strict`, and 0/100 boundaries are accepted.

- [ ] **Step 2: Write failing merge behavior tests**

Create two v6 sidecars with disjoint observations and assert unioned reports/hits. Cover v7 baseline compatibility, legacy mixed fleets, unknown SDK state versions, strict gate failure when any sidecar lacks SDK state, warn-only incomplete-state diagnostics, strict SDK miss/pass, no-sidecar failure, no eligible denominator, JSON v3 output, and preservation of sidecars after fatal protocol errors.

```php
$exit = $command->run([
    'sidecar_dir' => $this->sidecarDir,
    'spec_base_path' => $this->specBasePath,
    'specs' => ['sdk-exercise-coverage'],
    'min_sdk_exercise_coverage' => 100.0,
    'min_coverage_strict' => true,
]);
$this->assertSame(1, $exit);
```

- [ ] **Step 3: Run merge tests and verify failures**

Run: `vendor/bin/phpunit tests/Unit/Coverage/CoverageMergeCommandTest.php`

Expected: FAIL because the merge drops SDK halves and does not parse the new flag.

- [ ] **Step 4: Parse and import every SDK half atomically**

Install a fresh `SdkExerciseCoverageTracker`, import non-null halves, count `$sidecarsWithoutSdkExercise`, and treat parse/import exceptions as fatal before cleanup. Compute per-spec SDK reports after all imports.

- [ ] **Step 5: Pass SDK results to every merge output**

Update console, output dispatch closures, and GitHub Step Summary to receive both result arrays. Preserve the subscriber/CLI severity asymmetry for rendering and writes.

- [ ] **Step 6: Enforce parallel SDK threshold completeness**

Resolve `min_sdk_exercise_coverage`, include it in no-sidecar and empty-evidence guards, evaluate merged totals through `CoverageThresholdEvaluator`, and when strict SDK gating is configured return non-zero if `$sidecarsWithoutSdkExercise > 0`. Warn-only mode emits the incomplete worker count and evaluates available observations.

- [ ] **Step 7: Run merge, sidecar, and renderer tests**

Run: `vendor/bin/phpunit tests/Unit/Coverage/CoverageMergeCommandTest.php tests/Unit/Coverage/CoverageSidecarEnvelopeTest.php tests/Unit/Coverage/JsonCoverageRendererTest.php`

Expected: PASS and fatal cases retain their sidecar files.

- [ ] **Step 8: Commit parallel merge support**

```bash
git add src/Coverage/CoverageMergeCommand.php tests/Unit/Coverage/CoverageMergeCommandTest.php bin/gesso
git commit -m "feat(coverage): merge SDK exercise coverage"
```

Omit `bin/gesso` from `git add` when inspection confirms it delegates options without a duplicated allow-list.

### Task 9: Document compatibility surfaces and verify the complete issue

**Files:**
- Modify: `docs/coverage.md`
- Modify: `docs/coverage-json-schema.md`
- Modify: `docs/sdk-roundtrip.md`
- Modify: `docs/versioning.md`
- Modify: `docs/setup.md`
- Modify: `README.md` only if its existing coverage parameter table is authoritative.

**Interfaces:**
- Consumes: emitted JSON v3, sidecar v6/v7, PHPUnit parameter, and merge flag from prior tasks.
- Produces user-facing configuration and wire-format contracts matching runtime behavior.

- [ ] **Step 1: Update SDK round-trip and coverage guides**

Document that `GeneratedResponseCases::each()` and spec-wide decoder callbacks record attempts, manual iteration alone does not, component exploration is excluded, eligible response rules define the denominator, and failures remain assertion failures independently of coverage.

- [ ] **Step 2: Document PHPUnit and merge gate configuration**

Add examples using:

```xml
<parameter name="min_sdk_exercise_coverage" value="100" />
<parameter name="min_coverage_strict" value="true" />
```

```bash
vendor/bin/gesso coverage:merge \
  --spec-base-path=openapi \
  --min-sdk-exercise-coverage=100 \
  --min-coverage-strict
```

- [ ] **Step 3: Rewrite the JSON schema reference for version 3**

Document aggregate `sdk_exercise`, per-spec totals, response row fields (`endpoint`, `method`, `path`, `operation_id`, `status_key`, `content_type_key`, `exercised`, `hits`), unexpected rows, version bump rationale, and preserved v2 HTTP meanings.

- [ ] **Step 4: Update sidecar versioning policy**

State that current writers emit v6 plain / v7 baseline envelopes with required SDK state version 1; readers accept legacy bare v1 coverage and envelopes 2–7; v2–v5 contribute no SDK state; unknown envelope and inner tracker versions are rejected; strict parallel SDK gates reject missing halves.

- [ ] **Step 5: Run documentation and focused feature tests**

Run: `env NPM_CONFIG_CACHE=/tmp/gesso-npm-cache vendor/bin/phpunit tests/Unit/Coverage tests/Unit/Fuzz/OpenApiResponseExplorerTest.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php tests/Unit/PHPUnit/OpenApiCoverageExtensionTest.php tests/Unit/PHPUnit/CoverageReportSubscriberThresholdTest.php tests/Unit/PHPUnit/CoverageReportSubscriberWorkerModeTest.php`

Expected: PASS.

- [ ] **Step 6: Apply formatting and run static checks**

Run: `composer cs`

Run: `composer cs-check`

Run: `composer stan`

Expected: all PASS; inspect `git diff` after the formatting rewrite and retain only issue #449 files.

- [ ] **Step 7: Run the full test and CI gates**

Run: `env NPM_CONFIG_CACHE=/tmp/gesso-npm-cache composer test`

Run: `env NPM_CONFIG_CACHE=/tmp/gesso-npm-cache composer ci`

Expected: 0 failures. If an optional matrix combination is unavailable locally, record the exact unverified combination for CI instead of weakening tests.

- [ ] **Step 8: Audit every issue #449 requirement against evidence**

Confirm tracker granularity and state version from tracker tests; renderer behavior and JSON v3 from renderer tests; v6/v7 and unknown-version rejection from envelope tests; worker union and CLI gate from merge tests; PHPUnit parameter and partial-run behavior from extension/subscriber tests; and user-facing contracts from the modified docs.

- [ ] **Step 9: Commit documentation and final verification adjustments**

```bash
git add docs/coverage.md docs/coverage-json-schema.md docs/sdk-roundtrip.md docs/versioning.md docs/setup.md README.md
git commit -m "docs(coverage): explain SDK exercise reporting"
```

Omit unchanged documentation paths from `git add`.
