<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

use const E_USER_WARNING;

use function count;
use function implode;
use function sprintf;
use function trigger_error;

/**
 * Immediate warning companion to the run-level aggregate gate.
 *
 * @internal Configured by the PHPUnit extension.
 */
final class StrictAdditionalPropertiesPerCallChecker
{
    private static StrictAdditionalPropertiesPerCallMode $mode = StrictAdditionalPropertiesPerCallMode::Off;

    private function __construct() {}

    public static function configure(StrictAdditionalPropertiesPerCallMode $mode): void
    {
        self::$mode = $mode;
    }

    public static function reset(): void
    {
        self::$mode = StrictAdditionalPropertiesPerCallMode::Off;
    }

    public static function isEnabled(): bool
    {
        return self::$mode !== StrictAdditionalPropertiesPerCallMode::Off;
    }

    /**
     * @param array<string, string> $findings pointer => property
     */
    public static function maybeWarn(
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
        array $findings,
    ): void {
        if (!self::isEnabled() || $findings === []) {
            return;
        }

        $rows = [];
        foreach ($findings as $pointer => $property) {
            $rows[] = sprintf('  - %s (%s)', $pointer, $property);
        }
        trigger_error(sprintf(
            "[OpenAPI Strict Additional Properties per-call] WARN: %s %s  %s  %s returned %d undocumented response propert%s:\n%s\n"
            . 'Action: declare the properties, explicitly document the object as open, or set strict_additional_properties_per_call=off.',
            $method,
            $path,
            $statusKey,
            $contentTypeKey,
            count($findings),
            count($findings) === 1 ? 'y' : 'ies',
            implode("\n", $rows),
        ), E_USER_WARNING);
    }
}
