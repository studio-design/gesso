<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

enum ShippingMethodEnum: string
{
    case Post = 'post';
    case Pickup = 'pickup';
}
