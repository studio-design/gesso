<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Helpers;

use const E_USER_DEPRECATED;

use Studio\Gesso\Internal\Deprecations;

use function set_error_handler;
use function str_starts_with;

/**
 * Installs an error handler that swallows the `[Gesso deprecation]` channel
 * for tests exercising a deprecated surface whose notice is not the subject
 * under test. Pair with `restore_error_handler()` in `tearDown()`, and reset
 * `Deprecations` state alongside it — the notice may be swallowed, but the
 * process-wide counts still record.
 */
trait SwallowsGessoDeprecations
{
    private function swallowGessoDeprecations(): void
    {
        // Every other error chains to the previously registered (PHPUnit's)
        // handler. Returning false instead would discard it — during a test
        // run error_reporting is masked, so PHP's fallback handler drops the
        // error — silently disabling the suite's failOnWarning gate for
        // every test that runs under this handler.
        $previous = null;
        $previous = set_error_handler(
            static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previous): bool {
                if ($errno === E_USER_DEPRECATED && str_starts_with($errstr, Deprecations::PREFIX)) {
                    return true;
                }

                return $previous !== null && (bool) $previous($errno, $errstr, $errfile, $errline);
            },
        );
    }
}
