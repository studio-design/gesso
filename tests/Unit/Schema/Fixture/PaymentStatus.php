<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
