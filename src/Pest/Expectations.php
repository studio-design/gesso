<?php

declare(strict_types=1);

namespace Studio\Gesso\Pest;

use Illuminate\Testing\TestResponse;
use Pest\PendingCalls\TestCall;
use Pest\Support\HigherOrderTapProxy;
use RuntimeException;
use Studio\Gesso\HttpMethod;
use Symfony\Component\HttpFoundation\Request;

use function function_exists;
use function get_debug_type;
use function method_exists;
use function sprintf;
use function strtoupper;

/**
 * Static dispatch target for the Pest custom expectations registered in
 * {@see Autoload.php}. Centralising the implementation here (rather than
 * inlining it inside the closure) gives PHPStan and PHPUnit-driven tests a
 * real call site to introspect, and isolates the autoload boundary from
 * the validator orchestration.
 *
 * The dispatch contract: the running Pest test class must use the
 * `Studio\Gesso\Laravel\ValidatesOpenApiSchema` trait
 * (typically by extending a base `TestCase` that already does, then
 * registering it via `uses(...)->in(...)` in `tests/Pest.php`). Without
 * the trait the bridge methods don't exist and these helpers raise a
 * RuntimeException pointing the user at the standard wiring. Standalone
 * (non-Laravel) Pest support against PSR-7 messages is a follow-up to
 * the Pest plugin epic — see the README's "Pest plugin (Laravel)" section
 * for the documented v1 constraints.
 *
 * @internal Dispatch target for registered Pest expectations. User code calls
 *           the expectations rather than this class directly.
 */
final class Expectations
{
    /**
     * @param string[] $skipResponseCodes
     */
    public static function matchResponse(
        mixed $value,
        ?string $spec,
        ?string $method,
        ?string $path,
        array $skipResponseCodes,
    ): void {
        if (!$value instanceof TestResponse) {
            throw new RuntimeException(sprintf(
                'expect(...)->toMatchOpenApiResponseSchema() requires a %s, got %s. '
                . 'Standalone (non-Laravel) Pest support is tracked separately.',
                TestResponse::class,
                get_debug_type($value),
            ));
        }

        $testCase = self::resolveTestCase('toMatchOpenApiResponseSchema');
        $resolvedMethod = self::resolveHttpMethod($method, 'toMatchOpenApiResponseSchema');

        $testCase->runOpenApiResponseAssertion(
            $value,
            $spec,
            $resolvedMethod,
            $path,
            $skipResponseCodes,
        );
    }

    public static function matchRequest(
        mixed $value,
        ?string $spec,
        ?string $method,
        ?string $path,
    ): void {
        if (!$value instanceof Request) {
            throw new RuntimeException(sprintf(
                'expect(...)->toMatchOpenApiRequestSchema() requires a %s, got %s. '
                . 'In a Laravel test the current request is available via app(\'request\').',
                Request::class,
                get_debug_type($value),
            ));
        }

        $testCase = self::resolveTestCase('toMatchOpenApiRequestSchema');
        $resolvedMethod = self::resolveHttpMethod($method, 'toMatchOpenApiRequestSchema');

        $testCase->runOpenApiRequestAssertion(
            $value,
            $spec,
            $resolvedMethod,
            $path,
        );
    }

    /**
     * Locate the running Pest TestCase via the global `test()` helper and
     * verify it carries the public bridge methods. Failing here means the
     * test class is missing the `ValidatesOpenApiSchema` trait — almost
     * always a `tests/Pest.php` `uses(...)` misconfiguration.
     *
     * Pest's `test()` returns `HigherOrderTapProxy` whenever called with no
     * description argument while a TestCase is active — the proxy forwards
     * to the underlying TestCase via `__call` / `__get` so user code can
     * write `test()->skip(...)` or `test()->throws(...)`. We unwrap it
     * here because `method_exists()` against the proxy would resolve the
     * proxy's `__call` rather than the real bridge method, returning
     * false unconditionally.
     *
     * Pest can also return a `Pest\PendingCalls\TestCall` when `test()` is
     * resolved before any `it(...)` body has been entered (e.g., from a
     * dataset closure or `beforeEach` initialiser). The TestCall has its
     * own method surface and would surface as the misleading "missing
     * trait" message; we detect it explicitly and report the actual
     * environmental cause instead.
     */
    private static function resolveTestCase(string $expectationName): object
    {
        if (!function_exists('test')) {
            throw new RuntimeException(sprintf(
                '%s() was invoked outside a Pest test run (`test()` global is undefined). '
                . 'Custom expectations only run when the Pest CLI is the entrypoint.',
                $expectationName,
            ));
        }

        $testCase = test();
        if ($testCase instanceof HigherOrderTapProxy) {
            /** @var object $testCase */
            $testCase = $testCase->target;
        }

        if ($testCase instanceof TestCall) {
            throw new RuntimeException(sprintf(
                '%s() must be called from inside an it(...) / test(...) body. '
                . '`test()` returned a pending TestCall, which means no PHPUnit '
                . 'TestCase is currently executing (most often this happens when '
                . 'the expectation is invoked from a dataset closure or beforeEach '
                . 'initialiser before any test body has been entered).',
                $expectationName,
            ));
        }

        $bridge = $expectationName === 'toMatchOpenApiResponseSchema'
            ? 'runOpenApiResponseAssertion'
            : 'runOpenApiRequestAssertion';
        if (!method_exists($testCase, $bridge)) {
            throw new RuntimeException(sprintf(
                '%s() requires the test class (%s) to use the %s trait. '
                . 'Add `uses(\Studio\Gesso\Laravel\ValidatesOpenApiSchema::class)->in(...)` '
                . 'to tests/Pest.php, or extend a base TestCase that already uses the trait.',
                $expectationName,
                $testCase::class,
                'Studio\Gesso\Laravel\ValidatesOpenApiSchema',
            ));
        }

        return $testCase;
    }

    /**
     * Coerce the optional method string to the trait's HttpMethod enum.
     * Null passes through (the trait then auto-resolves from the current
     * request); anything else must be one of the supported verbs.
     */
    private static function resolveHttpMethod(?string $method, string $expectationName): ?HttpMethod
    {
        if ($method === null) {
            return null;
        }

        $resolved = HttpMethod::tryFrom(strtoupper($method));
        if ($resolved === null) {
            throw new RuntimeException(sprintf(
                '%s() received unsupported method: %s. Allowed: %s.',
                $expectationName,
                $method,
                HttpMethod::listOfValues(),
            ));
        }

        return $resolved;
    }
}
