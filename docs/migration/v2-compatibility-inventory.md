# Gesso v2 compatibility inventory

This document records the v1.9.0 compatibility surface that must be considered
when migrating the project to the Gesso v2 identity. It is an implementation
checklist for [ADR 0001](../adr/0001-gesso-v2-identity.md), not a promise that
every legacy identifier will remain available in v2.

Baseline:

- release: `v1.9.0`
- commit: `57920818e5677835ca681a499a8140ea651ea060`
- Composer package: `studio-design/openapi-contract-testing`
- root namespace: `Studio\OpenApiContractTesting\`

## Inventory rules

- A PHP type is in the v1 public API when its declaration is not marked
  `@internal`.
- Public members marked `@internal` are excluded even when their declaring type
  is public.
- Public method signatures, public constant values, enum cases, attributes,
  and trait methods are part of the source contract.
- CLI binaries, PHPUnit parameters, Laravel configuration, environment
  variables, warning prefixes, and documented machine-readable formats require
  separate tests because PHP API tools cannot detect changes to them.
- Validator error prose is not stable, but structured categories and documented
  warning prefixes are.

Reflection against the optimized v1.9 autoload map finds 65 non-internal PHP
types: 46 classes, 12 enums, and 7 traits. Member signatures remain
authoritative in the v1.9 source; this list prevents a type from disappearing
during the namespace migration.

The machine-readable baseline lives at
[`tests/fixtures/compatibility/v1.9-public-api.json`](../../tests/fixtures/compatibility/v1.9-public-api.json).
`PublicApiBaselineTest` compares it with the current source. After an
intentional, documented API change, regenerate it with:

```bash
php scripts/export-public-api.php --write
```

The snapshot includes type kind/modifiers, parent and directly introduced
interfaces/traits, attributes, enum backing types and cases, public constants,
public properties, and declared public method signatures. Declarations or
members marked `@internal` are excluded.

## Composer and autoload identity

| Surface | v1.9 contract | v2 migration responsibility |
| --- | --- | --- |
| Package | `studio-design/openapi-contract-testing` | Explicit root requirement migration to `studio-design/gesso` |
| PSR-4 | `Studio\OpenApiContractTesting\` → `src/` | Map `Studio\Gesso\` and verify optimized autoloading |
| Autoload file | `src/Pest/Autoload.php` | Rename imports and preserve no-Pest guard behavior |
| Binaries | `bin/openapi-contract`, `bin/openapi-coverage-merge`; v1.10 adds `bin/gesso` | Remove the legacy binaries and retain the unified `gesso` command surface |
| Laravel discovery | `Studio\OpenApiContractTesting\Laravel\OpenApiContractTestingServiceProvider` | Point discovery to the Gesso provider |
| PHP requirement | `^8.2` | Raise to `^8.3`; PHP 8.2 reaches security EOL at the end of 2026 |
| PHPUnit requirement | `^11.0 || ^12.0 || ^13.0` | Support `^12.0 || ^13.0`; drop PHPUnit 11 after its bug-fix support ended |
| Opis requirement | `opis/json-schema:^2.6` | Keep independent from the identity migration |
| PSR requirements | `psr/http-client:^1.0`, `psr/http-factory:^1.0`, `psr/http-message:^1.0 || ^2.0` | Preserve unless separately justified |

Packagist migration must be tested with a clean project. The new package must
not declare an unconstrained replacement that silently satisfies a dependency
requiring the old package's `^1` API.

## PHP public types

Every type below maps by replacing the prefix
`Studio\OpenApiContractTesting\` with `Studio\Gesso\`. Renaming or removing
the domain-level short names is not part of the identity migration.

### Core and attributes

```text
Studio\OpenApiContractTesting\DecodedBody
Studio\OpenApiContractTesting\HttpMethod
Studio\OpenApiContractTesting\OpenApiRequestValidator
Studio\OpenApiContractTesting\OpenApiResponseValidator
Studio\OpenApiContractTesting\OpenApiValidationOutcome
Studio\OpenApiContractTesting\OpenApiValidationResult
Studio\OpenApiContractTesting\OpenApiVersion
Studio\OpenApiContractTesting\SchemaContext
Studio\OpenApiContractTesting\SkipOpenApiResolver
Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum
Studio\OpenApiContractTesting\Attribute\OpenApiSpec
Studio\OpenApiContractTesting\Attribute\SkipOpenApi
```

`OpenApiValidationResult` additionally freezes its success, failure, and
skipped factories and these accessors: `outcome()`, `isValid()`, `isSkipped()`,
`errors()`, `errorMessage()`, `matchedPath()`, `skipReason()`,
`matchedStatusCode()`, and `matchedContentType()`.

### Exceptions

```text
Studio\OpenApiContractTesting\Exception\EnumBindingException
Studio\OpenApiContractTesting\Exception\EnumBindingReason
Studio\OpenApiContractTesting\Exception\EnumDriftException
Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException
Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason
Studio\OpenApiContractTesting\Exception\SpecFileNotFoundException
Studio\OpenApiContractTesting\Exception\StrictRequiredDriftException
```

The cases of `EnumBindingReason` and `InvalidOpenApiSpecReason`, including
deprecated cases, must be included in the PHP API snapshot.

### Spec loading

```text
Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader
Studio\OpenApiContractTesting\Spec\OpenApiSpecResolver
```

The loader's static configuration, base paths, strip prefixes, local/remote
reference inputs, cache behavior exposed through non-internal methods, and
exception taxonomy are observable contracts.

### Coverage

```text
Studio\OpenApiContractTesting\Coverage\ConsoleCoverageRenderer
Studio\OpenApiContractTesting\Coverage\CoverageSidecarEnvelope
Studio\OpenApiContractTesting\Coverage\CoverageSidecarReader
Studio\OpenApiContractTesting\Coverage\CoverageSidecarWriter
Studio\OpenApiContractTesting\Coverage\CoverageThresholdEvaluator
Studio\OpenApiContractTesting\Coverage\EndpointCoverageState
Studio\OpenApiContractTesting\Coverage\HtmlCoverageRenderer
Studio\OpenApiContractTesting\Coverage\InvalidCoverageOutputPathException
Studio\OpenApiContractTesting\Coverage\InvalidThresholdConfigurationException
Studio\OpenApiContractTesting\Coverage\JUnitCoverageRenderer
Studio\OpenApiContractTesting\Coverage\JsonCoverageRenderer
Studio\OpenApiContractTesting\Coverage\MarkdownCoverageRenderer
Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker
Studio\OpenApiContractTesting\Coverage\ResponseCoverageState
```

The inventory must preserve the enum values, renderer entry points, threshold
semantics, `OpenApiCoverageTracker::ANY_CONTENT_TYPE`, and all non-internal
tracker methods. The sidecar constants and filename conventions are separately
listed under wire formats.

### Schema and strict-required APIs

```text
Studio\OpenApiContractTesting\Schema\EnumDriftAsserter
Studio\OpenApiContractTesting\Schema\EnumDriftReport
Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredAsserter
Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode
Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallMode
Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredReport
Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker
```

Modes and enum values are stable inputs. Tracker import/export methods are
marked internal at member level, but their versioned payload is consumed by the
supported parallel merge workflow and therefore remains a migration concern.

### Fuzzing and exploration

```text
Studio\OpenApiContractTesting\Fuzz\ExplorationCaseKind
Studio\OpenApiContractTesting\Fuzz\ExplorationCases
Studio\OpenApiContractTesting\Fuzz\ExplorationSkip
Studio\OpenApiContractTesting\Fuzz\ExploredCase
Studio\OpenApiContractTesting\Fuzz\ExploredOperation
Studio\OpenApiContractTesting\Fuzz\FailureReducer
Studio\OpenApiContractTesting\Fuzz\OpenApiEndpointExplorer
Studio\OpenApiContractTesting\Fuzz\OpenApiSpecExploration
Studio\OpenApiContractTesting\Fuzz\OpenApiSpecExplorer
Studio\OpenApiContractTesting\Fuzz\SpecExplorationSummary
```

This includes constructor shapes, readonly DTO fields, fluent filter and hook
methods, replay/cURL snippets, replay tokens, iterator behavior, and negative
case configuration introduced through v1.9.

### PHPUnit and Pest

```text
Studio\OpenApiContractTesting\PHPUnit\AssertsNoEnumDrift
Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput
Studio\OpenApiContractTesting\PHPUnit\InvalidStrictRequiredConfigurationException
Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension
Studio\OpenApiContractTesting\Pest\Expectations
```

The Pest autoload file registers these expectation names and argument shapes:

- `toMatchOpenApiResponseSchema($spec, $method, $path, $skipResponseCodes)`
- `toMatchOpenApiRequestSchema($spec, $method, $path)`

They remain dormant when Pest is unavailable.

### Framework and PSR-7 adapters

```text
Studio\OpenApiContractTesting\Laravel\Commands\OpenApiRoutesCommand
Studio\OpenApiContractTesting\Laravel\ExploresOpenApiEndpoint
Studio\OpenApiContractTesting\Laravel\OpenApiContractTestingServiceProvider
Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema
Studio\OpenApiContractTesting\Psr7\OpenApiAssertions
Studio\OpenApiContractTesting\Psr7\OpenApiPsr7ValidationResult
Studio\OpenApiContractTesting\Psr7\OpenApiPsr7Validator
Studio\OpenApiContractTesting\Symfony\OpenApiAssertions
```

Trait methods, explicit-spec precedence, validation defaults, assertion
behavior, PSR-7 stream handling, and automatic coverage recording require
consumer-level fixtures rather than namespace-only checks.

## PHPUnit extension configuration

The extension FQCN and every accepted parameter name are public configuration.
Defaults shown here are the missing-parameter behavior.

| Parameter | v1.9 default or domain |
| --- | --- |
| `spec_base_path` | absent; required for named spec loading in normal use |
| `enum_spec_base_path` | absent; falls back to the spec base path |
| `strip_prefixes` | empty list |
| `specs` | `front` |
| `output_file` | absent; Markdown file not written |
| `junit_output` | absent |
| `json_output` | absent |
| `html_output` | absent |
| `console_output` | `default`; also `all`, `uncovered_only`, `active_only` |
| `sidecar_dir` | system temp plus `openapi-coverage-sidecars` |
| `min_endpoint_coverage` | no gate; otherwise 0–100 |
| `min_response_coverage` | no gate; otherwise 0–100 |
| `min_coverage_strict` | `false` |
| `default_testsuite_as_full` | `false` |
| `strict_required` | `off`; also `warn`, `fail` |
| `strict_required_per_call` | `off`; also `warn` |
| `enforce_discriminator` | `true` |
| `enum_drift_enabled` | `false` |
| `enum_drift_scan_namespaces` | empty; required when enum drift is enabled |
| `enum_drift_fail_on_drift` | `true` |

Relative paths resolve from the current working directory. Empty values,
boolean-compatible strings, invalid thresholds, and invalid mode handling are
observable and already covered by extension tests.

Environment inputs:

- `OPENAPI_CONSOLE_OUTPUT` overrides `console_output`.
- `GITHUB_STEP_SUMMARY` enables Markdown summary output.
- `TEST_TOKEN` selects the paratest worker sidecar path and suppresses final
  report writes from workers.

## Laravel configuration and command

The v1 service provider merges and publishes configuration under
`openapi-contract-testing`; the publish tag and destination filename use the
same string.

| Config key | v1.9 default |
| --- | --- |
| `default_spec` | empty string |
| `spec_base_path` | `openapi` |
| `strip_prefixes` | empty list |
| `max_errors` | `20` |
| `enforce_discriminator` | `true` |
| `auto_assert` | `false` |
| `auto_validate_request` | `false` |
| `auto_inject_dummy_credentials` | `false` |
| `auto_inject_dummy_bearer` | `false` |
| `skip_response_codes` | `['5\\d\\d']` |
| `skip_request_validation_response_codes` | `['422', '400']` |

The `openapi:routes` command accepts `--spec`, `--prefix`, `--middleware`,
`--domain`, `--exclude-route`, `--format=text|json`,
`--fail-on-undocumented`, and `--fail-on-unimplemented`. It uses Laravel's
standard success (`0`), failure (`1`), and invalid usage (`2`) exit codes.

Migration status: unchanged. The complete v1.9 configuration defaults,
provider configuration key/publish tag/destination, and versioned route-parity
JSON are captured under `tests/fixtures/compatibility/` and exercised through
Laravel consumer-level integration tests. Text output remains covered
semantically because Laravel owns its cross-version console table rendering.

V1.10 intentionally does not register a `gesso` configuration alias. At the v2
package boundary, consumers rename the published file and direct configuration
lookups. The v2 provider owns only `gesso`; it rejects either a legacy-only
`openapi-contract-testing` key or a dual-key configuration with an actionable
exception instead of selecting precedence. The implementation gate includes
provider tests for the new defaults, overrides, publish target, both rejection
paths, and stale cached legacy configuration. See the
[Laravel configuration transition policy](../adr/0001-gesso-v2-identity.md#laravel-configuration-transition-policy).

## CLI contracts

V1.10 adds the forward-compatible `gesso` entry point. It dispatches
`gesso doctor` and `gesso coverage:merge` to the same implementations while
rendering the new invocation names in help and usage output. The legacy
binaries remain the v1.9-compatible paths until the v1 line reaches EOL.

### Doctor

Executable and command: `openapi-contract doctor`.

Accepted options:

- repeated `--spec=<path[,path]>`;
- repeated `--strip-prefix=<prefix>`;
- `--format=text|json`;
- `--allow-remote-refs`;
- `--phpunit-snippet`;
- `--help` / `-h`.

Exit codes are `0` for a usable contract, `1` for diagnostic failure, and `2`
for invalid usage. JSON uses `schemaVersion: 1`; issue fields are `severity`,
`category`, `spec`, `message`, and nullable `suggestion`.

Migration status: unchanged. The exact v1.9 help and invalid-usage output are
captured under `tests/fixtures/compatibility/` and exercised through the
installed-style binary integration test, including exit code and output
channel.

### Coverage merge

Executable: `openapi-coverage-merge`.

Accepted options:

- `--spec-base-path`, `--specs`, `--strip-prefixes`, `--sidecar-dir`;
- `--output-file`, `--junit-output`, `--json-output`, `--html-output`;
- `--github-step-summary`, `--console-output`;
- `--min-endpoint-coverage`, `--min-response-coverage`;
- `--min-coverage-strict`, `--strict-required`;
- `--no-cleanup`, `--help`, and `-h`.

Exit codes are `0` for success or a warn-only result, `1` for data, gate, or
write failure, and `2` for invalid configuration or usage.

Migration status: unchanged. The exact v1.9 help and invalid-usage output are
captured under `tests/fixtures/compatibility/` and exercised through the
installed-style binary integration test, including exit code and output
channel.

## Versioned and machine-readable formats

| Format | v1.9 version | Compatibility responsibility |
| --- | ---: | --- |
| Coverage JSON report | `schema_version: 1` | V2 emits schema 2 because the documented fixed tool identity changes; all other fields retain their v1 meanings |
| Coverage JSON tool identity | `studio-design/openapi-contract-testing` | V2 emits only `studio-design/gesso` and pins it in a golden fixture |
| Coverage tracker state | `version: 1` | V2 retains version 1 and accepts supported v1 payloads |
| Strict-required tracker state | `version: 2` | V2 retains version 2 and preserves the documented import boundary |
| Sidecar envelope | `envelopeVersion: 2` | V2 retains version 2 and continues accepting legacy bare coverage v1 payloads |
| Laravel route parity JSON | `schema_version: 1` | V2 retains version 1 because neither identity nor shape changes |
| Doctor JSON | `schemaVersion: 1` | V2 retains version 1 because neither identity nor shape changes |
| Sidecar filenames | `part-*.json`, failure marker `failed-*` | Keep reader compatibility during mixed runs |

Coverage JSON v1 documents `generated_at`, `tool`, `aggregate`, and `specs`.
The per-spec contract includes aggregate counts and endpoint rows with response
states and unexpected observations. Laravel route parity JSON includes `specs`,
`summary`, `matched`, `documented_but_not_registered`,
`registered_but_undocumented`, `ambiguous`, and `unsupported`.

The sidecar compatibility boundary is now explicit in the
[versioning policy](../versioning.md#versioned-sidecar-compatibility): the PHP
import/export methods are internal, but versioned payloads accepted by the
released merge CLI are compatibility inputs. A newer reader preserves the
documented older inputs, while unknown or lossy formats fail loudly.

Migration status: the exact v1.9 coverage JSON report and sidecar envelope are
captured under `tests/fixtures/compatibility/`. The sidecar fixture pins coverage
tracker state v1 and strict-required tracker state v2, and consumer-style tests
verify both the envelope reader and the legacy bare-coverage reader path.

V2 decision: only coverage JSON advances its schema version. Its documented
`tool.name` behaves as a fixed value, so changing it to `studio-design/gesso` is
an incompatible contract change even though the surrounding object shape stays
the same. Format versions are otherwise independent of the Composer major:
Doctor JSON, route parity JSON, the sidecar envelope, and both tracker states do
not carry the legacy identity and therefore retain their existing versions.

## Diagnostic categories and embedded identifiers

The v1 policy explicitly protects these `E_USER_WARNING` category families:

- `[security]`
- `[OpenAPI Schema]`
- `[OpenAPI 3.2 querystring]`
- `[OpenAPI 3.2 $self]`
- `[OpenAPI 3.2 discriminator]`

Other stable operational prefixes that require rename or parity tests include:

- `[OpenAPI Coverage]`
- `[OpenAPI Doctor]`
- `[OpenAPI Enum Drift]`
- `[OpenAPI Strict Required]`
- `[OpenAPI Strict Required per-call]`
- `[openapi-contract-testing]` for the Laravel deprecation channel

The OpenAPI vendor extension
`x-studio-openapi-contract-testing-implicit-schema-name` is embedded in spec
input rather than PHP source. Its Gesso replacement and any dual-read period
must be decided explicitly.

## v2 migration verification matrix

| Consumer | Old fixture | New fixture | Mixed/negative fixture |
| --- | --- | --- | --- |
| Composer | old package `^1.10` | `studio-design/gesso:^2` | dual-install conflict and transitive old `^1` requirement |
| PHP | old namespace consumer | Gesso namespace consumer | optimized autoload, reflection, serialization, attributes, traits, enums |
| PHPUnit | old Extension FQCN/XML | Gesso FQCN/XML | every invalid/empty parameter behavior |
| Laravel | published old config/provider | Gesso config/provider | both configs present with conflicting values |
| Pest | old trait wiring | Gesso trait wiring | Pest absent and partially available |
| PSR-7/Symfony | old imports | Gesso imports | equivalent exchange produces equivalent result/coverage |
| CLI | old commands plus v1.10 `gesso` aliases and golden output | `gesso` only | flags, exit codes, stdout/stderr parity; legacy binaries absent |
| Coverage | v1 JSON and sidecar | v2 output | v1 worker payload merged by v2 reader |
| Doctor/routes | schema v1 consumers | v2 identity | incompatible field change requires version bump |

The PHP row now has an executable
[namespace compatibility spike](v2-namespace-compatibility-spike.md). It proves
that a lazy alias loader works under Composer's authoritative classmap and pins
the observable reflection and serialization limits. It does not yet add a shim
to the released package.

## Change-control checklist

For every v2 identity PR:

1. Mark each affected row in this inventory as unchanged, renamed, shimmed, or
   intentionally removed.
2. Add or update the corresponding consumer/golden fixture.
3. Keep domain behavior unchanged unless the PR has a separate behavioral
   rationale and migration note.
4. Search for both `openapi-contract-testing` and
   `Studio\\OpenApiContractTesting` after the change; any remaining occurrence
   must be a documented compatibility input, migration example, or historical
   reference.
5. Update `UPGRADING.md` in the same PR when a consumer action changes.
