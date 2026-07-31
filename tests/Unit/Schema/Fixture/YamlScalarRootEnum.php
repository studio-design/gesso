<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/yaml-scalar-root.yaml')]
enum YamlScalarRootEnum: string
{
    case A = 'a';
}
