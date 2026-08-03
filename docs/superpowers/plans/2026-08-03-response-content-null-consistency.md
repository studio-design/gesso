# Response `content: null` Consistency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make runtime response validation reject an explicitly null Response Object `content` field while preserving no-content behavior when the field is omitted.

**Architecture:** Keep `ResponseSchemaResolver` as the shared runtime boundary. Add one malformed fixture consumed by resolver and public-validator regression tests, then replace the resolver's non-null presence check with an explicit key-presence check so the existing `MalformedSpecNode` guard handles null.

**Tech Stack:** PHP 8.3+, PHPUnit, Composer, JSON OpenAPI fixtures.

## Global Constraints

- Preserve PHP 8.3 compatibility and existing public APIs.
- Add no production dependency.
- Keep omitted `content` resolving to `NoContent`.
- Keep doctor behavior unchanged.
- Do not modify unrelated nullable-enum or exploration behavior.

---

### Task 1: Reject explicit null response content at runtime

**Files:**
- Modify: `tests/fixtures/specs/malformed.json`
- Modify: `tests/Unit/Validation/Response/ResponseSchemaResolverTest.php`
- Modify: `tests/Unit/OpenApiResponseValidatorTest.php`
- Modify: `src/Validation/Response/ResponseSchemaResolver.php`

**Interfaces:**
- Consumes: `ResponseSchemaResolver::resolve(string $specName, string $method, string $path, int $statusCode, ?string $contentType = null): ResponseSchemaResolution`
- Produces: existing `ResponseSchemaResolutionOutcome::MalformedContent` for a present `content` key whose value is null; no new interface.

- [ ] **Step 1: Add the malformed OpenAPI fixture**

Add this path to `tests/fixtures/specs/malformed.json` beside the existing
response-content malformed cases:

```json
"/response-null-content": {
    "get": {
        "summary": "responses[200].content is null",
        "operationId": "responseNullContent",
        "responses": {
            "200": {
                "description": "OK",
                "content": null
            }
        }
    }
}
```

- [ ] **Step 2: Write resolver and public-validator regression tests**

Extend `resolve_reports_malformed_content_nodes()` in
`ResponseSchemaResolverTest` with:

```php
$nullContent = $this->resolver->resolve('malformed', 'GET', '/response-null-content', 200, 'application/json');
$this->assertSame(ResponseSchemaResolutionOutcome::MalformedContent, $nullContent->outcome);
$this->assertStringContainsString("Malformed 'responses[200].content'", (string) $nullContent->message);
$this->assertStringContainsString('expected object, got null', (string) $nullContent->message);
```

Add this test to `OpenApiResponseValidatorTest`:

```php
#[Test]
public function null_response_content_block_returns_failure(): void
{
    $result = $this->validator->validate(
        'malformed',
        'GET',
        '/response-null-content',
        200,
        ['id' => 1],
        'application/json',
    );

    $this->assertFalse($result->isValid());
    $this->assertStringContainsString(
        "Malformed 'responses[200].content'",
        $result->errors()[0],
    );
    $this->assertStringContainsString('expected object, got null', $result->errors()[0]);
}
```

- [ ] **Step 3: Run the tests to verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Validation/Response/ResponseSchemaResolverTest.php --filter resolve_reports_malformed_content_nodes
vendor/bin/phpunit tests/Unit/OpenApiResponseValidatorTest.php --filter null_response_content_block_returns_failure
```

Expected: both fail because explicit null currently resolves through
`ResponseSchemaResolutionOutcome::NoContent` and public validation succeeds.

- [ ] **Step 4: Make the minimal resolver change**

In `ResponseSchemaResolver`, replace the historical `isset()` branch and its
comment with:

```php
// 204 No Content (and similar) declare no `content` block. Check key
// presence so an explicit `content: null` reaches the malformed-node guard.
if (!array_key_exists('content', $responseSpec)) {
    return ResponseSchemaResolution::noContent($matchedPath, $matchedResponseKey, $responseSpec);
}
```

- [ ] **Step 5: Run focused verification to confirm GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Validation/Response/ResponseSchemaResolverTest.php
vendor/bin/phpunit tests/Unit/OpenApiResponseValidatorTest.php
vendor/bin/phpunit tests/Unit/Cli/DoctorCommandTest.php --filter rejects_nested_response_nodes_using_runtime_malformed_node_rules
```

Expected: all tests pass, including the doctor's existing explicit-null case.

- [ ] **Step 6: Run repository checks**

Run:

```bash
composer ci
```

Expected: PHP-CS-Fixer checks, PHPStan, and PHPUnit all pass.

- [ ] **Step 7: Commit the implementation**

```bash
git add tests/fixtures/specs/malformed.json \
  tests/Unit/Validation/Response/ResponseSchemaResolverTest.php \
  tests/Unit/OpenApiResponseValidatorTest.php \
  src/Validation/Response/ResponseSchemaResolver.php
git commit -m "fix(validation): reject null response content"
```

### Task 2: Publish the draft pull request

**Files:**
- Modify: none

**Interfaces:**
- Consumes: the complete feature-branch commit history and repository PR conventions.
- Produces: a draft GitHub pull request targeting the repository default branch.

- [ ] **Step 1: Confirm publish scope and authentication**

Run:

```bash
git status --short --branch
git diff main...HEAD --stat
gh --version
gh auth status
```

Expected: only the design, plan, fixture, tests, and resolver change are in the
branch diff; unrelated untracked files remain unstaged; GitHub CLI is authenticated.

- [ ] **Step 2: Push the feature branch**

```bash
git push -u origin fix/response-content-null-consistency
```

- [ ] **Step 3: Open a draft PR**

Use the title:

```text
fix(validation): reject null response content
```

The body must explain the runtime/doctor inconsistency, the `isset()` root
cause, the explicit key-presence fix, unchanged omitted-content behavior, and
the successful focused and repository checks.
