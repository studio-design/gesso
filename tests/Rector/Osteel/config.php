<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Studio\Gesso\Rector\GessoSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(GessoSetList::OSTEEL_TO_GESSO);
    $rectorConfig->bootstrapFiles([__DIR__ . '/Source/osteel.php']);
};
