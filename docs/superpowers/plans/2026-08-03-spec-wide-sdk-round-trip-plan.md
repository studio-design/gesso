# Spec-wide SDK Round-trip Plan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a deterministic whole-spec SDK round-trip plan that executes every explicitly mapped JSON response schema and reports mapping gaps loudly.

**Architecture:** Extend `OpenApiResponseExplorer` with a factory for a response-specific fluent plan. The plan reuses operation filtering, response resolution, branch-complete generation, round-trip assertions, and operation seed derivation; focused readonly DTOs carry categorized failures and skips.

**Tech Stack:** PHP 8.3+, PHPUnit 12/13, opis/json-schema through existing validation boundaries, PHPStan level 6, PHP-CS-Fixer.

## Global Constraints

- Keep PHP compatibility at the PHP 8.3 floor.
- Add no production dependency and keep Faker optional.
- Preserve response resolver dialect, discriminator, and malformed-node semantics.
- Do not guess generated SDK model names; all decode/encode mappings are explicit.
- Keep per-operation seeds order-independent through the existing crc32 derivation.
- Treat public symbols, method signatures, DTO fields, and failure categories as compatibility surfaces.
- Preserve unrelated `.claude/` and `docs/adr/0002-arazzo-workflow-execution.md` paths.
- Write each observable behavior test first, run it red, then add the minimum production code and run it green.

---

## File Structure

- Modify `src/Fuzz/OpenApiResponseExplorer.php`: expose the spec-wide factory.
- Create `src/Fuzz/OpenApiResponseSpecExploration.php`: own filtering, mappings, response discovery, execution, and aggregate assertion flow.
- Create `src/Fuzz/ResponseSpecExplorationSummary.php`: expose successful counts plus categorized rows.
- Create `src/Fuzz/ResponseSpecExplorationFailure.php`: carry replayable decode or round-trip failure context.
- Create `src/Fuzz/ResponseSpecExplorationSkip.php`: carry unsupported-response and mapping-gap context.
- Modify `src/Laravel/ExploresOpenApiEndpoint.php`: expose the configured-spec convenience.
- Create `tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`: pin the framework-independent behavior.
- Modify `tests/Unit/Laravel/ExploresOpenApiEndpointTest.php`: pin adapter behavior.
- Create `tests/fixtures/specs/sdk-roundtrip-plan.json`: cover filtering, exact/range/default responses, multi-JSON content, unsupported responses, and mapping gaps.
- Create malformed plan fixtures under `tests/fixtures/specs/`: cover malformed `responses`, response, content, and schema boundaries through the shared resolver.
- Modify `tests/fixtures/compatibility/v2-public-api.json` and `tests/Unit/Compatibility/PublicApiBaselineTest.php`: record intentional public API additions.
- Modify `docs/sdk-roundtrip.md`, `docs/fuzzing.md`, and `docs/api-reference.md`: document the plan and strict mapping gate.

---

### Task 1: Exact-status plan and public result types

**Files:**

- Create: `tests/fixtures/specs/sdk-roundtrip-plan.json`
- Create: `tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`
- Create: `src/Fuzz/OpenApiResponseSpecExploration.php`
- Create: `src/Fuzz/ResponseSpecExplorationSummary.php`
- Create: `src/Fuzz/ResponseSpecExplorationFailure.php`
- Create: `src/Fuzz/ResponseSpecExplorationSkip.php`
- Modify: `src/Fuzz/OpenApiResponseExplorer.php`

**Interfaces:**

- Produces: `OpenApiResponseExplorer::exploreSpec(string $specName, int $seed = 1, int $extraCases = 0): OpenApiResponseSpecExploration`.
- Produces: `OpenApiResponseSpecExploration::mapResponse(string $operationId, int|string $status, callable $decode, callable $encode): self`.
- Produces: `OpenApiResponseSpecExploration::assertRoundTrips(): ResponseSpecExplorationSummary`.
- Consumes: `OpenApiResponseExplorer::explore()`, `GeneratedResponseCase::assertRoundTrip()`, `SelectsExploredOperations`, and `ExploredOperation`.

- [ ] **Step 1: Add a representative plan fixture**

Create a 3.1 fixture containing `GET /pets` (`listPets`) with `200` JSON and problem+json schemas, `POST /pets` (`createPet`) with `201` JSON, a deprecated operation, an operation without an ID, and no-content/text-only responses. Give operations distinct tags so every shared filter can be asserted with literal expectations.

- [ ] **Step 2: Write the exact-status execution test**

```php
#[Test]
public function executes_every_json_schema_registered_for_an_exact_operation_status(): void
{
    $seen = [];

    $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan', seed: 41)
        ->includeOperations(['listPets'])
        ->mapResponse(
            'listPets',
            200,
            static function (GeneratedResponseCase $case, ExploredOperation $operation) use (&$seen): array {
                $seen[] = [$operation->operationId, $case->contentType, $case->seed];

                return $case->bodyAsArray() ?? [];
            },
            static fn(array $decoded): array => $decoded,
        )
        ->assertRoundTrips();

    $this->assertSame(1, $summary->executedOperations);
    $this->assertSame(2, $summary->executedResponses);
    $this->assertGreaterThanOrEqual(2, $summary->executedCases);
    $this->assertSame(['application/json', 'application/problem+json'], array_column($seen, 1));
    $this->assertSame([], $summary->decodeFailures);
    $this->assertSame([], $summary->roundTripFailures);
}
```

- [ ] **Step 3: Run the focused test red**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php --filter exact_operation_status`

Expected: FAIL because `OpenApiResponseExplorer::exploreSpec()` does not exist.

- [ ] **Step 4: Add public DTOs and the minimal exact-status plan**

Implement readonly result DTOs with these stable constructor fields:

```php
final readonly class ResponseSpecExplorationFailure
{
    public function __construct(
        public ExploredOperation $operation,
        public string $status,
        public int $wireStatus,
        public string $contentType,
        public int $caseIndex,
        public int $seed,
        public ?string $pinnedBranch,
        public string $replay,
        public string $message,
        public Throwable $cause,
    ) {}
}

final readonly class ResponseSpecExplorationSkip
{
    public function __construct(
        public ExploredOperation $operation,
        public string $status,
        public ?string $contentType,
        public string $reason,
        public bool $mappingGap = false,
    ) {}
}

final readonly class ResponseSpecExplorationSummary
{
    public function __construct(
        public int $executedOperations,
        public int $executedResponses,
        public int $executedCases,
        public array $operations,
        public array $decodeFailures,
        public array $roundTripFailures,
        public array $skips,
    ) {}
}
```

Add `exploreSpec()` argument guards for empty spec names and negative extra cases. In the plan, derive the operation seed with:

```php
$derivedSeed = crc32(implode("\0", [
    $this->specName,
    OpenApiOperationResolver::normalizeMethodForKey($method),
    $path,
    (string) $this->seed,
])) & 0x7fffffff;
```

Resolve each registered exact status through `ResponseSchemaResolver`, enumerate literal JSON media-type keys from the resolved response content, generate cases with `OpenApiResponseExplorer::explore()`, and execute decode → encode → `assertRoundTrip()`.

- [ ] **Step 5: Run the focused test green**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php --filter exact_operation_status`

Expected: PASS.

- [ ] **Step 6: Run the response explorer regression tests**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseExplorerTest.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

Expected: PASS.

- [ ] **Step 7: Commit the exact-status core**

```bash
git add src/Fuzz/OpenApiResponseExplorer.php src/Fuzz/OpenApiResponseSpecExploration.php src/Fuzz/ResponseSpecExplorationSummary.php src/Fuzz/ResponseSpecExplorationFailure.php src/Fuzz/ResponseSpecExplorationSkip.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php tests/fixtures/specs/sdk-roundtrip-plan.json
git commit -m "feat(fuzz): add spec-wide sdk round-trip plan"
```

### Task 2: Status discovery, filters, seeds, skips, and strict mapping

**Files:**

- Modify: `tests/fixtures/specs/sdk-roundtrip-plan.json`
- Modify: `tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`
- Modify: `src/Fuzz/OpenApiResponseSpecExploration.php`

**Interfaces:**

- Produces: mappings normalized to exact (`200`), range (`2XX` or `2xx`), and `default` declared keys.
- Produces: `OpenApiResponseSpecExploration::failOnUnmapped(bool $fail = true): self`.
- Consumes: `ResponseSchemaResolver::resolveOperation()` and `resolveResponseSchema()` so structural and dialect behavior cannot drift.

- [ ] **Step 1: Write status and mapping-gap tests**

```php
#[Test]
public function maps_range_and_default_response_keys_with_deterministic_wire_statuses(): void
{
    $statuses = [];

    $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan', seed: 7)
        ->includeOperations(['statusFallbacks'])
        ->mapResponse('statusFallbacks', '2XX', static function (GeneratedResponseCase $case) use (&$statuses): mixed {
            $statuses[] = $case->status;
            return $case->bodyAsObject();
        }, static fn(mixed $decoded): mixed => $decoded)
        ->mapResponse('statusFallbacks', 'default', static function (GeneratedResponseCase $case) use (&$statuses): mixed {
            $statuses[] = $case->status;
            return $case->bodyAsObject();
        }, static fn(mixed $decoded): mixed => $decoded)
        ->assertRoundTrips();

    $this->assertSame([200, 100], array_values(array_unique($statuses)));
    $this->assertSame(2, $summary->executedResponses);
}

#[Test]
public function unmapped_json_schemas_are_explicit_and_strict_mode_fails(): void
{
    $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
        ->includeOperations(['listPets'])
        ->assertRoundTrips();

    $this->assertCount(2, array_filter(
        $summary->skips,
        static fn(ResponseSpecExplorationSkip $skip): bool => $skip->mappingGap,
    ));

    $this->expectException(AssertionFailedError::class);
    $this->expectExceptionMessage('Unmapped response schemas');
    OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
        ->includeOperations(['listPets'])
        ->failOnUnmapped()
        ->assertRoundTrips();
}
```

Add table-driven tests for duplicate registrations, invalid status keys, filters matching no operations, no-content/text-only/schema-less/itemSchema skips, and operations without IDs.

- [ ] **Step 2: Run the new tests red**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php --filter 'range|unmapped|duplicate|filter|skip'`

Expected: FAIL because range/default discovery and strict mappings are absent.

- [ ] **Step 3: Implement declared-response discovery**

Normalize integer and exact-string statuses to decimal strings. Accept only `/^[1-5][0-9]{2}$/`, `/^[1-5](?:XX|xx)$/`, and `default`. Preserve a declared range key's casing for resolver identity.

Choose representative statuses with literal loops:

```php
private static function representativeRangeStatus(int $class, array $exact): ?int
{
    for ($status = $class * 100; $status <= ($class * 100) + 99; $status++) {
        if (!isset($exact[(string) $status])) {
            return $status;
        }
    }

    return null;
}
```

For `default`, choose the first status from 100 through 599 not claimed by an exact key or any declared range. Resolve each target first; only resolved JSON schemas can become mapping gaps. Convert all other structured outcomes to `ResponseSpecExplorationSkip` with the resolver's message/skip reason/outcome name.

Apply `SelectsExploredOperations` before response discovery. Track selected operations separately from executed mapped operations. Throw if filters select none.

- [ ] **Step 4: Implement strict mapping aggregation**

Add `failOnUnmapped()` and, after all targets are inspected, fail once with operation/status/content-type rows when strict mode sees mapping-gap skips. Do not fail ordinary unsupported-response skips.

- [ ] **Step 5: Run Task 2 tests green**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

Expected: PASS.

- [ ] **Step 6: Commit discovery and strictness**

```bash
git add src/Fuzz/OpenApiResponseSpecExploration.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php tests/fixtures/specs/sdk-roundtrip-plan.json
git commit -m "feat(fuzz): report sdk response mapping gaps"
```

### Task 3: Categorized callback failures and malformed-spec diagnostics

**Files:**

- Create: `tests/fixtures/specs/sdk-roundtrip-plan-malformed-responses.json`
- Create: `tests/fixtures/specs/sdk-roundtrip-plan-malformed-response.json`
- Create: `tests/fixtures/specs/sdk-roundtrip-plan-malformed-content.json`
- Modify: `tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`
- Modify: `src/Fuzz/OpenApiResponseSpecExploration.php`
- Modify: `src/Fuzz/ResponseSpecExplorationSummary.php`

**Interfaces:**

- Produces: categorized `decodeFailures` and `roundTripFailures` rows populated before one aggregate assertion failure.
- Preserves: the original throwable as `ResponseSpecExplorationFailure::$cause`.

- [ ] **Step 1: Write failure categorization tests**

```php
#[Test]
public function aggregate_failure_distinguishes_decode_and_round_trip_failures(): void
{
    try {
        OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan', seed: 9)
            ->includeOperations(['listPets', 'createPet'])
            ->mapResponse('listPets', 200, static function (): never {
                throw new RuntimeException('decoder rejected payload');
            }, static fn(mixed $decoded): mixed => $decoded)
            ->mapResponse('createPet', 201, static fn(GeneratedResponseCase $case): mixed => $case->bodyAsObject(), static fn(): array => [])
            ->assertRoundTrips();
        $this->fail('Expected aggregate SDK round-trip failure.');
    } catch (AssertionFailedError $e) {
        $this->assertStringContainsString('Decode failures', $e->getMessage());
        $this->assertStringContainsString('decoder rejected payload', $e->getMessage());
        $this->assertStringContainsString('Round-trip failures', $e->getMessage());
        $this->assertStringContainsString('replay:', $e->getMessage());
    }
}
```

Add malformed fixtures and tests that assert the shared resolver's exact location appears for malformed `responses`, response entries, content, media entries, and schema nodes.

- [ ] **Step 2: Run failure and malformed tests red**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php --filter 'failure|malformed'`

Expected: FAIL until callbacks are caught independently and malformed outcomes are escalated.

- [ ] **Step 3: Record failures and render one assertion**

Catch decode callback throwables into `decodeFailures`. Catch encode and `assertRoundTrip()` throwables into `roundTripFailures`. Continue to later cases and mappings. Render grouped headings and one row per failure with method/path, operation ID, declared and wire statuses, content type, seed, case, pinned branch, replay, and cause message. Use `Assert::fail()` only after traversal.

Malformed resolver outcomes are configuration/spec errors, not skips: throw `InvalidArgumentException` using the resolver message before any callback executes.

- [ ] **Step 4: Run the full plan test green**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php`

Expected: PASS.

- [ ] **Step 5: Commit failure diagnostics**

```bash
git add src/Fuzz/OpenApiResponseSpecExploration.php src/Fuzz/ResponseSpecExplorationSummary.php tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php tests/fixtures/specs/sdk-roundtrip-plan-malformed-*.json
git commit -m "feat(fuzz): categorize sdk round-trip plan failures"
```

### Task 4: Laravel convenience

**Files:**

- Modify: `tests/Unit/Laravel/ExploresOpenApiEndpointTest.php`
- Modify: `src/Laravel/ExploresOpenApiEndpoint.php`

**Interfaces:**

- Produces: `ExploresOpenApiEndpoint::exploreResponseSpec(int $seed = 1, int $extraCases = 0): OpenApiResponseSpecExploration`.
- Consumes: `ResolvesOpenApiSpec` and `OpenApiResponseExplorer::exploreSpec()`.

- [ ] **Step 1: Write the adapter test**

```php
#[Test]
#[OpenApiSpec('sdk-roundtrip-plan')]
public function exposes_the_spec_wide_response_round_trip_plan(): void
{
    $plan = $this->exploreResponseSpec(seed: 11, extraCases: 1);

    $this->assertInstanceOf(OpenApiResponseSpecExploration::class, $plan);
    $summary = $plan
        ->includeOperations(['createPet'])
        ->mapResponse('createPet', 201, static fn(GeneratedResponseCase $case): mixed => $case->bodyAsObject(), static fn(mixed $value): mixed => $value)
        ->assertRoundTrips();
    $this->assertSame(1, $summary->executedOperations);
}
```

- [ ] **Step 2: Run the adapter test red**

Run: `vendor/bin/phpunit tests/Unit/Laravel/ExploresOpenApiEndpointTest.php --filter spec_wide_response`

Expected: FAIL because `exploreResponseSpec()` does not exist.

- [ ] **Step 3: Add the convenience method**

Resolve and validate the spec name exactly like `exploreSpec()`, delegate to `OpenApiResponseExplorer::exploreSpec()`, and route `InvalidArgumentException|RuntimeException` through `failExplore()`.

- [ ] **Step 4: Run Laravel exploration tests green**

Run: `vendor/bin/phpunit tests/Unit/Laravel/ExploresOpenApiEndpointTest.php`

Expected: PASS.

- [ ] **Step 5: Commit the adapter**

```bash
git add src/Laravel/ExploresOpenApiEndpoint.php tests/Unit/Laravel/ExploresOpenApiEndpointTest.php
git commit -m "feat(laravel): expose spec-wide sdk round trips"
```

### Task 5: Public API inventory and documentation

**Files:**

- Modify: `tests/Unit/Compatibility/PublicApiBaselineTest.php`
- Modify: `tests/fixtures/compatibility/v2-public-api.json`
- Modify: `docs/sdk-roundtrip.md`
- Modify: `docs/fuzzing.md`
- Modify: `docs/api-reference.md`

**Interfaces:**

- Documents: `exploreSpec()`, `mapResponse()`, shared filters, range/default mapping, `failOnUnmapped()`, summary fields, callback failure categories, skips, seeds, and Laravel convenience.

- [ ] **Step 1: Run the compatibility test red**

Run: `vendor/bin/phpunit tests/Unit/Compatibility/PublicApiBaselineTest.php --filter public_php_api_matches_the_v2_baseline`

Expected: FAIL listing the new public classes and methods.

- [ ] **Step 2: Record intentional API additions**

Update the compatibility test's v1 adjustment block for issue #447, regenerate or edit `v2-public-api.json` through the repository's existing baseline workflow, and inspect the diff to ensure only the intended classes/methods appear.

- [ ] **Step 3: Document plain PHPUnit and Laravel usage**

Add the approved example to `docs/sdk-roundtrip.md`. State that one mapping covers every JSON media type under the declared response, exact/range/default selectors are supported, callback failures are aggregated by category, mapping gaps are always summary skips, and `failOnUnmapped()` turns only those gaps into failures. Remove the obsolete sentence calling spec-wide mapping a future phase.

Add a compact overview and link in `docs/fuzzing.md`, and list the new facade method, plan, summary, failure, and skip DTOs in `docs/api-reference.md`.

- [ ] **Step 4: Run documentation and compatibility checks**

Run: `vendor/bin/phpunit tests/Unit/Compatibility/PublicApiBaselineTest.php`

Run: `npx --yes markdownlint-cli2 'docs/**/*.md' 'README.md'`

Expected: both commands PASS.

- [ ] **Step 5: Run focused static and style checks**

Run: `composer stan`

Run: `composer cs-check`

Expected: both commands PASS.

- [ ] **Step 6: Run the full applicable suite**

Run: `composer test`

Expected: `0` failures and `0` errors.

- [ ] **Step 7: Commit compatibility and docs**

```bash
git add tests/Unit/Compatibility/PublicApiBaselineTest.php tests/fixtures/compatibility/v2-public-api.json docs/sdk-roundtrip.md docs/fuzzing.md docs/api-reference.md
git commit -m "docs(fuzz): document spec-wide sdk round trips"
```

### Task 6: Completion audit

**Files:**

- Verify all files changed by Tasks 1–5.

**Interfaces:**

- Proves each issue #447 scope item through a named test, checked public surface, or documentation section.

- [ ] **Step 1: Inspect the full branch diff**

Run: `git diff --stat main...HEAD`

Run: `git diff --check main...HEAD`

Run: `git status --short`

Confirm unrelated untracked paths remain untouched and no generated cache or secret is staged.

- [ ] **Step 2: Re-run the issue acceptance tests fresh**

Run: `vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseSpecExplorationTest.php tests/Unit/Laravel/ExploresOpenApiEndpointTest.php tests/Unit/Compatibility/PublicApiBaselineTest.php`

Expected: PASS.

- [ ] **Step 3: Map requirements to evidence**

Verify the issue's fluent plan, shared selection surface, explicit per-operation/status mappings, terminal summary, user-side schema mapping, crc32 seeds, explicit skips, categorized failures, strict unmapped gate, framework-independent core, Laravel convenience, docs, and compatibility baseline each have direct current-state evidence.
