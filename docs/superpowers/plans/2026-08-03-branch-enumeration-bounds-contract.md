# Branch-complete Enumeration Bounds Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete issue #443's finite-enumeration contract by documenting all exact bounds, directly protecting the node-visit guard, and correcting obsolete dead-end guidance.

**Architecture:** Preserve the existing internal enumerator and all public behavior. Add one boundary test against the real `SchemaChoicePointEnumerator`, then align user-facing and internal documentation with the already-implemented limits and static-proof-only dead-end policy.

**Tech Stack:** PHP 8.3+, PHPUnit, Markdown, PHP-CS-Fixer, PHPStan.

## Global Constraints

- Keep `MAX_DEPTH = 32`, `MAX_CHOICE_POINTS = 256`, and `MAX_NODE_VISITS = 10_000` unchanged.
- Do not add public API or dependencies.
- Exceeding a bound must remain loud; partial branch-coverage results are forbidden.
- Preserve unrelated worktree files and changes.

---

### Task 1: Protect the node-visit budget

**Files:**
- Test: `tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php`
- Temporarily mutate and restore: `src/Fuzz/SchemaChoicePointEnumerator.php:68`

**Interfaces:**
- Consumes: `SchemaChoicePointEnumerator::enumerate(array $schema): array`
- Produces: a regression test for the existing 10,000-node visit limit

- [ ] **Step 1: Add the real-behavior boundary test**

Add this test beside the existing depth and choice-point budget tests:

```php
#[Test]
public function throws_beyond_the_documented_node_visit_budget(): void
{
    $prefixItems = [];
    for ($i = 0; $i < 10_000; $i++) {
        $prefixItems[] = ['type' => 'string'];
    }

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('node-visit budget of 10000');

    SchemaChoicePointEnumerator::enumerate([
        'type' => 'array',
        'prefixItems' => $prefixItems,
    ]);
}
```

- [ ] **Step 2: Run the focused test against current behavior**

Run:

```bash
vendor/bin/phpunit --filter throws_beyond_the_documented_node_visit_budget tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php
```

Expected: PASS because the production guard already exists.

- [ ] **Step 3: Prove the regression test catches a weakened guard**

Temporarily change only `MAX_NODE_VISITS` from `10_000` to `10_001`, rerun the
same focused command, and confirm it FAILS because the expected exception is not
thrown. Restore `MAX_NODE_VISITS` to `10_000` immediately afterward.

- [ ] **Step 4: Run the focused suite after restoration**

Run:

```bash
vendor/bin/phpunit tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php
```

Expected: PASS with the new budget test included.

- [ ] **Step 5: Commit the regression test**

```bash
git add tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php
git commit -m "test(fuzz): cover enumeration node budget"
```

### Task 2: Publish and align the bounds contract

**Files:**
- Modify: `docs/fuzzing.md:238-246`
- Modify: `src/Fuzz/PinnedBranchObservation.php:21-25`
- Modify: `docs/superpowers/specs/2026-08-03-branch-enumeration-bounds-contract-design.md`

**Interfaces:**
- Consumes: the internal constants and `PinnedBranchObservation::$provenDeadEnd` policy
- Produces: accurate user-facing bounds and accurate internal dead-end guidance

- [ ] **Step 1: Document exact finite-enumeration limits**

After the supported-subset paragraph in `docs/fuzzing.md`, add that response
branch enumeration accepts at most 32 nested property/item levels, 256 choice
points, and 10,000 visited nodes across branch contexts. State that exceeding a
limit fails locally with a supported-subset diagnostic and never returns
partial branch coverage.

- [ ] **Step 2: Correct the dead-end policy comment**

Replace the obsolete deterministic-outcome paragraph in
`PinnedBranchObservation` with wording that `targetLocal` is diagnostic state
only and that a case may be dropped solely after `proveDeadEnd()` records a
schema-derived empty-domain proof. Search exhaustion or repeated output remains
loud.

- [ ] **Step 3: Format and inspect the scoped diff**

Run:

```bash
composer cs
git diff --check
git diff -- docs/fuzzing.md src/Fuzz/PinnedBranchObservation.php tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php docs/superpowers/specs/2026-08-03-branch-enumeration-bounds-contract-design.md
```

Expected: formatter succeeds, no whitespace errors, and no production behavior changes.

- [ ] **Step 4: Commit the contract documentation**

```bash
git add docs/fuzzing.md src/Fuzz/PinnedBranchObservation.php docs/superpowers/specs/2026-08-03-branch-enumeration-bounds-contract-design.md docs/superpowers/plans/2026-08-03-branch-enumeration-bounds-contract.md
git commit -m "docs(fuzz): publish enumeration bounds"
```

### Task 3: Verify the completed contract

**Files:**
- Verify only; no planned edits

**Interfaces:**
- Consumes: Tasks 1 and 2
- Produces: fresh evidence for issue #441's final acceptance audit

- [ ] **Step 1: Run the focused enumerator suite**

```bash
vendor/bin/phpunit tests/Unit/Fuzz/SchemaChoicePointEnumeratorTest.php
```

- [ ] **Step 2: Run the full repository gate**

```bash
NPM_CONFIG_CACHE=/tmp/gesso-npm-cache composer ci
```

- [ ] **Step 3: Verify repository scope**

```bash
git diff main...HEAD --check
git status --short --branch
```

Expected: all checks pass; only the known unrelated `.claude/` and
`docs/adr/0002-arazzo-workflow-execution.md` remain untracked.
