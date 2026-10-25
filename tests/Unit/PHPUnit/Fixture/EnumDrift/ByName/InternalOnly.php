<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit\Fixture\EnumDrift\ByName;

// No component of this name exists in any spec: auto-discovery skips it.
enum InternalOnly: string
{
    case X = 'x';
}
