# Core / PHPUnit quickstart

```bash
composer require --dev "studio-design/gesso:^2.0" phpunit/phpunit
```

Put the shared settings in a root `gesso.php` and register the extension in
`phpunit.xml`, which reads that file. The extension configures the spec loader and prints
the coverage report; without it the first validation call throws
`OpenApiSpecLoader base path not configured`:

```php
<?php
// gesso.php, beside phpunit.xml

declare(strict_types=1);

return [
    'spec' => [
        'base_path' => 'openapi',
        'names' => ['petstore'],
    ],
];
```

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension"/>
</extensions>
```

Copy the minimal [`examples/core`](https://github.com/studio-design/gesso/tree/main/examples/core) project. Its test validates a JSON response without a framework adapter. The validator records the coverage observation itself, so this is the whole test — the endpoint coverage table appears without any tracker call:

```php
$result = (new OpenApiResponseValidator(new StrictRequiredTracker()))->validate(
    'petstore', 'GET', '/pets', 200,
    [['id' => 1, 'name' => 'Fido']],
    'application/json',
);

self::assertTrue($result->isValid(), $result->errorMessage());
```

Run it:

```bash
composer test
```

The PHPUnit extension prints the endpoint coverage report after the passing test. For applications using PSR-7 messages, continue with the [PSR-7 guide](../psr7.md) — `OpenApiPsr7Validator` records coverage itself.
