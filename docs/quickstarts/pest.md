# Pest quickstart

> **Pre-release evaluation only.** Run this beta in an isolated branch or CI
> job; Gesso 2 has not reached a stable release.

```bash
composer require --dev "studio-design/gesso:^2.0@beta" pestphp/pest
```

Use the automatically registered expectation with a Laravel response:

```php
expect($this->getJson('/pets'))->toMatchOpenApiResponseSchema();
```

The runnable [`examples/pest`](https://github.com/studio-design/gesso/tree/main/examples/pest) project includes response and request expectations and is executed in CI. See the [Pest guide](../pest-plugin.md) for setup and supported expectation arguments.
