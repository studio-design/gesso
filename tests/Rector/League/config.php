<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Studio\Gesso\Rector\GessoSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(GessoSetList::LEAGUE_TO_GESSO);
    $rectorConfig->bootstrapFiles([__DIR__ . '/Source/league.php']);
};
