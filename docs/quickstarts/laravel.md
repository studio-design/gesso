# Laravel quickstart

```bash
composer require --dev "studio-design/gesso:^2.0"
php artisan vendor:publish --tag=gesso
```

Register the extension in `phpunit.xml`. This is what configures the spec loader
for the runtime validator; `gesso.spec_base_path` in the published
`config/gesso.php` does not — it only feeds the `openapi:routes` and
`openapi:stubs` Artisan commands:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi"/>
        <parameter name="specs" value="petstore"/>
    </bootstrap>
</extensions>
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
