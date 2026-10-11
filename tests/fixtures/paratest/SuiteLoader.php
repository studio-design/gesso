<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use Closure;

/**
 * Stand-in for paratest's SuiteLoader, which bootstraps PHPUnit extensions in
 * the parallel orchestrator. OpenApiCoverageExtension looks for this class on
 * the call stack to recognise Pest's sealed-facade orchestrator (#598).
 */
final class SuiteLoader
{
    public function __construct(Closure $bootstrap)
    {
        $bootstrap();
    }
}
