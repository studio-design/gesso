<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Pest;

use RuntimeException;

use function sprintf;

/**
 * Static dispatch target for the Pest custom expectations registered in
 * {@see Autoload.php}. Centralising the implementation here (rather than
 * inlining it inside the closure) gives us a real call site that PHPStan
 * and PHPUnit-driven Pest tests can introspect, and lets PR2 land the real
 * validator orchestration without touching the autoload boundary.
 *
 * The methods are stubs in PR1 — they intentionally throw so the smoke
 * test in `tests/Integration/Pest/PluginLoadsTest.php` can prove that
 * (a) the autoload entrypoint registered the expectation under the
 * expected name and (b) the dispatch reaches this class. PR2 replaces
 * the throw with the real `OpenApiResponseValidator` /
 * `OpenApiRequestValidator` orchestration and the `ValidatesOpenApiSchema`
 * public bridge call.
 */
final class Expectations
{
    public const NOT_IMPLEMENTED_URL = 'https://github.com/studio-design/openapi-contract-testing/issues/109';

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
        throw new RuntimeException(self::notImplementedMessage(__METHOD__));
    }

    public static function matchRequest(
        mixed $value,
        ?string $spec,
        ?string $method,
        ?string $path,
    ): void {
        throw new RuntimeException(self::notImplementedMessage(__METHOD__));
    }

    /**
     * Build the stub error message identifying which dispatch was hit.
     * Carrying __METHOD__ lets a clipped CI log still pinpoint matchResponse
     * vs matchRequest. The constant URL is the single source of truth for
     * the tracking issue so PR2 only deletes call sites, not literals.
     */
    private static function notImplementedMessage(string $method): string
    {
        return sprintf(
            'The Pest plugin entrypoint loaded, but %s lands in PR2. See %s.',
            $method,
            self::NOT_IMPLEMENTED_URL,
        );
    }
}
