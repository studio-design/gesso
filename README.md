# OpenAPI Contract Testing for PHPUnit

[![CI](https://github.com/studio-design/openapi-contract-testing/actions/workflows/ci.yml/badge.svg)](https://github.com/studio-design/openapi-contract-testing/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/studio-design/openapi-contract-testing/v)](https://packagist.org/packages/studio-design/openapi-contract-testing)
[![Total Downloads](https://poser.pugx.org/studio-design/openapi-contract-testing/downloads)](https://packagist.org/packages/studio-design/openapi-contract-testing)
[![PHP Version Require](https://poser.pugx.org/studio-design/openapi-contract-testing/require/php)](https://packagist.org/packages/studio-design/openapi-contract-testing)
[![License](https://poser.pugx.org/studio-design/openapi-contract-testing/license)](https://packagist.org/packages/studio-design/openapi-contract-testing)

Framework-agnostic OpenAPI 3.0/3.1 contract testing for PHPUnit **with endpoint coverage tracking**.

Validate your API responses against your OpenAPI specification during testing, and get a coverage report showing which endpoints have been tested.

## Features

- **OpenAPI 3.0 & 3.1 support** — Automatic version detection from the `openapi` field
- **Response & request validation** — JSON Schema (Draft 07 via opis/json-schema) for body, parameters, and security schemes; `application/json` and any `+json` content type
- **Endpoint coverage tracking** — Unique PHPUnit extension that reports which spec endpoints are covered by tests, at `(method, path, status, content-type)` granularity
- **Schema-driven request fuzzing** — `ExploresOpenApiEndpoint` trait generates N happy-path inputs straight from the spec (Schemathesis-style)
- **Enum drift detection** — Static comparison between PHP backed enums and their `enum:` spec arrays, with PHPUnit-extension auto-discovery
- **Schema under-description detection** — Optional strict mode that flags response fields the implementation always returns but the spec marks as optional, catching the spec gaps that conformance checks alone can't. See [`docs/strict-required.md`](docs/strict-required.md) for current scope and limitations.
- **Skip-by-status-code** — Configurable regex list of status codes whose bodies are not validated (default: every `5xx`); per-request via `skipResponseCode()`
- **Laravel & Pest adapters** — Auto-assert / auto-validate-request integration, with explicit `expect(...)->toMatchOpenApiResponseSchema()` for Pest
- **Parallel-runner safe** — Coordinated sidecar+merge workflow for paratest / `pest --parallel`
- **Multi-format reports** — Markdown / JUnit XML / JSON / HTML output with one-click GitHub Step Summary
- **Zero runtime overhead** — Only used in test suites

## Why this library?

This library fills a gap left by existing PHP OpenAPI testing tools: **endpoint coverage tracking** and **first-class OpenAPI 3.1 support**, combined with Laravel auto-assert DX. If you already use Spectator and don't need coverage reports, this library won't offer much. If you want to see which endpoints your test suite actually exercises, or you're writing OpenAPI 3.1 specs, this is likely the best choice today.

### Feature comparison (as of 2026-04)

|  | **This library** | [Spectator][spectator] | [league/psr7][league] | [osteel][osteel] | [kirschbaum][kirschbaum] |
| --- | :---: | :---: | :---: | :---: | :---: |
| OpenAPI 3.0 | ✅ | ✅ | ✅ | ✅ | ✅ |
| OpenAPI 3.1 | ✅ | ⚠️ | ❌ | ⚠️ | ⚠️ |
| Response body validation | ✅ | ✅ | ✅ | ✅ | ✅ |
| Request validation (body + params) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Response header validation | ✅ | ⚠️ | ✅ | ✅ | ✅ |
| **Endpoint coverage tracking** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Schema-driven request fuzzing** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Skip-by-status-code (default 5xx)** | ✅ | ❌ | ❌ | ❌ | ✅ |
| PHPUnit integration | ✅ | ✅ | ❌ | ⚠️ | ✅ |
| Pest plugin | ✅ | ❌ | ❌ | ❌ | ❌ |
| Laravel auto-assert | ✅ | ✅ | ❌ | ❌ | ✅ |
| Symfony HttpFoundation | ❌ | ❌ | ⚠️ | ✅ | ❌ |
| External `$ref` auto-resolution | ✅ | ✅ | ✅ | ✅ | ✅ |
| YAML spec loading | ✅ | ⚠️ | ✅ | ✅ | ✅ |
| **Auto-inject dummy bearer** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **GitHub Step Summary output** | ✅ | ❌ | ❌ | ❌ | ❌ |

**Legend**: ✅ fully supported · ⚠️ partial, delegated to an underlying library, or not explicitly documented · ❌ not supported

**Methodology**: Cells reflect what each library's public documentation and source explicitly guarantee as of 2026-04-25. Competitor versions checked: Spectator v2.2.0, league/openapi-psr7-validator v0.22, osteel/openapi-httpfoundation-testing v0.14, kirschbaum-development/laravel-openapi-validator v2.0.

[spectator]: https://github.com/hotmeteor/spectator
[league]: https://github.com/thephpleague/openapi-psr7-validator
[osteel]: https://github.com/osteel/openapi-httpfoundation-testing
[kirschbaum]: https://github.com/kirschbaum-development/laravel-openapi-validator

## Requirements

- PHP 8.2+
- PHPUnit 11, 12, or 13
- A PSR-18 HTTP client + PSR-17 request factory (e.g. Guzzle, Symfony HttpClient) — only required when resolving HTTP(S) `$ref`s

## Installation

```bash
composer require --dev studio-design/openapi-contract-testing
```

> **YAML specs require `symfony/yaml`.** It is listed under `suggest` so it isn't installed automatically. If your spec is JSON, you can skip this. If your spec is `.yaml` / `.yml`, add it explicitly:
>
> ```bash
> composer require --dev symfony/yaml
> ```
>
> Without it, the loader throws `InvalidOpenApiSpecException` with a clear "requires symfony/yaml" message the first time it tries to read a YAML file.

## Quick start

Three steps to your first contract-tested endpoint with the Laravel adapter. For framework-agnostic usage, configuration knobs, opt-out attributes, and request-side validation, see [`docs/setup.md`](docs/setup.md).

### 1. Provide your OpenAPI spec

Point the loader at your spec's entry file. Internal and local-filesystem `$ref` are resolved automatically — no pre-bundling required:

```
openapi/
├── root.yaml          # paths reference ./schemas/*.yaml
└── schemas/
    ├── pet.yaml
    └── error.json
```

### 2. Register the PHPUnit extension

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="strip_prefixes" value="/api"/>
        <parameter name="specs" value="front,admin"/>
    </bootstrap>
</extensions>
```

### 3. Assert in tests (Laravel)

```bash
php artisan vendor:publish --tag=openapi-contract-testing
```

Set `default_spec` in the published `config/openapi-contract-testing.php`, then mix in the trait:

```php
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;

class GetPetsTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_list_pets(): void
    {
        $response = $this->get('/api/v1/pets');
        $response->assertOk();
        $this->assertResponseMatchesOpenApiSchema($response);
    }
}
```

To validate every response automatically, set `'auto_assert' => true` and drop the explicit assert call. To also catch request-side drift, set `'auto_validate_request' => true`. See [`docs/setup.md`](docs/setup.md) for the full configuration and opt-out reference.

## Documentation

| Topic | Reference |
|---|---|
| Full setup, framework-agnostic adapter, auto-assert, opt-out attributes, request validation, HTTP `$ref` | [`docs/setup.md`](docs/setup.md) |
| Pest plugin: `expect()->toMatchOpenApiResponseSchema()` and friends | [`docs/pest-plugin.md`](docs/pest-plugin.md) |
| Schema-driven request fuzzing | [`docs/fuzzing.md`](docs/fuzzing.md) |
| Enum drift detection | [`docs/enum-drift.md`](docs/enum-drift.md) |
| Schema under-description detection (`strict_required`) | [`docs/strict-required.md`](docs/strict-required.md) |
| Coverage report modes & threshold gate | [`docs/coverage.md`](docs/coverage.md) |
| HTML coverage output | [`docs/coverage-html-output.md`](docs/coverage-html-output.md) |
| JSON coverage output schema | [`docs/coverage-json-schema.md`](docs/coverage-json-schema.md) |
| Parallel test runners (paratest / Pest `--parallel`) | [`docs/parallel.md`](docs/parallel.md) |
| CI integration (GitHub Actions, PR comments, output formats, partial-run handling) | [`docs/ci.md`](docs/ci.md) |
| API reference (`OpenApiResponseValidator`, `OpenApiSpecLoader`, `OpenApiCoverageTracker`) | [`docs/api-reference.md`](docs/api-reference.md) |
| Supported features, known limitations, warning channel | [`docs/supported-features.md`](docs/supported-features.md) |
| Versioning policy & support matrix | [`docs/versioning.md`](docs/versioning.md) |

## Development

```bash
composer install

# Run tests
vendor/bin/phpunit

# Static analysis
vendor/bin/phpstan analyse

# Code style
vendor/bin/php-cs-fixer fix
vendor/bin/php-cs-fixer fix --dry-run --diff  # Check only
```

## License

MIT License. See [LICENSE](LICENSE) for details.
