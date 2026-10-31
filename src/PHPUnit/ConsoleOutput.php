<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use const STDERR;

use Studio\Gesso\Internal\LegacyIdentity;

use function fwrite;
use function strtolower;
use function trim;

/**
 * @internal Configuration value used by the PHPUnit extension and merge CLI.
 */
enum ConsoleOutput: string
{
    case DEFAULT = 'default';
    case ALL = 'all';
    case UNCOVERED_ONLY = 'uncovered_only';
    case ACTIVE_ONLY = 'active_only';

    /**
     * Resolve the console output mode from the environment variable and/or gesso.php's coverage.console_report.
     *
     * Priority: environment variable > coverage.console_report > DEFAULT.
     */
    public static function resolve(?string $parameterValue): self
    {
        $envValue = LegacyIdentity::env('GESSO_CONSOLE_OUTPUT');

        if ($envValue !== false && trim($envValue) !== '') {
            $resolved = self::tryFrom(strtolower(trim($envValue)));

            if ($resolved === null) {
                fwrite(STDERR, "[Gesso] WARNING: Invalid GESSO_CONSOLE_OUTPUT value '{$envValue}'. Valid values: default, all, uncovered_only, active_only. Falling back to 'default'.\n");
            }

            return $resolved ?? self::DEFAULT;
        }

        if ($parameterValue !== null && trim($parameterValue) !== '') {
            $resolved = self::tryFrom(strtolower(trim($parameterValue)));

            if ($resolved === null) {
                fwrite(STDERR, "[Gesso] WARNING: Invalid coverage.console_report value '{$parameterValue}'. Valid values: default, all, uncovered_only, active_only. Falling back to 'default'.\n");
            }

            return $resolved ?? self::DEFAULT;
        }

        return self::DEFAULT;
    }
}
