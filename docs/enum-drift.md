# Enum drift detection

Runtime contract validation only sees enum values your tests actually return. Two failure modes slip through:

1. **PHP-only values** — a case is added to a PHP enum but the spec is not updated. Existing contract tests catch this only if a test exercises a code path that returns the new value. Untested paths drift silently.
2. **Spec-only values** — a value is added to the spec but no PHP case exists. Runtime validation can never observe this — the value cannot be produced by the implementation.

`EnumDriftAsserter` closes both holes by comparing PHP enum case values against the spec's `enum:` array statically.

- [`#[BoundToOpenApiEnum]` — bind a PHP enum to its spec file](#boundtoopenapienum--bind-a-php-enum-to-its-spec-file)
  - [Bundled-external enum sources (`enum_spec_base_path`)](#bundled-external-enum-sources-enum_spec_base_path)
- [`EnumDriftAsserter::assertNoDrift()`](#enumdriftasserterassertnodrift)
- [`AssertsNoEnumDrift` — PHPUnit trait](#assertsnoenumdrift--phpunit-trait)
- [`detectAll()` — inspection without throwing](#detectall--inspection-without-throwing)
- [Misconfiguration vs drift](#misconfiguration-vs-drift)
- [Auto-discovery via the PHPUnit extension](#auto-discovery-via-the-phpunit-extension)
- [Known limitations](#known-limitations)

## `#[BoundToOpenApiEnum]` — bind a PHP enum to its spec file

```php
use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('_shared/components/schemas/enums/NotificationCodeEnum.json')]
enum NotificationCodeEnum: string
{
    case StudioPaymentOld = 'studioPaymentOld';
    case StudioPaymentNew = 'studioPaymentNew';
    // ...
}
```

The path is resolved relative to the configured enum root (`OpenApiSpecLoader::getEnumBasePath()`), falling back to the spec root (`OpenApiSpecLoader::getBasePath()`) when the enum-specific root is unset. The bound file must contain an `enum:` array, e.g.:

```json
{
  "type": "string",
  "enum": ["studioPaymentOld", "studioPaymentNew"]
}
```

Both JSON and YAML bound files are supported. The binding path's extension — as written in the attribute — picks the parser: `.yaml` / `.yml` (case-insensitive) decode as YAML — through the same optional `symfony/yaml` dependency the spec loader uses — and every other path, including extension-less ones, is parsed as JSON. Binding a YAML file without `symfony/yaml` installed throws `EnumBindingException` with reason `YamlLibraryMissing`.

```php
#[BoundToOpenApiEnum('components/schemas/GrantType.yaml')]
enum GrantType: string { /* ... */ }
```

### Bundled-external enum sources (`enum_spec_base_path`)

Some projects bundle their OpenAPI documents (`front.json` / `admin.json` / …) into one directory while keeping individual `enum:` schemas elsewhere — so orval / Stoplight can `$ref` them without baking the values into the bundle. Concretely:

```
openapi/
├── _shared/
│   └── components/schemas/enums/
│       └── NotificationCodeEnum.json     ← per-enum source files
├── admin/   front/   store/              ← per-app sources
└── bundled/                              ← orval-readable aggregate
    ├── admin.json
    ├── front.json
    └── store.json
```

`spec_base_path` has to point at `openapi/bundled/` (that's where `{spec}.json` lookup for runtime contract tests lives), but the per-enum JSONs are deliberately *outside* that root. To bind a PHP enum to one without leaking the bundle directory choice into the attribute (`'../_shared/...'`), set `enum_spec_base_path` to a higher root used only for `#[BoundToOpenApiEnum]` resolution:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="enum_spec_base_path" value="openapi"/>
        <parameter name="specs" value="front,store,admin"/>
    </bootstrap>
</extensions>
```

```php
#[BoundToOpenApiEnum('_shared/components/schemas/enums/NotificationCodeEnum.json')]
enum NotificationCodeEnum: string
{
    // ...
}
```

When this parameter is omitted (the default), `#[BoundToOpenApiEnum]` paths resolve against `spec_base_path` exactly as before — single-root projects don't need to change anything. Setting it to the same value as `spec_base_path` is functionally equivalent (the opt-in branch additionally validates that the directory exists with `is_dir()` before resolving any binding, while the fallback branch defers that check to per-file `file_exists()` lookups).

The selected base path is a filesystem trust boundary. Attribute paths may use
nested directories, but a `..` segment, an absolute path, or a symlink whose
canonical target escapes the base is rejected as `SpecFileNotFound`. Existing
and missing outside targets use the same reason so bindings cannot probe their
existence. Configure `enum_spec_base_path` to the narrowest common directory
that intentionally contains every bound enum schema.

If `enum_spec_base_path` is configured but the directory does not exist, the asserter throws `EnumBindingException` with `EnumBindingReason::EnumBasePathNotFound` so a typo cannot silently fall through to a misleading `SpecFileNotFound` on every binding. From PHP, the manual `OpenApiSpecLoader::configure(basePath: …, enumBasePath: …)` call accepts the same parameter for non-PHPUnit setups (e.g. dedicated drift CI scripts).

## `EnumDriftAsserter::assertNoDrift()`

Call from any test (or from a dedicated drift-only test) to verify all bound enums match their spec files:

```php
use Studio\Gesso\Schema\EnumDriftAsserter;

public function test_no_enum_drift(): void
{
    EnumDriftAsserter::assertNoDrift([
        \App\Enums\NotificationCodeEnum::class,
        \App\Enums\ValidationErrorCodeEnum::class,
    ]);
}
```

When drift is detected the asserter throws `EnumDriftException` with a structured diagnostic:

```
[OpenAPI Enum Drift] FATAL: 1 enum binding(s) drift from spec.

  App\Enums\NotificationCodeEnum  ->  _shared/components/schemas/enums/NotificationCodeEnum.json
    PHP-only (1): "betaFeature"
    Spec-only (1): "deprecated"

Action: align the PHP enum cases with the spec, or update the spec's enum array.
```

To downgrade drift to a non-fatal warning (matches the `failOnWarning` ergonomic), pass `failOnDrift: false`:

```php
EnumDriftAsserter::assertNoDrift([NotificationCodeEnum::class], failOnDrift: false);
```

The asserter then fires one `E_USER_WARNING` containing the full drift report (every drifting binding aggregated into a single message) instead of throwing — `failOnWarning="true"` in `phpunit.xml` will still fail the run, but explicit warning suppressors will not. For programmatic access without the global error channel, use `detectAll()` (see below) and inspect the returned `EnumDriftReport[]` directly.

## `AssertsNoEnumDrift` — PHPUnit trait

`EnumDriftAsserter::assertNoDrift()` works on a throw-on-failure / return-void-on-success contract, so PHPUnit never sees the call as a real assertion. Under PHPUnit 13's default `beStrictAboutTestsThatDoNotTestAnything=true`, drift tests that pass get flagged risky:

```
There was 1 risky test:
1) Tests\Unit\EnumDriftTest::no_drift
This test did not perform any assertions
```

The `AssertsNoEnumDrift` trait wraps the same comparison and bumps PHPUnit's assertion counter on success — drop it into any `TestCase`:

```php
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\PHPUnit\AssertsNoEnumDrift;

class EnumDriftTest extends TestCase
{
    use AssertsNoEnumDrift;

    #[Test]
    public function no_drift(): void
    {
        $this->assertNoEnumDrift([
            \App\Enums\StatusEnum::class,
            \App\Enums\RoleEnum::class,
        ]);
    }
}
```

Failures throw `PHPUnit\Framework\AssertionFailedError` with the same `[OpenAPI Enum Drift] FATAL` block as the static asserter, routed through `Assert::fail()` so PHPUnit's diff-aware reporter picks it up. Stack frames inside this library are filtered out so the failure points at the consumer's test line.

Misconfiguration (`EnumBindingException` — missing `#[BoundToOpenApiEnum]`, spec file not found, malformed JSON/YAML, etc.) is **not** wrapped — it bubbles unchanged so the structured `$reason`/`$enumFqcn`/`$specPath` properties stay accessible to downstream tooling.

The static `EnumDriftAsserter::assertNoDrift()` is unchanged. Non-PHPUnit consumers (dedicated drift CI scripts that catch `EnumDriftException` directly) keep working as before.

## `detectAll()` — inspection without throwing

For dashboards or custom CI summaries that need every report (clean and drifting):

```php
$reports = EnumDriftAsserter::detectAll([NotificationCodeEnum::class]);
foreach ($reports as $report) {
    echo $report->enumFqcn, ' has drift: ', $report->hasDrift() ? 'yes' : 'no', "\n";
}
```

Each `EnumDriftReport` carries `enumFqcn`, `specPath`, `phpOnly`, and `specOnly` as readonly properties.

## Misconfiguration vs drift

`EnumBindingException` is thrown when the comparison cannot be performed at all — missing `#[BoundToOpenApiEnum]`, target is not a backed enum, spec file not found, malformed JSON/YAML, YAML binding without `symfony/yaml` installed, `enum` key missing or not an array, or an `enum` array entry is non-scalar (`null` / `bool` / nested arrays — backed PHP enums can only carry `string` or `int`). `$reason` carries an `EnumBindingReason` enum so you can branch programmatically. These errors fire regardless of `failOnDrift` — they are setup mistakes, not drift signals.

## Auto-discovery via the PHPUnit extension

Manually enumerating every bound enum in a test method gets stale fast — a new `#[BoundToOpenApiEnum]` added by another developer slips by silently until someone remembers to update the list. The PHPUnit extension can scan one or more PSR-4 namespace prefixes at bootstrap and run drift checks before any test executes.

Add the opt-in parameters to your `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/dist"/>
        <parameter name="enum_drift_enabled" value="true"/>
        <parameter name="enum_drift_scan_namespaces" value="App\Enums,App\Domain\Enums"/>
        <parameter name="enum_drift_fail_on_drift" value="true"/>
    </bootstrap>
</extensions>
```

| Parameter | Default | Behaviour |
|---|---|---|
| `enum_drift_enabled` | `false` | Master opt-in. Empty value (`<parameter name="enum_drift_enabled"/>`) is also treated as `true`, mirroring `min_coverage_strict`. |
| `enum_drift_scan_namespaces` | _none_ | Comma-separated PSR-4 namespace prefixes (whitespace tolerated). Each prefix must match — directly or as a sub-namespace of — an entry in your `composer.json` `autoload.psr-4` map. |
| `enum_drift_fail_on_drift` | `true` | `true` aborts the run with a `[OpenAPI Enum Drift] FATAL` block on stderr (and `GITHUB_STEP_SUMMARY` when set). `false` emits a `WARNING` block but lets PHPUnit continue. |
| `enum_spec_base_path` | _none_ | Optional secondary root used only for `#[BoundToOpenApiEnum]` path resolution. Set this when per-enum JSONs live outside `spec_base_path` (e.g. `openapi/_shared/...` while bundles live in `openapi/bundled/`). Relative values resolve against `getcwd()`. See [Bundled-external enum sources](#bundled-external-enum-sources-enum_spec_base_path) for the full layout. |
| _misconfiguration_ | _n/a_ | No namespaces configured, an unresolvable namespace prefix, a missing Composer `ClassLoader`, an `enum_spec_base_path` that does not point at a directory, or any `EnumBindingException` raised by a discovered enum **always** produces a FATAL exit regardless of `enum_drift_fail_on_drift`. These are setup errors and would otherwise hide a real drift signal. |

Discovery merges results from Composer's classmap (`getClassMap()`) and a recursive scan of each PSR-4-registered directory, deduplicating across both sources. Production deployments using `--optimize-autoloader` or `--classmap-authoritative` are covered by the classmap pass; default dev installs are covered by the PSR-4 directory walk. Only backed enums carrying `#[BoundToOpenApiEnum]` are passed to `EnumDriftAsserter`; pure enums, traits, abstract classes, and unattributed classes in the same directory are silently skipped.

A strict-mode (default) drift run produces the same diagnostic block documented above:

```
[OpenAPI Enum Drift] FATAL: 1 enum binding(s) drift from spec.

  App\Enums\NotificationCodeEnum  ->  _shared/components/schemas/enums/NotificationCodeEnum.json
    PHP-only (1): "betaFeature"
    Spec-only (1): "deprecated"

Action: align the PHP enum cases with the spec, or update the spec's enum array.
```

In `enum_drift_fail_on_drift="false"` mode the body is identical except for the severity prefix:

```
[OpenAPI Enum Drift] WARNING: 1 enum binding(s) drift from spec.

  App\Enums\NotificationCodeEnum  ->  _shared/components/schemas/enums/NotificationCodeEnum.json
    PHP-only (1): "betaFeature"
    Spec-only (1): "deprecated"

Action: align the PHP enum cases with the spec, or update the spec's enum array.
```

PHPUnit exits normally in `WARNING` mode. **`failOnWarning="true"` and `failOnPhpunitWarning="true"` do _not_ catch this block** — both flags only fire for warnings raised during test execution, not bootstrap-time stderr writes from an extension. If you need lenient drift to fail the build, gate on the stderr text in CI directly (e.g. `phpunit ... 2>&1 | tee out && ! grep -q '\[OpenAPI Enum Drift\] WARNING' out`).

If `enum_drift_scan_namespaces` resolves but no `#[BoundToOpenApiEnum]`-attributed enums are found, the extension emits one `[OpenAPI Enum Drift] NOTE:` line to stderr and continues. This surfaces typo'd namespaces ("`App\Enum`" vs "`App\Enums`") without failing codebases that are mid-migration.

## Known limitations

- **No `$ref` traversal on the bound file.** Unlike `OpenApiSpecLoader::load()`, the asserter does not resolve `$ref` inside the bound file. Bind to the leaf file containing the literal `enum:` array.
- **`oneOf` enum unions** (e.g., `code: oneOf: [CommonCode, AdminCode]`) are not yet auto-resolved. Bind each PHP enum to its leaf file directly.
- **`x-enum-varnames` / `x-enum-descriptions`** are not validated. Only the `enum` value array is compared.
