# Laravel quickstart

```bash
composer require --dev "studio-design/gesso:^2.0"
composer install
composer test
```

The fixture covers explicit response assertions and the `auto_assert` / `auto_validate_request` switches. Settings live in [`gesso.php`](gesso.php) beside `phpunit.xml.dist` (there is no `config/gesso.php` to publish); its PHPUnit extension prints endpoint coverage.
