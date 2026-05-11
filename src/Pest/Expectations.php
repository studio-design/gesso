<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Pest;

use RuntimeException;

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
    private const NOT_IMPLEMENTED_MESSAGE = 'The Pest plugin entrypoint loaded, '
        . 'but the matchResponse/matchRequest implementation lands in PR2. '
        . 'See https://github.com/studio-design/openapi-contract-testing/issues/109.';

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
        throw new RuntimeException(self::NOT_IMPLEMENTED_MESSAGE);
    }

    public static function matchRequest(
        mixed $value,
        ?string $spec,
        ?string $method,
        ?string $path,
    ): void {
        throw new RuntimeException(self::NOT_IMPLEMENTED_MESSAGE);
    }
}
