# Pest quickstart

```bash
composer require --dev "studio-design/gesso:^2.0" pestphp/pest
```

Pest runs on PHPUnit, so the spec loader comes from the same extension. Register
it in `phpunit.xml` — without it the first expectation throws
`OpenApiSpecLoader base path not configured`:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi"/>
        <parameter name="specs" value="petstore"/>
    </bootstrap>
</extensions>
```

Use the automatically registered expectation with a Laravel response:

```php
expect($this->getJson('/pets'))->toMatchOpenApiResponseSchema();
```

The runnable [`examples/pest`](https://github.com/studio-design/gesso/tree/main/examples/pest) project includes response and request expectations and is executed in CI. See the [Pest guide](../pest-plugin.md) for setup and supported expectation arguments.
