# Laravel quickstart

> **Pre-release evaluation only.** Run this beta in an isolated branch or CI
> job; Gesso 2 has not reached a stable release.

```bash
composer require --dev "studio-design/gesso:^2.0@beta"
php artisan vendor:publish --tag=gesso
```

Set `default_spec` to `petstore`, add `ValidatesOpenApiSchema` to your base test case, then assert a normal Laravel response:

```php
$response = $this->getJson('/pets');
$response->assertOk();
$this->assertResponseMatchesOpenApiSchema($response);
```

The complete [`examples/laravel`](https://github.com/studio-design/gesso/tree/main/examples/laravel) fixture installs and runs in CI:

```bash
composer test
```

Its second test enables `auto_assert` and `auto_validate_request`, demonstrating validation without an explicit assertion. The PHPUnit extension prints coverage for both tests.
