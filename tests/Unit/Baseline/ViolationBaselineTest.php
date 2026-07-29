<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationFingerprint;

use function array_map;

class ViolationBaselineTest extends TestCase
{
    #[Test]
    public function add_and_contains_use_fingerprint_identity(): void
    {
        $baseline = new ViolationBaseline();
        $baseline->add(new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'));

        $this->assertTrue($baseline->contains(
            new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'),
        ));
        $this->assertFalse($baseline->contains(
            new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/name', 'type'),
        ));
    }

    #[Test]
    public function duplicate_entries_collapse(): void
    {
        $baseline = new ViolationBaseline();
        $fingerprint = new ViolationFingerprint('front', 'GET', '/v1/pets', null, null, 'response.status', null, null);
        $baseline->add($fingerprint);
        $baseline->add($fingerprint);

        $this->assertSame(1, $baseline->count());
    }

    #[Test]
    public function sorted_orders_entries_deterministically_with_null_before_strings(): void
    {
        $baseline = new ViolationBaseline();
        $rootPointer = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '', 'required');
        $nullPointer = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', null, null);
        $otherSpec = new ViolationFingerprint('admin', 'GET', '/v1/pets', '200', 'application/json', 'response.body', null, null);
        $baseline->add($rootPointer);
        $baseline->add($nullPointer);
        $baseline->add($otherSpec);

        $this->assertSame(
            [$otherSpec->key(), $nullPointer->key(), $rootPointer->key()],
            array_map(static fn(ViolationFingerprint $f): string => $f->key(), $baseline->sorted()),
        );
    }
}
