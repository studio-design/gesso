<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

/*
 * PHP-CS-Fixer config for Pest test files (tests/Integration/Pest and
 * tests/Pest.php). Reuses the mainline ruleset via .php-cs-fixer.shared.php
 * with `static_lambda` overridden off — Pest's `it(...)` and `test(...)`
 * callbacks must NOT be static (Pest binds $this to the test case and
 * rejects static closures with TestClosureMustNotBeStatic). The previous
 * approach excluded these files from CS entirely, which silently lost
 * `declare_strict_types`, `no_unused_imports`, `single_quote`, trailing
 * comma checks, and so on. Splitting the ruleset between two configs
 * keeps everything else enforced.
 *
 * Both `composer cs` and `composer cs-check` invoke this config after
 * the mainline one.
 */

$rulesFactory = require __DIR__ . '/.php-cs-fixer.shared.php';

$rules = $rulesFactory();
$rules['static_lambda'] = false;

$finder = Finder::create()
    ->in([__DIR__ . '/tests/Integration/Pest'])
    ->append([__DIR__ . '/tests/Pest.php'])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.pest.cache')
    ->setRules($rules);
