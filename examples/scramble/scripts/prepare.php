<?php

declare(strict_types=1);

// Prepare only this example's local files; Laravel supplies the default config.
\chdir(\dirname(__DIR__));
foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'openapi', 'build'] as $directory) {
    if (!\is_dir($directory)) {
        \mkdir($directory, 0o777, true);
    }
}
if (!\file_exists('database/database.sqlite')) {
    \touch('database/database.sqlite');
}
if (!\file_exists('.env')) {
    \file_put_contents('.env', "APP_ENV=local\nAPP_KEY=base64:" . \base64_encode(\random_bytes(32)) . "\nDB_CONNECTION=sqlite\nSESSION_DRIVER=array\nCACHE_STORE=array\n");
}
