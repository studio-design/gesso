<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Internal;

use Error;
use Exception;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionException;
use ReflectionProperty;

use function is_string;
use function str_contains;
use function str_replace;

/**
 * Trims this library's own + Laravel test-concern frames out of an
 * assertion-failure trace so a contract-test failure points at the
 * user's test line, not at vendor code.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class StackTraceFilter
{
    /**
     * Frame `file` substrings (forward-slash form) that mark frames as
     * library/framework noise and should be dropped from the trace.
     *
     * - `studio-design/openapi-contract-testing/src/` covers the composer-installed location.
     * - `openapi-contract-testing/src/Laravel/`, `…/src/Internal/`, `…/src/PHPUnit/`
     *   cover path-repo / monorepo dev installs where the vendor prefix is absent.
     * - The two Laravel entries cover the testing concerns that always sit
     *   between the trait hook and the user test method.
     *
     * PHPUnit's own internal frames (Assert, Constraint, TestCase) are
     * filtered by PHPUnit's stack-trace formatter when the default result
     * printer renders the failure block, so we leave them in the raw trace.
     * Tools that read `$exception->getTrace()` directly (Sentry, custom
     * reporters) will still see them.
     *
     * @var list<string>
     */
    private const DROP_PATTERNS = [
        '/studio-design/openapi-contract-testing/src/',
        '/openapi-contract-testing/src/Laravel/',
        '/openapi-contract-testing/src/Internal/',
        '/openapi-contract-testing/src/PHPUnit/',
        '/Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php',
        '/Illuminate/Testing/TestResponse.php',
    ];
    private static ?ReflectionProperty $traceProperty = null;

    /**
     * Filter library/framework frames out of an assertion failure's trace
     * and re-throw. If filtering would empty the trace, the original trace
     * is preserved — a clean trace is never worth less debug info than what
     * we started with. If the reflection-based trace rewrite fails for any
     * reason (future PHP / PHPUnit changes the property contract), the
     * original exception still propagates with its full trace; we never
     * mask the contract-test failure with a reflection error.
     *
     * Only `trace` is rewritten. `getFile()` / `getLine()` keep their
     * throw-site values because rewriting them would lie about where the
     * exception was actually constructed — a price programmatic consumers
     * (Sentry, custom reporters) would pay for the default renderer's
     * display preference.
     */
    public static function rethrowWithCleanTrace(AssertionFailedError $exception): never
    {
        /** @var list<array<string, mixed>> $original */
        $original = $exception->getTrace();
        $filtered = self::filterFrames($original);

        if ($filtered !== [] && $filtered !== $original) {
            try {
                self::traceProperty()->setValue($exception, $filtered);
            } catch (Error|ReflectionException) {
                // Reflection contract changed underneath us — fall through
                // and re-throw with the original trace rather than mask the
                // assertion failure with a reflection error.
            }
        }

        throw $exception;
    }

    /**
     * Drop frames whose `file` matches any DROP_PATTERNS entry. Frames without
     * a `file` key (closures, internal calls) are kept — we cannot tell where
     * they originate, and dropping them would risk hiding user code.
     *
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array<string, mixed>>
     */
    public static function filterFrames(array $trace): array
    {
        $kept = [];
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file)) {
                $kept[] = $frame;

                continue;
            }

            $normalized = str_replace('\\', '/', $file);
            $drop = false;
            foreach (self::DROP_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    $drop = true;

                    break;
                }
            }
            if (!$drop) {
                $kept[] = $frame;
            }
        }

        return $kept;
    }

    private static function traceProperty(): ReflectionProperty
    {
        return self::$traceProperty ??= new ReflectionProperty(Exception::class, 'trace');
    }
}
