# Pest quickstart

```bash
composer require --dev studio-design/openapi-contract-testing pestphp/pest
```

Use the automatically registered expectation with a Laravel response:

```php
expect($this->getJson('/pets'))->toMatchOpenApiResponseSchema();
```

The runnable [`examples/pest`](https://github.com/studio-design/gesso/tree/main/examples/pest) project includes response and request expectations and is executed in CI. See the [Pest guide](../pest-plugin.md) for setup and supported expectation arguments.
