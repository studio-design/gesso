# PSR-7 example

This runnable example validates a Guzzle PSR-7 request and response as one
exchange and records OpenAPI coverage.

```bash
composer install
composer test
```

The package is loaded from the repository root through a Composer path
repository, so CI exercises the current working tree rather than a released
version.
