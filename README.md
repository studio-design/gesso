<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="images/logo-dark.png">
    <img alt="openapi-contract-testing" src="images/logo-light.png" width="480">
  </picture>
</p>

# OpenAPI Contract Testing for PHPUnit

[![CI](https://github.com/studio-design/openapi-contract-testing/actions/workflows/ci.yml/badge.svg)](https://github.com/studio-design/openapi-contract-testing/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/studio-design/openapi-contract-testing/v)](https://packagist.org/packages/studio-design/openapi-contract-testing)
[![Total Downloads](https://poser.pugx.org/studio-design/openapi-contract-testing/downloads)](https://packagist.org/packages/studio-design/openapi-contract-testing)
[![PHP Version Require](https://poser.pugx.org/studio-design/openapi-contract-testing/require/php)](https://packagist.org/packages/studio-design/openapi-contract-testing)
[![License](https://poser.pugx.org/studio-design/openapi-contract-testing/license)](https://packagist.org/packages/studio-design/openapi-contract-testing)

Framework-agnostic OpenAPI 3.0/3.1/3.2 contract testing for PHPUnit **with endpoint coverage tracking**.

Validate your API responses against your OpenAPI specification during testing, and get a coverage report showing which endpoints have been tested.

## Features

- **OpenAPI 3.0, 3.1 & 3.2 support** — Explicit version detection, including 3.2 `QUERY`, custom `additionalOperations`, form `querystring`, `discriminator.defaultMapping`, and observable streaming limitations
- **Response & request validation** — dialect-aware JSON Schema via opis/json-schema: Draft 07 compatibility for OpenAPI 3.0 and native 2020-12 semantics for OpenAPI 3.1/3.2; `application/json` and any `+json` content type
- **Endpoint coverage tracking** — Unique PHPUnit extension that reports which spec endpoints are covered by tests, at `(method, path, status, content-type)` granularity
- **Schema-driven request fuzzing** — `ExploresOpenApiEndpoint` trait generates N happy-path inputs straight from the spec (Schemathesis-style)
- **Enum drift detection** — Static comparison between PHP backed enums and their `enum:` spec arrays, with PHPUnit-extension auto-discovery
- **Schema under-description detection** — Optional strict mode that flags response fields the implementation always returns but the spec marks as optional, catching the spec gaps that conformance checks alone can't. See [`docs/strict-required.md`](docs/strict-required.md) for current scope and limitations.
- **Skip-by-status-code** — Configurable regex list of status codes whose bodies are not validated (default: every `5xx`); per-request via `skipResponseCode()`
- **Laravel, Symfony & Pest adapters** — Auto-assert / auto-validate-request integration for Laravel, an `OpenApiAssertions` trait for Symfony HttpFoundation, and explicit `expect(...)->toMatchOpenApiResponseSchema()` for Pest
- **Parallel-runner safe** — Coordinated sidecar+merge workflow for paratest / `pest --parallel`
- **Multi-format reports** — Markdown / JUnit XML / JSON / HTML output with one-click GitHub Step Summary
- **Zero runtime overhead** — Only used in test suites

## Why this library?

Choose based on the workflow you need rather than on a single yes/no feature count:

- Choose **this library** when you need response-level coverage at `(method, path, status, content-type)` granularity, several CI report formats, OpenAPI 3.1/3.2 JSON Schema semantics, schema-driven exploration, or drift detection across a framework-agnostic core and Laravel, Symfony, and Pest adapters.
- Choose **[Spectator][spectator]** for a Laravel 12 application when route/spec parity, Artisan diagnostics, generated test stubs, JSON assertion failures, or remote/private-GitHub spec sources matter more than response-level coverage granularity.
- Choose **[league/openapi-psr7-validator][league]** when you want a low-level PSR-7 validator or PSR-15 middleware and will build the test/reporting integration yourself.
- Choose **[osteel/openapi-httpfoundation-testing][osteel]** when you want a small HttpFoundation-to-PSR-7 validation bridge, or **[laravel-openapi-validator][kirschbaum]** when automatic validation around Laravel HTTP tests is the main requirement.

### Feature comparison (checked 2026-07-10)

| Capability | **This library** | [Spectator v3.0.2][spectator] | [league/psr7 v0.24][league] | [osteel v0.14][osteel] | [kirschbaum v2.0.2][kirschbaum] |
| --- | --- | --- | --- | --- | --- |
| OpenAPI versions explicitly supported | [3.0, 3.1, 3.2](docs/supported-features.md) | Version scope not stated | [3.0.x][league-readme] | [3+; delegates to League v0.22][osteel-composer] | [Delegates to League v0.14–0.24][kirschbaum-composer] |
| Request + response validation | ✅ | [✅ Laravel][spectator] | [✅ PSR-7][league-readme] | [✅ HttpFoundation / PSR-7][osteel-readme] | [✅ Laravel HTTP tests][kirschbaum-readme] |
| Coverage granularity | [`method, path, status, content-type`](docs/coverage.md) | [`method, path` operation][spectator-coverage-source] | — | — | — |
| Coverage outputs | [Markdown, JUnit XML, JSON, HTML, GitHub Step Summary](docs/coverage.md) | [Text, JSON][spectator-coverage] | — | — | — |
| Parallel coverage merge | [Sidecar + merge CLI](docs/parallel.md) | Not documented | — | — | — |
| Route/spec parity | Not yet ([#277](https://github.com/studio-design/openapi-contract-testing/issues/277)) | [`spectator:routes`][spectator-cli] | — | — | — |
| CLI diagnostics / scaffolding | Coverage merge only | [`validate`, `coverage`, `routes`, `stubs`][spectator-cli] | — | — | — |
| Structured validation failures | Text messages; JSON planned ([#282](https://github.com/studio-design/openapi-contract-testing/issues/282)) | [JSON `{errors: [...]}`][spectator-errors] | [PHP exception hierarchy][league-errors] | [Wrapper exception][osteel-readme] | [PHPUnit failure text][kirschbaum-failure-source] |
| Schema-driven exploration | [Deterministic happy-path generation](docs/fuzzing.md) | — | — | — | — |
| Drift / under-description checks | [Enum drift](docs/enum-drift.md), [strict required](docs/strict-required.md) | — | — | — | — |
| First-class integration | [Framework-agnostic, Laravel, Symfony, Pest](docs/setup.md) | [Laravel][spectator] | [PSR-7, PSR-15 middleware][league-middleware] | [HttpFoundation, PSR-7][osteel-readme] | [Laravel auto-validation][kirschbaum-readme] |
| Declared runtime floor | PHP 8.2 core; [Testbench 9–11](composer.json) ([Laravel 11–12][testbench-compat]; [Laravel 13 / PHP 8.3][testbench-11-composer]) | [PHP 8.3, Laravel 12][spectator-composer] | [PHP 7.2][league-composer] | [PHP 8.0, HttpFoundation 5–8][osteel-composer] | [PHP 8.0, Illuminate 10–13][kirschbaum-composer] |

**Legend**: ✅ supported · — no equivalent feature documented. “Not documented” is intentionally different from “unsupported”.

**Methodology**: This is a documentation/source audit, not a benchmark. Claims are limited to the linked, tag-pinned public documentation and Composer constraints checked on 2026-07-10. This-library claims describe [`main` at `8c6416d`](https://github.com/studio-design/openapi-contract-testing/commit/8c6416dcd7edf179010f5f1cdc71a1e146a5c403); competitor versions are shown in the table header. Re-check this matrix using the [release checklist](docs/versioning.md#release-checklist) at least quarterly or before a release when three months have elapsed.

[spectator]: https://github.com/hotmeteor/spectator/tree/v3.0.2
[spectator-cli]: https://github.com/hotmeteor/spectator/tree/v3.0.2#artisan-commands
[spectator-coverage]: https://github.com/hotmeteor/spectator/tree/v3.0.2#contract-coverage-tracking
[spectator-coverage-source]: https://github.com/hotmeteor/spectator/blob/v3.0.2/src/Coverage/CoverageTracker.php
[spectator-errors]: https://github.com/hotmeteor/spectator/tree/v3.0.2#machine-readable-error-output
[spectator-composer]: https://github.com/hotmeteor/spectator/blob/v3.0.2/composer.json
[league]: https://github.com/thephpleague/openapi-psr7-validator/tree/0.24
[league-readme]: https://github.com/thephpleague/openapi-psr7-validator/tree/0.24#openapi-psr-7-message-httprequestresponse-validator
[league-errors]: https://github.com/thephpleague/openapi-psr7-validator/tree/0.24#exceptions
[league-middleware]: https://github.com/thephpleague/openapi-psr7-validator/tree/0.24#psr-15-middleware
[league-composer]: https://github.com/thephpleague/openapi-psr7-validator/blob/0.24/composer.json
[osteel]: https://github.com/osteel/openapi-httpfoundation-testing/tree/v0.14
[osteel-readme]: https://github.com/osteel/openapi-httpfoundation-testing/tree/v0.14#usage
[osteel-composer]: https://github.com/osteel/openapi-httpfoundation-testing/blob/v0.14/composer.json
[kirschbaum]: https://github.com/kirschbaum-development/laravel-openapi-validator/tree/2.0.2
[kirschbaum-readme]: https://github.com/kirschbaum-development/laravel-openapi-validator/tree/2.0.2#usage
[kirschbaum-composer]: https://github.com/kirschbaum-development/laravel-openapi-validator/blob/2.0.2/composer.json
[kirschbaum-failure-source]: https://github.com/kirschbaum-development/laravel-openapi-validator/blob/2.0.2/src/ValidatesOpenApiSpec.php#L271-L289
[testbench-compat]: https://packages.tools/testbench#version-compatibility
[testbench-11-composer]: https://github.com/orchestral/testbench/blob/v11.1.0/composer.json

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

Three steps to your first contract-tested endpoint with the Laravel adapter. For the Symfony adapter, framework-agnostic usage, configuration knobs, opt-out attributes, and request-side validation, see [`docs/setup.md`](docs/setup.md).

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
| Full setup, Laravel / Symfony / framework-agnostic adapters, auto-assert, opt-out attributes, request validation, HTTP `$ref` | [`docs/setup.md`](docs/setup.md) |
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
