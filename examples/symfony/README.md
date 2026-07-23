# Symfony quickstart

> **Pre-release evaluation only.** Run this beta in an isolated branch or CI
> job; Gesso 2 has not reached a stable release.

```bash
composer require --dev "studio-design/gesso:^2.0@beta" symfony/http-foundation symfony/http-kernel
composer install
composer test
```

The passing test validates HttpFoundation request and response objects. In a full Symfony application, the same trait also validates the last `KernelBrowser` exchange.
