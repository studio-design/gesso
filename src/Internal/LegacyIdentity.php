<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const STDERR;

use InvalidArgumentException;

use function array_search;
use function fwrite;
use function getenv;
use function in_array;
use function putenv;
use function sprintf;
use function trim;

/**
 * The old→new spelling map for the two identity surfaces ADR 0001 left behind:
 * the environment variables and Artisan commands Gesso owns.
 *
 * Issue #504. Every legacy spelling keeps working with identical behavior for
 * the whole of v3; the only new output is one STDERR line per legacy name per
 * process. Removal is {@see self::REMOVED_IN}, tracked by #523.
 *
 * The warning deliberately does *not* go through {@see Deprecations} and its
 * `E_USER_DEPRECATED` channel: a PHPUnit run configured with `failOnDeprecation`
 * would then fail purely for spelling a name that is still supported.
 *
 * @internal Implementation detail of the v3 rename. Not a public API: the map
 *           disappears with the legacy names it describes.
 */
final class LegacyIdentity
{
    /**
     * The major that removes every legacy spelling below. Tracked by
     * https://github.com/studio-design/gesso/issues/523.
     */
    public const REMOVED_IN = '4.0.0';

    /** Legacy environment variable name => the name to use instead. */
    public const ENV_NAMES = [
        'OPENAPI_VALIDATION_OUTPUT' => 'GESSO_VALIDATION_FORMAT',
        'OPENAPI_CONSOLE_OUTPUT' => 'GESSO_CONSOLE_OUTPUT',
        'OPENAPI_BASELINE_GENERATE' => 'GESSO_BASELINE_GENERATE',
    ];

    /** Legacy Artisan command name => the name to use instead. */
    public const COMMAND_NAMES = [
        'openapi:routes' => 'gesso:routes',
        'openapi:stubs' => 'gesso:stubs',
    ];

    /** @var list<string> */
    private static array $warnings = [];

    private function __construct() {}

    /**
     * Read `$name`, falling back to its legacy spelling with a one-time
     * warning. Returns `false` when neither name carries a non-blank value,
     * so a caller keeps `getenv()`'s branch shape.
     *
     * The current name wins when both are set, and setting only the legacy
     * name is honoured.
     */
    public static function env(string $name): false|string
    {
        $value = getenv($name);

        if ($value !== false && trim($value) !== '') {
            return $value;
        }

        $legacy = array_search($name, self::ENV_NAMES, true);

        if ($legacy === false) {
            return false;
        }

        $legacyValue = getenv($legacy);

        if ($legacyValue === false || trim($legacyValue) === '') {
            return false;
        }

        self::warn($legacy, $name);

        return $legacyValue;
    }

    /**
     * Warn when an Artisan command was invoked under its legacy alias. Takes
     * the invoked name rather than the canonical one, because Symfony resolves
     * the alias before the command runs and only the input still remembers
     * which spelling the user typed.
     */
    public static function warnIfLegacyCommand(string $invokedName): void
    {
        $current = self::COMMAND_NAMES[$invokedName] ?? null;

        if ($current === null) {
            return;
        }

        self::warn($invokedName, $current);
    }

    /**
     * The warning lines emitted this process, in order. The writes go to
     * STDERR — which no test harness here can capture — so this is the seam
     * that makes them assertable.
     *
     * @return list<string>
     */
    public static function warnings(): array
    {
        return self::$warnings;
    }

    /** Clear the once-per-process dedup. Call from tests that trigger a warning. */
    public static function resetForTesting(): void
    {
        self::$warnings = [];
    }

    /**
     * Unset `$name` *and* the spelling {@see env()} would fall back to, then
     * clear the dedup.
     *
     * Test lifecycles call this instead of `putenv($name)`: clearing only the
     * current name leaves a legacy name in the ambient environment able to
     * steer a test that asserts default behaviour, and the leak is invisible
     * until someone runs the suite on a machine that exports the old spelling.
     */
    public static function resetEnvForTesting(string $name): void
    {
        putenv($name);

        $legacy = array_search($name, self::ENV_NAMES, true);

        if ($legacy !== false) {
            putenv($legacy);
        }

        self::$warnings = [];
    }

    private static function warn(string $legacy, string $current): void
    {
        if (trim($legacy) === '' || trim($current) === '') {
            throw new InvalidArgumentException('A legacy-identity warning needs both the legacy and the current name.');
        }

        $line = sprintf(
            '[Gesso] WARNING: %s is deprecated and will be removed in Gesso %s. Use %s.',
            $legacy,
            self::REMOVED_IN,
            $current,
        );

        if (in_array($line, self::$warnings, true)) {
            return;
        }

        self::$warnings[] = $line;
        fwrite(STDERR, $line . "\n");
    }
}
