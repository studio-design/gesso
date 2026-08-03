# Named Component Schema Explorer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `OpenApiResponseExplorer::exploreComponent()` so SDK models can be exercised by a named `components.schemas` entry with the existing branch-complete and round-trip guarantees.

**Architecture:** Keep component lookup and operation-response lookup as separate public paths in `OpenApiResponseExplorer`, then funnel both converted schemas through one private case builder. Reuse `GeneratedResponseCase` and `GeneratedResponseCases`; make only operation metadata nullable and choose the correct replay call from stored source metadata.

**Tech Stack:** PHP 8.3+, PHPUnit, opis/json-schema through the existing validation boundary, PHPStan level 6, PHP-CS-Fixer.

## Global Constraints

- Keep PHP compatibility at the repository's PHP 8.3 floor.
- Add no production dependency; generation must keep working without optional Faker.
- Convert component schemas with the document's OpenAPI version, JSON Schema dialect, `SchemaContext::Response`, and current discriminator-enforcement gate.
- Unknown, malformed, recursive, or otherwise unsupported schemas must throw; no empty or skipped green run is allowed.
- Preserve existing operation-response behavior and replay strings.
- Public symbols and constructor/property types are v2 compatibility surfaces; update the checked-in public API inventory for intentional changes.
- Follow PER-CS2.0 and snake_case PHPUnit test method naming.
- Preserve the unrelated untracked `.claude/` and `docs/adr/0002-arazzo-workflow-execution.md` paths.

---

## File Structure

- Modify `src/Fuzz/OpenApiResponseExplorer.php`: resolve named components, convert them in response context, and centralize case construction.
- Modify `src/Fuzz/GeneratedResponseCase.php`: represent absent operation metadata and render component replay snippets.
- Modify `tests/Unit/Fuzz/OpenApiResponseExplorerTest.php`: cover generation, conversion context, malformed/unknown/recursive failures, replay metadata, and round-trip fidelity through the public facade.
- Modify `tests/fixtures/specs/sdk-roundtrip.json`: add branch-complete and malformed named component fixtures.
- Modify `tests/fixtures/specs/dialect-draft07.json`: add a Draft 07 tuple component proving document dialect propagation.
- Create `tests/fixtures/specs/component-malformed-components.json`: malformed `components` boundary.
- Create `tests/fixtures/specs/component-malformed-schemas.json`: malformed `components.schemas` boundary.
- Modify `docs/api-reference.md`: document the new public method and nullable component-case metadata.
- Modify `docs/sdk-roundtrip.md`: add named-model usage and remove the obsolete follow-up statement.
- Modify `tests/fixtures/compatibility/v2-public-api.json`: record the public method and intentional nullable metadata.

---

### Task 1: Public component explorer and reusable cases

**Files:**
- Modify: `tests/fixtures/specs/sdk-roundtrip.json`
- Modify: `tests/fixtures/specs/dialect-draft07.json`
- Create: `tests/fixtures/specs/component-malformed-components.json`
- Create: `tests/fixtures/specs/component-malformed-schemas.json`
- Modify: `tests/Unit/Fuzz/OpenApiResponseExplorerTest.php`
- Modify: `src/Fuzz/OpenApiResponseExplorer.php`
- Modify: `src/Fuzz/GeneratedResponseCase.php`

**Interfaces:**
- Consumes: `OpenApiSpecLoader::load(string): array`, `OpenApiVersion::fromSpec(array): OpenApiVersion`, `OpenApiSchemaDialect::fromSpec(array, OpenApiVersion): string`, `OpenApiSchemaConverter::convert(array, OpenApiVersion, SchemaContext, ?DiscriminatorContext, ?string): array`, and `BranchCompleteCaseGenerator::generate(array, ?int, int): list<PlannedSchemaCase>`.
- Produces: `OpenApiResponseExplorer::exploreComponent(string $specName, string $schemaName, ?int $seed = null, int $extraCases = 0): GeneratedResponseCases` and component cases whose `status`/`contentType` are `null`.

- [ ] **Step 1: Add representative named-component fixtures**

Add these component entries to `tests/fixtures/specs/sdk-roundtrip.json`:

```json
"components": {
  "schemas": {
    "IntrospectResponse": {
      "type": "object",
      "required": ["active"],
      "properties": {
        "active": {"type": "boolean"},
        "aud": {
          "oneOf": [
            {"type": "string"},
            {"type": "array", "items": {"type": "string"}}
          ]
        },
        "secret": {"type": "string", "writeOnly": true}
      }
    },
    "JsonWebKey": {
      "type": "object",
      "required": ["kty"],
      "properties": {"kty": {"type": "string", "enum": ["RSA", "EC"]}},
      "discriminator": {
        "propertyName": "kty",
        "mapping": {
          "RSA": "#/components/schemas/RsaJsonWebKey",
          "EC": "#/components/schemas/EcJsonWebKey"
        }
      }
    },
    "RsaJsonWebKey": {
      "type": "object",
      "required": ["n"],
      "properties": {"n": {"type": "string"}}
    },
    "EcJsonWebKey": {
      "type": "object",
      "required": ["x"],
      "properties": {"x": {"type": "string"}}
    },
    "MalformedSchema": "not a schema object"
  }
}
```

Add a `Tuple` component to `tests/fixtures/specs/dialect-draft07.json` using the fixture's existing Draft 07 tuple schema:

```json
"components": {
  "schemas": {
    "Tuple": {
      "type": "array",
      "items": [{"type": "string"}, {"type": "integer"}],
      "additionalItems": false
    }
  }
}
```

Create the two malformed-boundary fixtures as minimal OpenAPI 3.1 documents, one with `"components": "invalid"` and one with `"components": {"schemas": "invalid"}`.

- [ ] **Step 2: Write public-behavior tests before production changes**

In `OpenApiResponseExplorerTest`, add tests using only real specs and the public facade. The primary generation test must assert literal branch descriptors, metadata, and replay shape:

```php
#[Test]
public function explores_a_named_component_with_branch_complete_cases_and_no_operation_metadata(): void
{
    $cases = OpenApiResponseExplorer::exploreComponent(
        'sdk-roundtrip',
        'IntrospectResponse',
        seed: 1,
        extraCases: 1,
    );

    $this->assertSame(
        [
            '/properties/aud@0',
            '/properties/aud@1',
            '/properties/aud/oneOf@0',
            '/properties/aud/oneOf@1',
        ],
        array_values(array_filter(array_map(
            static fn(GeneratedResponseCase $case): ?string => $case->pinnedBranch,
            $cases->cases,
        ))),
    );
    foreach ($cases as $case) {
        $this->assertNull($case->status);
        $this->assertNull($case->contentType);
        $this->assertArrayNotHasKey('secret', $case->bodyAsArray() ?? []);
    }
    $this->assertStringContainsString(
        "OpenApiResponseExplorer::exploreComponent('sdk-roundtrip', 'IntrospectResponse', seed: 1, extraCases: 1)",
        $cases->cases[0]->replaySnippet(),
    );
}
```

Add separate tests whose named break is:

```php
#[Test]
public function component_cases_keep_the_existing_round_trip_fidelity_assertion(): void
{
    $case = OpenApiResponseExplorer::exploreComponent('sdk-roundtrip', 'IntrospectResponse', seed: 1)->cases[2];
    $body = $case->bodyAsArray();
    $this->assertIsArray($body);
    $case->assertRoundTrip($body);

    unset($body['aud']);
    $this->expectException(AssertionFailedError::class);
    $this->expectExceptionMessage("missing generated key 'aud'");
    $case->assertRoundTrip($body);
}

#[Test]
public function component_conversion_uses_the_document_json_schema_dialect(): void
{
    $case = OpenApiResponseExplorer::exploreComponent('dialect-draft07', 'Tuple', seed: 1)->cases[0];
    $this->assertIsArray($case->body);
    $this->assertCount(2, $case->body);
    $this->assertIsString($case->body[0]);
    $this->assertIsInt($case->body[1]);
}

#[Test]
public function component_discriminator_mapping_resolves_against_the_document_root(): void
{
    $cases = OpenApiResponseExplorer::exploreComponent('sdk-roundtrip', 'JsonWebKey', seed: 1);
    $encoded = json_encode(array_map(static fn(GeneratedResponseCase $case): mixed => $case->body, $cases->cases));
    $this->assertIsString($encoded);
    $this->assertStringContainsString('RSA', $encoded);
    $this->assertStringContainsString('EC', $encoded);
}
```

Add a data provider for unknown and malformed boundaries asserting `InvalidArgumentException` and stable context fragments (`components`, `components.schemas`, exact schema name, and spec name). Add a negative-`extraCases` test naming `exploreComponent()`. Add a recursive-schema test that calls `exploreComponent('refs-circular', 'Node')` and expects `InvalidOpenApiSpecException` containing `Circular $ref`.

Before running, name the mutations these tests catch: operation metadata accidentally retained, response context omitted, dialect hard-coded, discriminator root omitted, missing structural guards, silent unknown-name handling, and recursion swallowed.

- [ ] **Step 3: Run the focused tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseExplorerTest.php
```

Expected: test collection fails because `OpenApiResponseExplorer::exploreComponent()` does not exist. Confirm the failure is not fixture JSON syntax or test setup.

- [ ] **Step 4: Implement `exploreComponent()` and shared case construction**

In `OpenApiResponseExplorer.php`, import the existing version, dialect, loader, converter, schema context, discriminator, enforcement, malformed-node, and array helpers. Add the public method with the approved signature. Use `array_key_exists()` for exact component lookup and `MalformedSpecNode::isMalformed()` at all three structural boundaries. Convert using:

```php
$version = OpenApiVersion::fromSpec($spec);
$schema = OpenApiSchemaConverter::convert(
    $componentSchema,
    $version,
    SchemaContext::Response,
    new DiscriminatorContext($spec, DiscriminatorEnforcement::isEnabled()),
    OpenApiSchemaDialect::fromSpec($spec, $version),
);
```

Extract the existing `BranchCompleteCaseGenerator` plus `array_map()` loop into a private method that takes the converted schema, effective seed, extra cases, spec name, and nullable replay metadata. Operation `explore()` must pass its existing status, content type, method, and matched path; component exploration must pass `schemaName` and null operation metadata.

Emit contextual errors with this form:

```php
throw new InvalidArgumentException(sprintf(
    "Component schema '%s' is not defined in '%s' spec.",
    $schemaName,
    $specName,
));
```

For malformed boundaries, include the exact location and `MalformedSpecNode::describe($node)`; reject negative `extraCases` before loading the spec.

- [ ] **Step 5: Make existing cases represent either replay source**

In `GeneratedResponseCase.php`, change public `status` to `?int`, public `contentType` to `?string`, private `method` and `matchedPath` to `?string`, and add a final private optional `?string $schemaName = null` constructor parameter. Keep all existing parameter names and the existing `extraCases` default.

Branch in `replaySnippet()`:

```php
if ($this->schemaName !== null) {
    return sprintf(
        'OpenApiResponseExplorer::exploreComponent(%s, %s, seed: %d%s)->cases[%d]',
        var_export($this->specName, true),
        var_export($this->schemaName, true),
        $this->seed,
        $extraCases,
        $this->caseIndex,
    );
}
```

For the operation branch, guard impossible null metadata with `LogicException`, then leave the current replay format byte-for-byte unchanged. Do not alter `bodyAsObject()`, `bodyAsArray()`, `assertRoundTrip()`, or fidelity comparison.

- [ ] **Step 6: Run focused tests and verify GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseExplorerTest.php tests/Unit/Fuzz/GeneratedResponseCaseTest.php tests/Unit/Fuzz/GeneratedResponseCasesTest.php
```

Expected: all tests pass with no warnings. If a discriminator fixture exposes a generator limitation, stop and determine whether the fixture or implementation violated the existing supported subset; do not weaken the discriminator-context assertion.

- [ ] **Step 7: Format and commit the behavior slice**

Run:

```bash
composer cs
vendor/bin/phpunit tests/Unit/Fuzz/OpenApiResponseExplorerTest.php tests/Unit/Fuzz/GeneratedResponseCaseTest.php tests/Unit/Fuzz/GeneratedResponseCasesTest.php
git diff --check
```

Commit only Task 1 files:

```bash
git add src/Fuzz/OpenApiResponseExplorer.php src/Fuzz/GeneratedResponseCase.php tests/Unit/Fuzz/OpenApiResponseExplorerTest.php tests/fixtures/specs/sdk-roundtrip.json tests/fixtures/specs/dialect-draft07.json tests/fixtures/specs/component-malformed-components.json tests/fixtures/specs/component-malformed-schemas.json
git commit -m "feat(fuzz): explore named component schemas"
```

---

### Task 2: Public compatibility record and user documentation

**Files:**
- Modify: `tests/fixtures/compatibility/v2-public-api.json`
- Modify: `docs/api-reference.md`
- Modify: `docs/sdk-roundtrip.md`

**Interfaces:**
- Consumes: the Task 1 signature and nullable `GeneratedResponseCase::$status` / `$contentType`.
- Produces: a passing public API compatibility gate and documented plain-PHPUnit usage.

- [ ] **Step 1: Confirm the public API gate detects the intentional change**

Run:

```bash
vendor/bin/phpunit tests/Unit/Compatibility/PublicApiBaselineTest.php
```

Expected: `public_php_api_matches_the_v2_baseline` fails and its diff includes `exploreComponent`, nullable metadata, and the optional `schemaName` constructor parameter.

- [ ] **Step 2: Regenerate and inspect the compatibility inventory**

Run:

```bash
php scripts/export-public-api.php --write
git diff -- tests/fixtures/compatibility/v2-public-api.json
```

Accept only these changes: the new static `exploreComponent()` signature, `status` from `int` to `?int`, `contentType` from `string` to `?string`, constructor method/path nullable types, and the final optional nullable `schemaName`. Investigate any unrelated inventory change.

- [ ] **Step 3: Document named component exploration**

In `docs/api-reference.md`, extend the `OpenApiResponseExplorer` section with the exact call:

```php
$cases = OpenApiResponseExplorer::exploreComponent(
    specName: 'front',
    schemaName: 'IntrospectResponse',
    seed: 1,
    extraCases: 2,
);
```

State that it uses response conversion semantics, cases have null `status` and `contentType`, and unknown or unsupported schemas throw rather than returning an empty collection.

In `docs/sdk-roundtrip.md`, add a short “Named component models” section showing SDK decode and `assertRoundTrip()`. Replace the final sentence that calls named components a follow-up with text reserving only spec-wide mapping and exercise coverage for later phases.

- [ ] **Step 4: Verify docs-adjacent and compatibility checks**

Run:

```bash
vendor/bin/phpunit tests/Unit/Compatibility/PublicApiBaselineTest.php
composer cs-check
git diff --check
```

Expected: the compatibility test passes, both PHP-CS-Fixer configurations report clean, and no whitespace errors remain.

- [ ] **Step 5: Commit the compatibility and documentation slice**

```bash
git add tests/fixtures/compatibility/v2-public-api.json docs/api-reference.md docs/sdk-roundtrip.md
git commit -m "docs(fuzz): document component schema exploration"
```

---

### Task 3: Requirement audit and repository verification

**Files:**
- Review: `docs/superpowers/specs/2026-08-03-named-component-schema-explorer-design.md`
- Review: all Task 1 and Task 2 diffs

**Interfaces:**
- Consumes: the complete implementation and documentation.
- Produces: evidence for every issue #446 requirement and repository gate.

- [ ] **Step 1: Run narrow static analysis and unit tests**

```bash
composer stan
vendor/bin/phpunit --testsuite Unit
```

Expected: PHPStan reports no errors and the Unit suite has zero failures/errors/warnings.

- [ ] **Step 2: Run repository formatting and test gates**

```bash
composer cs-check
composer test
```

Expected: both commands exit 0. If environment constraints prevent a gate, record the exact command and reason rather than claiming it passed.

- [ ] **Step 3: Audit issue requirements against evidence**

Read issue #446 and the design again, then confirm:

- `exploreComponent()` is public and branch-complete for exact named components.
- conversion uses the loaded document's version/dialect, response context, and discriminator root/gate;
- existing case and collection types are reused with operation metadata absent;
- `assertRoundTrip()` behavior is unchanged and covered through a component case;
- unknown names and recursive/unsupported schemas throw loudly;
- API reference and SDK round-trip guide document the feature;
- the operation-based `explore()` tests still pass;
- unrelated worktree files are untouched.

- [ ] **Step 4: Inspect final repository state**

```bash
git status --short --branch
git log --oneline --decorate -5
git diff main...HEAD --stat
git diff main...HEAD --check
```

Expected: only issue #446 design, implementation, tests, fixtures, compatibility inventory, and docs are committed; unrelated pre-existing untracked files remain unmodified.
