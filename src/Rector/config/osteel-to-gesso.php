<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Studio\Gesso\Rector\Rule\OsteelValidatorRector;

// osteel/openapi-httpfoundation-testing → Gesso. Run `gesso config:migrate`
// first to name the spec in gesso.php. What has no 1:1 rewrite is listed in
// docs/migration/rector.md.
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(OsteelValidatorRector::class);
};
