# Laravel quickstart

```bash
composer require --dev "studio-design/gesso:^2.0" phpunit/phpunit
```

Put the shared settings in the application-root `gesso.php` — the PHPUnit
extension and the Laravel service provider both read it, so there is no
`config/gesso.php` to publish — and register the extension in `phpunit.xml`.
The extension is what configures the spec loader for the runtime validator;
Laravel uses `spec.default` for assertions and `spec.base_path` for the
`gesso:routes` and `gesso:stubs` Artisan commands:

```php
<?php
// gesso.php, beside phpunit.xml

declare(strict_types=1);

return [
    'spec' => [
        'base_path' => 'openapi',
        'default' => 'petstore',
        'names' => ['petstore'],
    ],
];
```

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension"/>
</extensions>
```

Add `ValidatesOpenApiSchema` to your base test case, then assert a normal Laravel response:

```php
$response = $this->getJson('/pets');
$response->assertOk();
$this->assertResponseMatchesOpenApiSchema($response);
```

The complete [`examples/laravel`](https://github.com/studio-design/gesso/tree/main/examples/laravel) fixture installs and runs in CI:

```bash
composer test
```

Its second test switches on `auto_assert` and `auto_validate_request` for one test, demonstrating validation without an explicit assertion; set `laravel.auto_assert` / `laravel.auto_validate_request` in `gesso.php` to enable them suite-wide. The PHPUnit extension prints coverage for both tests.

To send a body a PHP array cannot express (the empty object `{}`), use
`$this->jsonRaw('PATCH', '/pets/1', '{}')`; see
[raw JSON request bodies](../setup.md#raw-json-request-bodies-with-jsonraw).

Already generating your spec with Scramble? Follow the
[Scramble integration recipe](../recipes/scramble.md) to validate generated
contracts, cover 401/404/422 responses, and gate untested contract changes in CI.
