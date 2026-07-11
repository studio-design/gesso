# Migrating from other OpenAPI validators

Adopt this package incrementally: keep the existing validator for untouched tests, add the PHPUnit extension, then migrate one assertion at a time. A migrated assertion records coverage immediately.

## Spectator

Replace Spectator response assertions with `ValidatesOpenApiSchema`. Set `default_spec` once in Laravel config. Compare route coverage using `php artisan openapi:routes`, then enable `auto_assert` only after explicit assertions pass.

## league/openapi-psr7-validator

Keep the same PSR-7 messages and replace direct validator construction with `OpenApiPsr7Validator` or the `OpenApiAssertions` trait. The adapter adds PHPUnit failures and coverage reporting around the exchange.

## osteel/openapi-httpfoundation-testing

Keep the Symfony `Request` and `Response` values and switch to `Symfony\OpenApiAssertions`. Call `assertResponseMatchesOpenApiSchema()` or validate the last `KernelBrowser` exchange with `assertClientMatchesOpenApiSchema()`.

## kirschbaum-development/laravel-openapi-validator

Add `ValidatesOpenApiSchema` to the Laravel base test case and migrate explicit assertions first. If automatic validation is required, enable `auto_assert` and `auto_validate_request`, then use per-request opt-outs for intentionally out-of-contract tests.

See the [feature comparison](https://github.com/studio-design/gesso#why-this-library) for deliberately scoped, version-pinned differences. Do not remove the previous validator until the migrated suite reports the expected endpoint coverage.
