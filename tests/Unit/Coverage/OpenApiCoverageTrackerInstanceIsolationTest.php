<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

/**
 * Issue #229: structural guarantee that two independently-constructed
 * {@see OpenApiCoverageTracker} instances do not share state. This is the
 * invariant the refactor away from a static singleton buys us; a regression
 * here would put us back in the world where test isolation depended on
 * everyone remembering to call `::reset()` in `setUp`/`tearDown`.
 *
 * Spec loader still uses static configuration — only the tracker is
 * instance-based here, mirroring the scope of the refactor.
 */
final class OpenApiCoverageTrackerInstanceIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function two_instances_do_not_share_recorded_requests(): void
    {
        $a = new OpenApiCoverageTracker();
        $b = new OpenApiCoverageTracker();

        $a->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');

        $this->assertTrue($a->hasAnyCoverageOn('petstore-3.0'));
        $this->assertFalse($b->hasAnyCoverageOn('petstore-3.0'));
    }

    #[Test]
    public function two_instances_do_not_share_recorded_responses(): void
    {
        $a = new OpenApiCoverageTracker();
        $b = new OpenApiCoverageTracker();

        $a->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $this->assertNotSame([], $a->getCoveredOn());
        $this->assertSame([], $b->getCoveredOn());
    }

    #[Test]
    public function export_state_round_trips_through_a_second_instance(): void
    {
        $source = new OpenApiCoverageTracker();
        $source->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $sink = new OpenApiCoverageTracker();
        $sink->importStateOn($source->exportStateOn());

        $this->assertSame($source->getCoveredOn(), $sink->getCoveredOn());
    }

    #[Test]
    public function reset_on_instance_does_not_affect_sibling_instance(): void
    {
        $a = new OpenApiCoverageTracker();
        $b = new OpenApiCoverageTracker();

        $a->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');
        $b->recordRequestOn('petstore-3.0', 'POST', '/v1/pets');

        $a->resetOn();

        $this->assertFalse($a->hasAnyCoverageOn('petstore-3.0'));
        $this->assertTrue($b->hasAnyCoverageOn('petstore-3.0'));
    }

    #[Test]
    public function static_facade_routes_to_the_current_instance(): void
    {
        // resetCurrent() first so the setCurrent() overwrite-guard does not
        // trip on a stateful slot left over from an earlier test in the same
        // process.
        OpenApiCoverageTracker::resetCurrent();
        $injected = new OpenApiCoverageTracker();
        OpenApiCoverageTracker::setCurrent($injected);

        try {
            OpenApiCoverageTracker::recordRequest('petstore-3.0', 'GET', '/v1/pets');

            $this->assertTrue($injected->hasAnyCoverageOn('petstore-3.0'));
        } finally {
            // Drop the slot so the injected instance does not leak into
            // other tests that share the process-global facade.
            OpenApiCoverageTracker::resetCurrent();
        }
    }

    #[Test]
    public function cold_slot_lazily_mints_default_for_static_facade(): void
    {
        // Production scenario: nothing has called setCurrent() and current()
        // has never been touched. The first static-facade call must lazily
        // mint a default rather than fail. Pinned because pre-Issue #229 the
        // tracker was always populated; post-refactor we depend on the
        // ??=  in current() for the host-less call path (CLI tools, unit
        // tests that hit the facade before any setup).
        OpenApiCoverageTracker::resetCurrent();

        try {
            OpenApiCoverageTracker::recordRequest('petstore-3.0', 'GET', '/v1/pets');

            $minted = OpenApiCoverageTracker::current();
            $this->assertTrue($minted->hasAnyCoverageOn('petstore-3.0'));
        } finally {
            OpenApiCoverageTracker::resetCurrent();
        }
    }

    #[Test]
    public function reset_current_drops_the_installed_instance(): void
    {
        // resetCurrent() must return the locator to the "cold" state so the
        // next current() call mints a fresh default — pinning the documented
        // contract on the two-method split.
        OpenApiCoverageTracker::resetCurrent();
        $first = new OpenApiCoverageTracker();
        OpenApiCoverageTracker::setCurrent($first);
        $this->assertSame($first, OpenApiCoverageTracker::current());

        OpenApiCoverageTracker::resetCurrent();
        $afterReset = OpenApiCoverageTracker::current();
        $this->assertNotSame($first, $afterReset, 'resetCurrent must drop the previously installed slot');

        OpenApiCoverageTracker::resetCurrent();
    }
}
