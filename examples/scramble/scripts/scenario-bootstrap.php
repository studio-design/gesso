<?php

declare(strict_types=1);

// The scenario runner copies the application and reuses installed dependencies.
// Override Composer's application paths so mutations affect only that copy.
$base = \dirname(__DIR__);
$loader = require $base . '/vendor/autoload.php';
$loader->setPsr4('App\\', $base . '/app');
$loader->setPsr4('Tests\\', $base . '/tests');
$loader->setPsr4('Database\\Factories\\', $base . '/database/factories');
$_ENV['APP_BASE_PATH'] = $base;
