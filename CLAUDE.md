# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install dependencies
composer install

# Run all tests
vendor/bin/phpunit

# Run a single test file
vendor/bin/phpunit tests/Unit/OpenApiSchemaConverterTest.php

# Run a single test method
vendor/bin/phpunit --filter test_method_name

# Run by test suite (Unit or Integration)
vendor/bin/phpunit --testsuite Unit

# Static analysis (level 6)
vendor/bin/phpstan analyse

# Code style fix
vendor/bin/php-cs-fixer fix

# Code style check (dry run)
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Architecture

This is a PHP library (`studio-design/openapi-contract-testing`) that validates API responses against OpenAPI 3.0/3.1/3.2 specs during PHPUnit tests and tracks endpoint coverage.

### Validation Flow

1. **`OpenApiSpecLoader`** — Static singleton that loads and caches bundled JSON spec files from a configured base path. Specs are referenced by name (e.g., `"front"` loads `front.json`).
2. **`OpenApiPathMatcher`** — Matches actual request paths (e.g., `/v1/pets/123`) against spec paths with parameters (e.g., `/v1/pets/{petId}`). Handles prefix stripping (e.g., `/api`) and sorts by literal segment count for most-specific-first matching.
3. **`OpenApiSchemaConverter`** — Converts OpenAPI schemas to JSON Schema Draft 07 (required by `opis/json-schema`). Handles OAS 3.0 `nullable` → type arrays, OAS 3.1 `prefixItems` → `items`, and removes OAS-only / Draft 2020-12 keys.
4. **`OpenApiResponseValidator`** — Orchestrates the above: loads spec → matches path → converts schema → validates response body via `opis/json-schema`. Returns `OpenApiValidationResult`.

### PHPUnit Extension & Coverage

- **`OpenApiCoverageExtension`** — PHPUnit extension configured in `phpunit.xml`. Reads `spec_base_path`, `strip_prefixes`, `specs`, and `output_file` parameters. Registers an `ExecutionFinishedSubscriber` that prints a coverage report to stdout and optionally writes Markdown to a file and/or GitHub Step Summary.
- **`OpenApiCoverageTracker`** — Static tracker that records validated endpoints during test runs and computes coverage against the full spec.
- **`MarkdownCoverageRenderer`** — Pure function class that generates a Markdown coverage report from computed results. Used by the extension for `output_file` and `GITHUB_STEP_SUMMARY` output.

### Laravel Integration

- **`ValidatesOpenApiSchema`** trait — Used in Laravel test cases. Provides `assertResponseMatchesOpenApiSchema()` which auto-resolves method/path from the current request and records coverage. The default spec name is read from `config('openapi-contract-testing.default_spec')`; override `openApiSpec(): string` per-test-class if needed.
- **`OpenApiContractTestingServiceProvider`** — Auto-discovered service provider that registers and publishes the `openapi-contract-testing` config file (`default_spec` key).

### Key Enums

- **`OpenApiVersion`** (`V3_0` | `V3_1`) — Auto-detected from spec's `openapi` field.
- **`HttpMethod`** — GET, POST, PUT, PATCH, DELETE.
- **`OpenApiValidationOutcome`** (`Success` | `Failure` | `Skipped`) — Tri-state outcome of a validation run, exposed via `OpenApiValidationResult::outcome()`. `isValid()` / `isSkipped()` are bool wrappers over this enum.

## Code Style

Enforced by PHP-CS-Fixer (`.php-cs-fixer.dist.php`):
- `declare(strict_types=1)` in all files
- PER-CS2.0 base ruleset with `@PHP8x2Migration`
- PHPUnit test methods use `snake_case`
- Strict comparisons (`===`/`!==`) enforced
- Explicit `use function` / `use const` imports (global namespace import)
- Class elements ordered: traits → constants → static properties → properties → constructor → public static methods → public → protected → private

## CI Matrix

Tests run across PHP 8.2–8.4 with PHPUnit 11–13. PHP 8.2 + PHPUnit 12 is excluded (incompatible). PHPStan runs on PHP 8.3, PHP-CS-Fixer on PHP 8.2.
