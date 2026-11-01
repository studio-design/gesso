<?php

declare(strict_types=1);

// Regenerates docs/failure-codes.md from the failure-code catalogue.
// FailureCatalogueTest fails when the committed page drifts from it.

use Studio\Gesso\Internal\FailureCatalogue;

require __DIR__ . '/../vendor/autoload.php';

file_put_contents(__DIR__ . '/../docs/failure-codes.md', FailureCatalogue::markdown());
