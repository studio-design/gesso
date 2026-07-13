#!/usr/bin/env php
<?php

declare(strict_types=1);

use Studio\OpenApiContractTesting\Tests\Helpers\PublicApiInventory;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
array_shift($arguments);

if ($arguments !== [] && !in_array('--write', $arguments, true)) {
    fwrite(STDERR, "Usage: php scripts/export-public-api.php [--write]\n");
    exit(2);
}

$inventory = PublicApiInventory::capture(
    $root . '/src',
    'Studio\\OpenApiContractTesting\\',
);
$json = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

if (in_array('--write', $arguments, true)) {
    $path = $root . '/tests/fixtures/compatibility/v1.9-public-api.json';
    if (file_put_contents($path, $json) === false) {
        fwrite(STDERR, "Failed to write {$path}\n");
        exit(1);
    }

    fwrite(STDERR, "Wrote {$path}\n");
    exit(0);
}

echo $json;
