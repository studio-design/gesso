<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationFingerprint;

class ViolationBaselineCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        ViolationBaselineCollector::resetCurrent();
        ViolationBaselineEnforcer::resetCurrent();
        parent::tearDown();
    }

    #[Test]
    public function no_collector_is_installed_by_default(): void
    {
        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function set_current_installs_and_reset_removes_the_collector(): void
    {
        $collector = new ViolationBaselineCollector();
        ViolationBaselineCollector::setCurrent($collector);

        $this->assertSame($collector, ViolationBaselineCollector::current());

        ViolationBaselineCollector::resetCurrent();

        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function record_accumulates_deduplicated_fingerprints(): void
    {
        $collector = new ViolationBaselineCollector();
        $fingerprint = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type');
        $collector->record($fingerprint);
        $collector->record($fingerprint);

        $this->assertSame(1, $collector->baseline()->count());
        $this->assertTrue($collector->baseline()->contains($fingerprint));
    }

    #[Test]
    public function uncap_lifts_the_cap_only_while_a_collector_or_enforcer_is_installed(): void
    {
        $this->assertSame(20, ViolationBaselineCollector::uncap(20));

        ViolationBaselineCollector::setCurrent(new ViolationBaselineCollector());
        $this->assertSame(0, ViolationBaselineCollector::uncap(20));
        ViolationBaselineCollector::resetCurrent();

        // Enforcement needs the full error list too: a truncated list could
        // hide a new violation behind baselined ones and suppress it.
        ViolationBaselineEnforcer::setCurrent(new ViolationBaselineEnforcer(new ViolationBaseline()));
        $this->assertSame(0, ViolationBaselineCollector::uncap(20));
    }
}
