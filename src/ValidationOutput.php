<?php

declare(strict_types=1);

namespace Studio\Gesso;

use const STDERR;

use Studio\Gesso\Internal\AgentEnvironment;
use Studio\Gesso\Internal\LegacyIdentity;

use function fwrite;
use function strtolower;
use function trim;

/**
 * Process-wide selection of the validation failure output format.
 *
 * Every framework adapter (Laravel, Symfony, Pest, PSR-7) consults
 * {@see format()} when composing an assertion failure message, so one switch
 * selects the same mode everywhere. Resolution priority:
 *
 * 1. environment variable `GESSO_VALIDATION_FORMAT` (`text` | `json` |
 *    `agent` | `auto`), or the pre-v3 spelling {@see LegacyIdentity} still
 *    accepts for it;
 * 2. the format selected via {@see use()} (directly or through the PHPUnit
 *    extension's `validation_output` parameter, i.e. `validation.format`);
 * 3. {@see ValidationOutputFormat::Auto}.
 *
 * `Auto` resolves to `Agent` when {@see AgentEnvironment} detects a coding
 * agent and to `Text` otherwise, so outside an agent the default output is
 * unchanged. An explicit `text` or `json` always wins.
 *
 * An unrecognised environment value warns on STDERR once per process and
 * falls through to the next source, mirroring the coverage extension's
 * `GESSO_CONSOLE_OUTPUT` handling.
 */
final class ValidationOutput
{
    private static ?ValidationOutputFormat $selected = null;
    private static bool $warnedInvalidEnv = false;

    private function __construct() {}

    public static function format(): ValidationOutputFormat
    {
        $format = self::$selected ?? ValidationOutputFormat::Auto;
        $envValue = LegacyIdentity::env('GESSO_VALIDATION_FORMAT');

        if ($envValue !== false && trim($envValue) !== '') {
            $resolved = ValidationOutputFormat::tryFrom(strtolower(trim($envValue)));

            if ($resolved !== null) {
                $format = $resolved;
            } elseif (!self::$warnedInvalidEnv) {
                self::$warnedInvalidEnv = true;
                fwrite(STDERR, "[Gesso] WARNING: Invalid GESSO_VALIDATION_FORMAT value '{$envValue}'. Valid values: text, json, agent, auto. Falling back to the configured format.\n");
            }
        }

        if ($format === ValidationOutputFormat::Auto) {
            return AgentEnvironment::detected() ? ValidationOutputFormat::Agent : ValidationOutputFormat::Text;
        }

        return $format;
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

    /** Restore the `auto` default. Call from test tear-down when a test selects a format. */
    public static function reset(): void
    {
        self::$selected = null;
        self::$warnedInvalidEnv = false;
    }
}
