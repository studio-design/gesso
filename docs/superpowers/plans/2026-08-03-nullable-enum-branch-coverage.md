# Nullable Enum Branch Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate both reachable null and non-null response cases when a nullable converted JSON Schema has an enum spanning both sides, without inventing branches for single-value or one-sided finite domains.

**Architecture:** Keep nullable enum classification in the existing pre-pass enumerator and honor the resulting `/type` selection in the existing schema generator. The enumerator filters enum members through `SchemaValueValidator` before deciding whether two branches exist; the generator partitions its already-admissible planned enum domain by the pinned null/value selection. Public explorer APIs, choice-point pointers, and plan-less enum rotation remain unchanged.

**Tech Stack:** PHP 8.3+, PHPUnit 12/13 attributes, existing internal fuzzing classes, Composer CI scripts.

## Global Constraints

- Preserve compatibility with the PHP 8.3 floor and the existing public API inventory.
- Add no production dependency, public symbol, configuration option, or wire-format change.
- Keep request exploration without a `CaseSelectionPlan` bit-for-bit on its existing enum rotation path.
- Enumerate a nullable finite domain only when valid null and non-null members are both reachable.
- Treat `const` and one-sided enums as terminal domains without synthetic nullable branches.
- Preserve unrelated worktree changes, including `.claude/` and `docs/adr/0002-arazzo-workflow-execution.md`.

---

## File Structure

- Modify `src/Fuzz/SchemaChoicePointEnumerator.php`: classify admissible enum members before terminating exact-value leaf traversal.
- Modify `src/Fuzz/SchemaDataGenerator.php`: apply a pinned `/type` selection to planned enum generation.
- Modify `tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php`: pin two-sided and one-sided finite-domain enumeration behavior.
- Modify `tests/Unit/Fuzz/SchemaDataGeneratorTest.php`: prove pinned nullable selections override enum rotation.
- Modify `tests/Unit/Fuzz/BranchCompleteCaseGeneratorTest.php`: prove the end-to-end case set contains both reachable sides.

### Task 1: Enumerate only reachable nullable enum sides

**Files:**
- Modify: `src/Fuzz/SchemaChoicePointEnumerator.php:318-342`
- Test: `tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php:297-312`

**Interfaces:**
- Consumes: `SchemaChoicePointEnumerator::enumerate(array<string, mixed>): list<SchemaChoicePoint>` and `SchemaValueValidator::isValid(mixed, array<string, mixed>): bool`.
- Produces: an existing `SchemaChoicePointKind::Nullable` at `<pointer>/type`, with branch `SchemaChoicePoint::VALUE` equal to `0` and `SchemaChoicePoint::NULL_VALUE` equal to `1`, only for enum domains admitting both categories.

- [ ] **Step 1: Replace the overly broad exact-domain regression test**

Replace `stops_at_const_and_enum_schemas()` with focused tests that describe the finite-domain contract:

```php
#[Test]
public function collects_nullable_choice_for_an_enum_spanning_both_reachable_sides(): void
{
    $points = SchemaChoicePointEnumerator::enumerate([
        'type' => ['string', 'null'],
        'enum' => ['member', null],
    ]);

    $this->assertCount(1, $points);
    $this->assertSame(SchemaChoicePointKind::Nullable, $points[0]->kind);
    $this->assertSame('/type', $points[0]->pointer);
    $this->assertSame(2, $points[0]->branchCount);
    $this->assertSame([], $points[0]->ancestors);
}

#[Test]
public function stops_at_exact_domains_that_reach_only_one_nullable_side(): void
{
    $schemas = [
        ['type' => ['string', 'null'], 'const' => 'fixed'],
        ['type' => ['string', 'null'], 'const' => null],
        ['type' => ['string', 'null'], 'enum' => ['fixed']],
        ['type' => ['string', 'null'], 'enum' => [null]],
        [
            'type' => ['string', 'null'],
            'enum' => ['fixed', null],
            'not' => ['const' => null],
        ],
    ];

    foreach ($schemas as $schema) {
        $this->assertSame([], SchemaChoicePointEnumerator::enumerate($schema));
    }
}
```

- [ ] **Step 2: Run the enumerator tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php
```

Expected: FAIL in `collects_nullable_choice_for_an_enum_spanning_both_reachable_sides()` because the current early enum return produces zero points. The one-sided exact-domain assertions remain green.

- [ ] **Step 3: Classify the admissible enum domain before returning**

Keep `const` terminal. In the non-empty enum branch of `visitLeaf()`, filter members against the effective schema and record the existing nullable choice only when the filtered domain contains both null and non-null values:

```php
if (array_key_exists('const', $schema)) {
    return;
}
if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
    $admissible = array_values(array_filter(
        $schema['enum'],
        static fn(mixed $value): bool => SchemaValueValidator::isValid($value, $schema),
    ));
    $hasNull = in_array(null, $admissible, true);
    $hasValue = array_filter($admissible, static fn(mixed $value): bool => $value !== null) !== [];
    if (self::isNullableTypeArray($schema) && $hasNull && $hasValue) {
        $this->record(
            SchemaChoicePointKind::Nullable,
            $pointer . '/type',
            2,
            $ancestors,
            null,
        );
    }

    return;
}
```

Do not descend into properties or items after an enum: the finite values remain terminal and already determine their entire shape.

- [ ] **Step 4: Run the enumerator tests and verify GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php
```

Expected: PASS with both the two-sided enum and all one-sided finite domains classified correctly.

- [ ] **Step 5: Commit the enumerator slice**

```bash
git add src/Fuzz/SchemaChoicePointEnumerator.php tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php
git commit -m "fix(fuzz): enumerate nullable enum branches"
```

### Task 2: Honor nullable pins during enum generation

**Files:**
- Modify: `src/Fuzz/SchemaDataGenerator.php:676-716`
- Test: `tests/Unit/Fuzz/SchemaDataGeneratorTest.php:1148-1172`
- Test: `tests/Unit/Fuzz/BranchCompleteCaseGeneratorTest.php:109-126`

**Interfaces:**
- Consumes: `CaseSelectionPlan::branchFor(string): ?int`, the `/type` pointer emitted by Task 1, and the existing enum admissibility filter.
- Produces: planned enum generation that returns null for `SchemaChoicePoint::NULL_VALUE` and an admissible non-null member for `SchemaChoicePoint::VALUE`, while registering the existing `PinnedBranchObservation` target predicate.

- [ ] **Step 1: Add a direct pinned-generation regression test**

Add next to `pinned_plan_selects_nullable_branches()`:

```php
#[Test]
public function pinned_plan_partitions_nullable_enum_values(): void
{
    $schema = [
        'type' => ['string', 'null'],
        'enum' => [null, 'member', 'alternate'],
    ];

    $null = SchemaDataGenerator::generateOne(
        $schema,
        null,
        2,
        new CaseSelectionPlan(['/type' => SchemaChoicePoint::NULL_VALUE]),
    );
    $this->assertNull($null);

    $value = SchemaDataGenerator::generateOne(
        $schema,
        null,
        0,
        new CaseSelectionPlan(['/type' => SchemaChoicePoint::VALUE]),
    );
    $this->assertSame('member', $value);
}
```

The chosen iterations deliberately contradict normal enum rotation: iteration `2` would select `alternate`, while iteration `0` would select `null`.

- [ ] **Step 2: Add the branch-complete end-to-end regression test**

Add next to `covers_both_nullable_branches()`:

```php
#[Test]
public function covers_both_reachable_nullable_enum_branches(): void
{
    $schema = [
        'type' => ['string', 'null'],
        'enum' => [null, 'member', 'alternate'],
    ];

    $cases = BranchCompleteCaseGenerator::generate($schema, seed: 1);
    $values = array_map(static fn(PlannedSchemaCase $case): mixed => $case->value, $cases);

    $this->assertCount(2, $cases);
    $this->assertContains(null, $values);
    $this->assertContains('member', $values);
    foreach ($cases as $case) {
        $this->assertTrue(SchemaValueValidator::isValid($case->value, $schema));
        $this->assertSame('/type', $case->plan->targetPointer);
    }
}
```

- [ ] **Step 3: Run both new behavior tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Fuzz/SchemaDataGeneratorTest.php --filter pinned_plan_partitions_nullable_enum_values
vendor/bin/phpunit tests/Unit/Fuzz/BranchCompleteCaseGeneratorTest.php --filter covers_both_reachable_nullable_enum_branches
```

Expected: the direct test fails because enum rotation ignores the pin. The branch-complete test fails because target observation is never registered and the target search cannot prove either requested branch.

- [ ] **Step 4: Partition admissible enum values by the nullable pin**

In `SchemaDataGenerator::generateNode()`, derive the nullable pointer and pin before the enum return. Inside planned enum generation, register the normal target predicate and restrict the admissible finite domain when a pin exists:

```php
$nullablePointer = $pointer . '/type';
$pinnedNullable = $plan?->branchFor($nullablePointer);

// Existing const branch remains here.

if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
    $values = array_values($schema['enum']);
    if ($plan !== null) {
        $admissible = array_values(array_filter(
            $values,
            static fn(mixed $value): bool => SchemaValueValidator::isValid($value, $schema),
        ));
        if ($pinnedNullable !== null) {
            $wantNull = $pinnedNullable === SchemaChoicePoint::NULL_VALUE;
            if ($plan->targetPointer === $nullablePointer) {
                $plan->observation->expect(
                    $pointer,
                    static fn(mixed $value): bool => ($value === null) === $wantNull,
                );
            }
            $admissible = array_values(array_filter(
                $admissible,
                static fn(mixed $value): bool => ($value === null) === $wantNull,
            ));
        }
        if ($admissible === []) {
            if ($forced) {
                $plan->observation->proveDeadEnd();
            }

            return $values[0];
        }
        if ((isset($schema['not']) && is_array($schema['not'])) || $pinnedNullable !== null) {
            return $admissible[$iteration % count($admissible)];
        }
    }

    return $values[$iteration % count($values)];
}
```

Reuse `$nullablePointer` and `$pinnedNullable` in the existing generic nullable block below the enum branch. Do not change the plan-less return, so request generation keeps rotating over the original enum order.

- [ ] **Step 5: Run focused generator tests and verify GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Fuzz/SchemaDataGeneratorTest.php --filter pinned_plan_partitions_nullable_enum_values
vendor/bin/phpunit tests/Unit/Fuzz/BranchCompleteCaseGeneratorTest.php --filter covers_both_reachable_nullable_enum_branches
vendor/bin/phpunit tests/Unit/Fuzz/BranchCompleteCaseGeneratorTest.php --filter covers_both_nullable_branches
```

Expected: all focused tests PASS; the generic nullable test confirms the non-enum path remains intact.

- [ ] **Step 6: Run all affected fuzzing suites**

Run:

```bash
vendor/bin/phpunit \
  tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php \
  tests/Unit/Fuzz/SchemaDataGeneratorTest.php \
  tests/Unit/Fuzz/BranchCompleteCaseGeneratorTest.php
```

Expected: PASS with no warnings or failures.

- [ ] **Step 7: Commit the generation slice**

```bash
git add \
  src/Fuzz/SchemaDataGenerator.php \
  tests/Unit/Fuzz/SchemaDataGeneratorTest.php \
  tests/Unit/Fuzz/BranchCompleteCaseGeneratorTest.php
git commit -m "fix(fuzz): honor nullable enum branch plans"
```

### Task 3: Verify and publish the fix

**Files:**
- Verify: all tracked changes on `fix/nullable-enum-branch-coverage`
- Preserve: `.claude/`, `docs/adr/0002-arazzo-workflow-execution.md`

**Interfaces:**
- Consumes: the two implementation commits from Tasks 1 and 2.
- Produces: a verified draft pull request targeting `main`, with the documented nullable enum example and test evidence.

- [ ] **Step 1: Apply repository formatting and inspect its scope**

Run:

```bash
composer cs
git status --short
git diff --check
```

Expected: only the plan, design, two fuzzing classes, and three focused test files are tracked changes or branch commits; unrelated untracked paths remain untouched.

- [ ] **Step 2: Run the repository CI gate fresh**

Run:

```bash
NPM_CONFIG_CACHE=/tmp/gesso-npm-cache composer ci
```

Expected: both PHP-CS-Fixer checks, PHPStan, and the complete PHPUnit suite PASS.

- [ ] **Step 3: Inspect the final branch and commit any formatter-only adjustment**

Run:

```bash
git diff main...HEAD --check
git diff --stat main...HEAD
git status --short --branch
```

If `composer cs` changed any implementation/test file, stage only the fixed
in-scope paths (Git ignores unchanged paths) and commit the adjustment:

```bash
git add \
  src/Fuzz/SchemaChoicePointEnumerator.php \
  src/Fuzz/SchemaDataGenerator.php \
  tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php \
  tests/Unit/Fuzz/SchemaDataGeneratorTest.php \
  tests/Unit/Fuzz/BranchCompleteCaseGeneratorTest.php
git commit -m "style: format nullable enum coverage fix"
```

Do not stage `.claude/` or `docs/adr/0002-arazzo-workflow-execution.md`.

- [ ] **Step 4: Push and create the draft pull request**

Run:

```bash
git push -u origin fix/nullable-enum-branch-coverage
```

Create a draft PR targeting `main` with title:

```text
fix(fuzz): cover nullable enum branches
```

The PR body must summarize the finite-domain partition, `const`/one-sided enum behavior, RED-to-GREEN evidence, affected focused suites, and the fresh `composer ci` result.
