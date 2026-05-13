<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function restore_error_handler;
use function set_error_handler;

/**
 * Issue #229: structural guarantee that two independently-constructed
 * {@see StrictRequiredTracker} instances do not share state. Mirrors the
 * matching coverage-tracker isolation test — if either tracker regresses
 * back to global mutable state, the suite catches it without depending on
 * a test author remembering to call `::reset()` everywhere.
 */
final class StrictRequiredTrackerInstanceIsolationTest extends TestCase
{
    #[Test]
    public function two_instances_do_not_share_observations(): void
    {
        $a = new StrictRequiredTracker();
        $b = new StrictRequiredTracker();

        $a->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);

        $this->assertSame(['front'], $a->recordedSpecsOn());
        $this->assertSame([], $b->recordedSpecsOn());
    }

    #[Test]
    public function reset_on_instance_does_not_affect_sibling_instance(): void
    {
        $a = new StrictRequiredTracker();
        $b = new StrictRequiredTracker();

        $a->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a'],
        ]);
        $b->recordOn('back', 'GET', '/y', '200', 'application/json', [
            '/' => ['b'],
        ]);

        $a->resetOn();

        $this->assertSame([], $a->recordedSpecsOn());
        $this->assertSame(['back'], $b->recordedSpecsOn());
    }

    #[Test]
    public function export_state_round_trips_through_a_second_instance(): void
    {
        $source = new StrictRequiredTracker();
        $source->recordOn('front', 'GET', '/x', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);

        $sink = new StrictRequiredTracker();
        $sink->importStateOn($source->exportStateOn());

        $this->assertSame(
            $source->getObservationsOn('front'),
            $sink->getObservationsOn('front'),
        );
    }

    #[Test]
    public function static_facade_routes_to_the_current_instance(): void
    {
        // resetCurrent() first so the setCurrent() overwrite-guard does not
        // trip on a stateful slot left over from an earlier test.
        StrictRequiredTracker::resetCurrent();
        $injected = new StrictRequiredTracker();
        StrictRequiredTracker::setCurrent($injected);

        try {
            StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', [
                '/' => ['a'],
            ]);

            $this->assertSame(['front'], $injected->recordedSpecsOn());
        } finally {
            // Drop the slot so the injected instance does not leak.
            StrictRequiredTracker::resetCurrent();
        }
    }

    #[Test]
    public function cold_slot_lazily_mints_default_for_static_facade(): void
    {
        // Production scenario: nothing has called setCurrent() and current()
        // has never been touched. The first static-facade call must lazily
        // mint a default rather than fail.
        StrictRequiredTracker::resetCurrent();

        try {
            StrictRequiredTracker::record('front', 'GET', '/x', '200', 'application/json', [
                '/' => ['a'],
            ]);

            $minted = StrictRequiredTracker::current();
            $this->assertSame(['front'], $minted->recordedSpecsOn());
        } finally {
            StrictRequiredTracker::resetCurrent();
        }
    }

    #[Test]
    public function reset_current_drops_the_installed_instance(): void
    {
        StrictRequiredTracker::resetCurrent();
        $first = new StrictRequiredTracker();
        StrictRequiredTracker::setCurrent($first);
        $this->assertSame($first, StrictRequiredTracker::current());

        StrictRequiredTracker::resetCurrent();
        $afterReset = StrictRequiredTracker::current();
        $this->assertNotSame($first, $afterReset, 'resetCurrent must drop the previously installed slot');

        StrictRequiredTracker::resetCurrent();
    }

    #[Test]
    public function set_current_warns_when_overwriting_stateful_slot(): void
    {
        // Hardening guard: if a caller forgets resetCurrent() between
        // installations, observations accumulated on the outgoing tracker
        // silently drop out of the report. The guard fires E_USER_WARNING
        // exactly in that case so a test author sees what they're losing.
        StrictRequiredTracker::resetCurrent();
        $first = new StrictRequiredTracker();
        $first->recordOn('front', 'GET', '/x', '200', 'application/json', ['/' => ['a']]);
        StrictRequiredTracker::setCurrent($first);

        $captured = null;
        set_error_handler(static function (int $errno, string $message) use (&$captured): bool {
            $captured = $message;

            return true;
        });

        try {
            StrictRequiredTracker::setCurrent(new StrictRequiredTracker());
        } finally {
            restore_error_handler();
            StrictRequiredTracker::resetCurrent();
        }

        $this->assertNotNull($captured, 'overwriting a stateful slot must emit E_USER_WARNING');
        $this->assertStringContainsString('still holds recorded observations', $captured);
    }
}
