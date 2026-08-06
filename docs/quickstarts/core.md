# Core / PHPUnit quickstart

```bash
composer require --dev "studio-design/gesso:^2.0"
```

Register the extension in `phpunit.xml`. It configures the spec loader and prints
the coverage report; without it the first validation call throws
`OpenApiSpecLoader base path not configured`:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi"/>
        <parameter name="specs" value="petstore"/>
    </bootstrap>
</extensions>
```

Copy the minimal [`examples/core`](https://github.com/studio-design/gesso/tree/main/examples/core) project. Its test validates a JSON response without a framework adapter, then records the observation — the framework-independent validator never touches the coverage tracker, so a suite that only validates prints no endpoint coverage table at all. The Laravel, Symfony, and Pest adapters record it for you:

```php
$result = (new OpenApiResponseValidator(new StrictRequiredTracker()))->validate(
    'petstore', 'GET', '/pets', 200,
    [['id' => 1, 'name' => 'Fido']],
    'application/json',
);

self::assertTrue($result->isValid(), $result->errorMessage());

OpenApiCoverageTracker::recordResponse(
    'petstore',
    'GET',
    $result->matchedPath() ?? '/pets',
    $result->matchedStatusCode() ?? '200',
    $result->matchedContentType(),
    schemaValidated: true,
);
```

Run it:

```bash
composer test
```

The PHPUnit extension prints the endpoint coverage report after the passing test. For applications using PSR-7 messages, continue with the [PSR-7 guide](../psr7.md) — `OpenApiPsr7Validator` records coverage itself.
