<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use const STDERR;

use function fwrite;
use function getenv;
use function mb_strtolower;
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
     * Resolve the console output mode from environment variable and/or phpunit.xml parameter.
     *
     * Priority: environment variable > parameter > DEFAULT.
     */
    public static function resolve(?string $parameterValue): self
    {
        $envValue = getenv('OPENAPI_CONSOLE_OUTPUT');

        if ($envValue !== false && trim($envValue) !== '') {
            $resolved = self::tryFrom(mb_strtolower(trim($envValue)));

            if ($resolved === null) {
                fwrite(STDERR, "[OpenAPI Coverage] WARNING: Invalid OPENAPI_CONSOLE_OUTPUT value '{$envValue}'. Valid values: default, all, uncovered_only, active_only. Falling back to 'default'.\n");
            }

            return $resolved ?? self::DEFAULT;
        }

        if ($parameterValue !== null && trim($parameterValue) !== '') {
            $resolved = self::tryFrom(mb_strtolower(trim($parameterValue)));

            if ($resolved === null) {
                fwrite(STDERR, "[OpenAPI Coverage] WARNING: Invalid console_output parameter '{$parameterValue}'. Valid values: default, all, uncovered_only, active_only. Falling back to 'default'.\n");
            }

            return $resolved ?? self::DEFAULT;
        }

        return self::DEFAULT;
    }
}
