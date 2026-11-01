<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Studio\Gesso\Rector\Rule\SpectatorAssertionRector;

// hotmeteor/spectator → Gesso. Run `gesso config:migrate` first: it turns
// Spectator::using() into spec.default and proposes the auto_assert and
// auto_validate_request settings that replace the deleted assertions.
// What has no 1:1 rewrite is listed in docs/migration/rector.md.
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(SpectatorAssertionRector::class);
};
