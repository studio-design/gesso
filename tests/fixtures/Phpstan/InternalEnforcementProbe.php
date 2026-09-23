<?php

declare(strict_types=1);

// Phpstan regression probe for the @internal enforcement contract.
//
// This file deliberately lives OUTSIDE the `Studio` root namespace
// (the boundary PHPStan's bleedingEdge `internalTag` rule uses) so each
// statement below trips a different `*.internalClass` rule. The
// inline ignore directives suppress those rules, so a healthy PHPStan
// run stays green.
//
// `reportUnmatchedIgnoredErrors` (on by default under bleedingEdge)
// flips the script: if the enforcement ever stops firing — someone
// removes the bleedingEdge include, downgrades phpstan/phpstan past
// 2.1.13, or adds a too-broad `ignoreErrors` pattern — the suppressions
// here become "unmatched" and CI fails loudly. That's the only purpose
// of this file.
//
// This is a fixture, not autoloaded code. Composer's PSR-4 mapping for
// `tests/` would resolve to `Studio\Gesso\Tests\...`,
// which would defeat the boundary-crossing premise; the
// `Acme\PhpstanProbe` namespace keeps the file scannable by PHPStan
// while invisible to autoloaders.

namespace Acme\PhpstanProbe;

use Studio\Gesso\Coverage\CoverageMergeCommand;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;

final class InternalEnforcementProbe
{
    /**
     * @return array<string, mixed>
     */
    public function probeStaticMethodCall(): array
    {
        /** @phpstan-ignore staticMethod.internalClass */
        return OpenApiSchemaConverter::convert([]);
    }

    public function probeAnotherStaticMethodCall(): bool
    {
        /** @phpstan-ignore staticMethod.internalClass */
        return ContentTypeMatcher::isJsonContentType('application/json');
    }

    /**
     * @phpstan-ignore return.internalClass
     */
    public function probeReturnTypeHint(): CoverageMergeCommand
    {
        /** @phpstan-ignore new.internalClass, method.internalClass */
        return new CoverageMergeCommand();
    }

    /**
     * @phpstan-ignore parameter.internalClass
     */
    public function probeParameterTypeHint(CoverageMergeCommand $cmd): int
    {
        /** @phpstan-ignore method.internalClass */
        return $cmd->run([]);
    }
}
