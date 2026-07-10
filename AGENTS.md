# Repository guide for coding agents

This file is the shared source of repository-specific guidance. Keep it factual,
tool-agnostic, and aligned with the code and CI. Put agent-specific settings in
that agent's local configuration instead.

## Project scope

`studio-design/openapi-contract-testing` is a PHP 8.2+ test-time library for
validating requests and responses against OpenAPI 3.0, 3.1, and 3.2. Its core is
framework-agnostic; adapters support PHPUnit, Laravel, Symfony, and Pest. It also
provides endpoint/response coverage, schema-driven exploration, enum-drift and
strict-required checks, a spec doctor CLI, parallel coverage merging, and Laravel
route/spec parity.

Runtime dependencies are deliberately small. Framework, YAML, remote-reference,
faker, and Pest packages are optional; see `composer.json` and `docs/setup.md`.
Do not add a production dependency without first justifying the public and
maintenance cost.

## Repository map

- `src/OpenApiRequestValidator.php`, `src/OpenApiResponseValidator.php`: public
  framework-independent validation entry points.
- `src/Spec/`: spec loading, reference resolution, version/dialect selection,
  path matching, operation lookup, and schema conversion.
- `src/Validation/`: request/response validators, strict-required analysis, and
  shared validation boundaries.
- `src/Coverage/`, `src/PHPUnit/`: coverage state/renderers/merge protocol and the
  PHPUnit extension.
- `src/Laravel/`, `src/Symfony/`, `src/Pest/`: integration layers. Keep policy in
  the core when it is not framework-specific.
- `src/Fuzz/`, `src/Schema/`: generated exploration cases and enum-drift checks.
- `src/Internal/`: implementation details; do not expose these as public API.
- `bin/openapi-contract`, `bin/openapi-coverage-merge`: Composer-installed CLIs.
- `tests/Unit`, `tests/Integration`, `tests/fixtures`: tests and representative
  OpenAPI fixtures. Pest integration tests run through a separate command.
- `docs/`: focused guides. Start with `docs/setup.md`,
  `docs/supported-features.md`, `docs/coverage.md`, and `docs/versioning.md`.

## Architecture and invariants

The normal validation path is:

1. `OpenApiSpecLoader` decodes JSON or optional YAML, resolves internal and local
   file references, and caches named specs. HTTP(S) references are opt-in.
2. `OpenApiVersion` and `OpenApiSchemaDialect` select OpenAPI and JSON Schema
   behavior. OpenAPI 3.0 uses the Draft 07 compatibility pipeline; 3.1/3.2 retain
   native JSON Schema 2020-12 semantics where supported by opis.
3. `OpenApiPathMatcher` normalizes and matches request paths, then
   `OpenApiOperationResolver` selects the operation. OpenAPI 3.2 custom
   `additionalOperations` are case-sensitive; do not normalize them like fixed
   HTTP fields.
4. Request validation composes path, query, header, security, and body results.
   Response validation resolves status/content type and validates headers/body.
   Both return `OpenApiValidationResult` with Success, Failure, or Skipped.
5. Framework adapters turn results into test assertions and record observations
   in `OpenApiCoverageTracker`; the PHPUnit subscriber renders reports and gates.

Keep doctor diagnostics consistent with runtime validation: malformed spec nodes
must not pass one path and fail the other. Preserve discriminator enforcement and
the selected schema dialect when introducing alternate validation entry points.

Coverage is measured at `(method, path, status, content-type)` granularity. State
export/import and sidecar formats support parallel runs; treat versioned wire and
JSON output formats as compatibility surfaces.

Public symbols not marked `@internal`, CLI flags/exit codes, PHPUnit extension
parameters, versioned Laravel route-parity JSON output, warning category prefixes,
and documented wire formats are covered by the v1.x compatibility policy. Read
`docs/versioning.md` before changing any of them. Do not assert exact validator
error prose unless the wording itself is the contract.

## Setup and commands

```bash
composer install

# Narrow tests first
vendor/bin/phpunit tests/Unit/Spec/OpenApiSchemaConverterTest.php
vendor/bin/phpunit --filter test_method_name
vendor/bin/phpunit --testsuite Unit

# Repository checks
composer test
composer stan
composer cs-check
composer ci

# Dependency metadata checks when composer.json changes
composer validate --strict
composer audit --abandoned=fail

# Apply formatting (rewrites files)
composer cs
```

`composer ci` runs both PHP-CS-Fixer configurations, PHPStan, and PHPUnit. CI also
tests PHP 8.2-8.4 with PHPUnit 11-13, lowest dependencies, the optional Pest
integration and example, Composer validation/audit, and generated Markdown lint.
When changing one of those surfaces, run its focused check and rely on the matrix
for combinations unavailable locally. Pest tests require Pest 3 and PHPUnit 11;
follow `composer.json` and `examples/pest/README.md` rather than adding Pest to the
normal dependency set.

## Implementation conventions

- Follow PSR-4 under `Studio\OpenApiContractTesting\` and start PHP files with
  `declare(strict_types=1);`.
- Keep code compatible with the PHP 8.2 floor.
- PHP-CS-Fixer is authoritative: PER-CS2.0, strict comparisons, explicit global
  function/constant imports, ordered imports/elements, and snake_case PHPUnit
  test methods. Pest callbacks are the intentional exception to `static_lambda`.
- Mark new classes `final` unless extension is intentional. Prefer readonly DTOs
  or accessors to public mutable properties.
- Mark implementation-only public symbols `@internal` with the reason. PHPStan
  level 6 plus bleeding-edge internal-boundary rules analyzes `src/` and `tests/`;
  exclusions in `phpstan.neon.dist` are intentional compatibility boundaries.
- Add a regression test for observable behavior changes. Put reusable specs in
  `tests/fixtures/specs`; cover both valid behavior and loud malformed-input
  behavior when changing spec traversal.
- Reset process-wide loaders, trackers, injected instances, and warning-dedup
  state in tests that mutate them. Do not let test order carry state.
- Preserve optional-dependency behavior: guard integration classes/functions and
  keep the default PHPUnit matrix free of Pest.
- Keep changes scoped. Prefer extending existing resolvers, validators, result
  objects, and renderers over duplicating their rules in an adapter or CLI.

## Documentation and contribution workflow

Update user documentation for public behavior and configuration changes. Link to
focused docs instead of expanding the README indefinitely. For OpenAPI, JSON
Schema, PHPUnit, or framework semantics, verify current official documentation;
add fixtures that pin the interpretation.

Run the narrowest relevant checks before the full applicable suite and report any
checks that could not run. Preserve unrelated worktree changes. Never commit
secrets, generated caches, local agent settings, or dependency directories.

Branches normally start from `main`. PR titles use Conventional Commits with a
lowercase subject, for example `fix(spec): resolve implicit discriminator maps`.
The repository squash-merges PRs, so the PR title becomes the canonical commit.

Releases are managed by release-please. Do not edit `CHANGELOG.md`, create `v*`
tags, or modify the release manifest during feature/fix work. See
`CONTRIBUTING.md` for release and recovery procedures, and `SECURITY.md` for
private vulnerability reporting.
