<?php

declare(strict_types=1);

/*
 * Composer-script guard invoked before `composer test:pest`.
 *
 * Pest is intentionally not listed in require-dev because Pest 4 requires
 * PHPUnit ^12 — incompatible with the PHPUnit ^13 cells of the main
 * test matrix. So a fresh `composer install` does NOT bring Pest in.
 *
 * Without this guard, `composer test:pest` against a Pest-free vendor/
 * fails with "sh: pest: command not found" (exit 127) — technically
 * loud, but a contributor seeing it has to know which command to run
 * to fix it. This script catches the missing binary up front and prints
 * the exact install command instead.
 */

if (is_file(__DIR__ . '/../vendor/bin/pest')) {
    exit(0);
}

fwrite(
    STDERR,
    "\nERROR: vendor/bin/pest not found.\n"
    . "\n"
    . "composer test:pest requires Pest 4. Install it with:\n"
    . "  composer require --dev pestphp/pest:^4.0\n"
    . "\n"
    . "Pest is intentionally not in require-dev because Pest 4 requires\n"
    . "PHPUnit ^12 while the mainline matrix also covers PHPUnit ^13.\n"
    . "\n",
);

exit(1);
