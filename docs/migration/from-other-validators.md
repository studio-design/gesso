# Migrating from other OpenAPI validators

Adopt this package incrementally: keep the existing validator for untouched tests, add the PHPUnit extension, then migrate one assertion at a time. A migrated assertion records coverage immediately.

## Before you start

0. Gesso 3 needs PHPUnit 12 or later, and Pest 4 for the Pest plugin. Many
   projects using these validators are on PHPUnit 11. Upgrade first:

   ```bash
   composer require --dev "phpunit/phpunit:^12.5" --with-all-dependencies
   ```

1. Install Gesso and run `vendor/bin/gesso config:migrate`. It detects the
   packages below in `composer.json`, translates the settings it can map into
   a proposed `gesso.php`, and prints a file-by-file plan: each line under
   `tests/` that uses the old package, with the edit it needs. Write the
   proposal with `--write`. Run `vendor/bin/gesso config:migrate --check` in CI
   while you migrate. It exits `1` until the old package is removed. See
   [config:migrate](../cli.md#migrate-a-configuration-configmigrate) for what
   is translated.

## Spectator

Replace Spectator response assertions with `ValidatesOpenApiSchema`. Set `spec.default` once in `gesso.php`. Compare route coverage using `php artisan gesso:routes`, then enable `auto_assert` only after explicit assertions pass. `config:migrate` translates `path_prefix` to `spec.strip_prefixes` and the local source path to `spec.base_path`. Spectator validated only after `Spectator::using()`, and only routes in its `middleware_groups`. With `auto_assert`, Gesso validates every exchange in a test that uses the trait, so record known failures with `baseline.violations`.

## league/openapi-psr7-validator

Keep the same PSR-7 messages and replace direct validator construction with `OpenApiPsr7Validator` or the `OpenApiAssertions` trait. The adapter adds PHPUnit failures and coverage reporting around the exchange.

## osteel/openapi-httpfoundation-testing

Keep the Symfony `Request` and `Response` values and switch to `Symfony\OpenApiAssertions`. Call `assertResponseMatchesOpenApiSchema()` or validate the last `KernelBrowser` exchange with `assertClientMatchesOpenApiSchema()`.

## kirschbaum-development/laravel-openapi-validator

Add `ValidatesOpenApiSchema` to the Laravel base test case and migrate explicit assertions first. If automatic validation is required, enable `auto_assert` and `auto_validate_request`, then use per-request opt-outs for intentionally out-of-contract tests. `config:migrate` proposes both settings as `true`, because this package validates every request and response, and translates `spec_path` to `spec.base_path` and `spec.default`.

See the [feature comparison](https://github.com/studio-design/gesso#why-this-library) for deliberately scoped, version-pinned differences. Do not remove the previous validator until the migrated suite reports the expected endpoint coverage.
