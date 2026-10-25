<?php

declare(strict_types=1);

namespace Studio\Gesso\Attribute;

use Attribute;
use InvalidArgumentException;
use Studio\Gesso\Schema\EnumDriftAsserter;

/**
 * Bind a backed PHP enum to its OpenAPI `enum` definition. Pure marker —
 * carries no runtime behavior outside {@see EnumDriftAsserter}, which reads
 * it via reflection.
 *
 * - `component: 'Name'` binds to `components.schemas.Name` in the configured
 *   specs. Needed only when the component is not named after the class
 *   (without the attribute the asserter matches by short class name).
 * - A positional spec file path (legacy) is resolved relative to the
 *   configured enum root (`OpenApiSpecLoader::getEnumBasePath()`, falling
 *   back to `OpenApiSpecLoader::getBasePath()`). Removed in 3.0 (#508).
 *
 * Exactly one of the two must be given; the asserter reports a violation as
 * `EnumBindingException` with reason `AttributeInvalid`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BoundToOpenApiEnum
{
    public function __construct(
        /** @deprecated Use `component:`, or no attribute when the component is named after the class. Removed in Gesso 3.0 (#508). */
        public readonly ?string $specPath = null,
        public readonly ?string $component = null,
    ) {
        if (($specPath === null) === ($component === null)) {
            throw new InvalidArgumentException(
                '#[BoundToOpenApiEnum] takes exactly one argument: a components.schemas name via component:, or (deprecated) a spec file path.',
            );
        }
    }
}
