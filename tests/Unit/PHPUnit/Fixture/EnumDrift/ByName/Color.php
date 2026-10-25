<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit\Fixture\EnumDrift\ByName;

// Matches components.schemas.Color by name; 'black' is PHP-only drift.
enum Color: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
    case Black = 'black';
}
