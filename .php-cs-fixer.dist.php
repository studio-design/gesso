<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$rulesFactory = require __DIR__ . '/.php-cs-fixer.shared.php';

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Pest test files run under .php-cs-fixer.pest.dist.php with the
    // `static_lambda` rule overridden off (Pest forbids static `it(...)`
    // callbacks). The two configs share the rest of the ruleset via
    // .php-cs-fixer.shared.php; both `composer cs` and `composer cs-check`
    // invoke them sequentially.
    ->exclude('Integration/Pest')
    ->notPath('Pest.php')
    ->append([
        __FILE__,
        __DIR__ . '/.php-cs-fixer.shared.php',
    ])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules($rulesFactory());
