<?php

declare(strict_types=1);

namespace Studio\Gesso;

use const STDERR;

use function fwrite;
use function getenv;
use function mb_strtolower;
use function trim;

/**
 * Process-wide selection of the validation failure output format.
 *
 * Every framework adapter (Laravel, Symfony, Pest, PSR-7) consults
 * {@see format()} when composing an assertion failure message, so one switch
 * selects the same mode everywhere. Resolution priority:
 *
 * 1. environment variable `OPENAPI_VALIDATION_OUTPUT` (`text` | `json`);
 * 2. the format selected via {@see use()} (directly or through the PHPUnit
 *    extension's `validation_output` parameter);
 * 3. {@see ValidationOutputFormat::Text} (the historical default).
 *
 * An unrecognised environment value warns on STDERR once per process and
 * falls through to the next source, mirroring the coverage extension's
 * `OPENAPI_CONSOLE_OUTPUT` handling.
 */
final class ValidationOutput
{
    private static ?ValidationOutputFormat $selected = null;

    private static bool $warnedInvalidEnv = false;

    private function __construct() {}

    public static function format(): ValidationOutputFormat
    {
        $envValue = getenv('OPENAPI_VALIDATION_OUTPUT');

        if ($envValue !== false && trim($envValue) !== '') {
            $resolved = ValidationOutputFormat::tryFrom(mb_strtolower(trim($envValue)));

            if ($resolved !== null) {
                return $resolved;
            }

            if (!self::$warnedInvalidEnv) {
                self::$warnedInvalidEnv = true;
                fwrite(STDERR, "[OpenAPI Validation] WARNING: Invalid OPENAPI_VALIDATION_OUTPUT value '{$envValue}'. Valid values: text, json. Falling back to the configured format.\n");
            }
        }

        return self::$selected ?? ValidationOutputFormat::Text;
    }

    /**
     * Select the format programmatically (test bootstrap, framework setup,
     * or the PHPUnit extension's `validation_output` parameter). The
     * environment variable still wins when set.
     */
    public static function use(ValidationOutputFormat $format): void
    {
        self::$selected = $format;
    }

    /** Restore the text default. Call from test tear-down when a test selects a format. */
    public static function reset(): void
    {
        self::$selected = null;
        self::$warnedInvalidEnv = false;
    }
}
