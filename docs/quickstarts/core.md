# Core / PHPUnit quickstart

```bash
composer require --dev studio-design/gesso
```

Copy the minimal [`examples/core`](https://github.com/studio-design/gesso/tree/main/examples/core) project. Its test validates a JSON response without a framework adapter:

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

The PHPUnit extension prints the endpoint coverage report after the passing test. For applications using PSR-7 messages, continue with the [PSR-7 guide](../psr7.md).
