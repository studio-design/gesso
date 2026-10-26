# Pest quickstart

```bash
composer require --dev "studio-design/gesso:^2.0" pestphp/pest
```

Pest runs on PHPUnit, so the spec loader comes from the same extension. Put the
settings in a root `gesso.php` and register the extension in `phpunit.xml` — without it the first expectation throws
`OpenApiSpecLoader base path not configured`:

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

Use the automatically registered expectation with a Laravel response:

```php
expect($this->getJson('/pets'))->toMatchOpenApiResponseSchema();
```

The runnable [`examples/pest`](https://github.com/studio-design/gesso/tree/main/examples/pest) project includes response and request expectations and is executed in CI. See the [Pest guide](../pest-plugin.md) for setup and supported expectation arguments.
