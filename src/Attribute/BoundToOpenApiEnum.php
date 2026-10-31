<?php

declare(strict_types=1);

namespace Studio\Gesso\Attribute;

use Attribute;
use Studio\Gesso\Schema\EnumDriftAsserter;

/**
 * Bind a backed PHP enum to the `components.schemas` entry named
 * `$component` in the configured specs. Pure marker — carries no runtime
 * behavior outside {@see EnumDriftAsserter}, which reads it via reflection.
 *
 * Needed only when the component is not named after the class: without the
 * attribute the asserter matches by short class name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BoundToOpenApiEnum
{
    public function __construct(
        public readonly string $component,
    ) {}
}
