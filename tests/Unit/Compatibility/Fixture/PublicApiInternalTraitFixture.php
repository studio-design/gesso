<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility\Fixture;

/**
 * @internal Fixture trait excluded from the recorded trait list of consumers.
 */
trait PublicApiInternalTraitFixture
{
    private function internalHelper(): void {}
}
