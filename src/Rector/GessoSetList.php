<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector;

/**
 * Rector sets that rewrite PHP code for Gesso. Requires `rector/rector` ^2.7,
 * which Gesso only suggests. Pass a constant to `RectorConfig::withSets()`, or
 * pass the file path to `vendor/bin/rector process --config`.
 *
 * The constants and the files they name are stable for 3.x; what a set
 * rewrites is best-effort. See docs/migration/rector.md.
 */
final class GessoSetList
{
    /** Gesso v2 → v3: the PHP renames and removals in UPGRADING.md. */
    public const string V3 = __DIR__ . '/config/gesso-v3.php';

    /** kirschbaum-development/laravel-openapi-validator → Gesso's Laravel trait. */
    public const string KIRSCHBAUM_TO_GESSO = __DIR__ . '/config/kirschbaum-to-gesso.php';
}
