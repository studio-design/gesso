<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use PHPUnit\Event\Event;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestSuite\Skipped as TestSuiteSkipped;
use PHPUnit\Event\Tracer\Tracer;

use function implode;
use function sprintf;

/**
 * Observes whether every planned test in the run actually completed
 * cleanly (issue #402 review). Partial-run detection only reads PHPUnit's
 * *selection* signals (--filter, --testsuite, …); at runtime the suite can
 * still be truncated — so a baseline entry that went unhit proves nothing
 * and must not be reported as stale.
 *
 * Two complementary signals:
 *
 *  - The planned test count from `TestRunner\ExecutionStarted` compared
 *    against the number of `Test\Finished` events. This catches every
 *    interruption path regardless of reason — --stop-on-failure as well as
 *    --stop-on-warning / -risky / -deprecation / -notice / -defect, and
 *    class-hook failures that prevent tests from running at all.
 *  - The defect outcomes themselves (failed / errored / skipped /
 *    incomplete tests, plus `TestSuite\Skipped` for class-level requirement
 *    skips): even when every planned test "finished", a failed test's
 *    later assertions never ran.
 *
 * Registered as a tracer (not per-event subscribers) because PHPUnit's
 * subscriber interfaces declare incompatible `notify()` signatures, so one
 * class cannot implement several of them.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class TestRunCompletionTracer implements Tracer
{
    private ?int $plannedTests = null;
    private int $finishedTests = 0;
    private int $errored = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private int $incomplete = 0;
    private int $suitesSkipped = 0;

    public function trace(Event $event): void
    {
        match (true) {
            $event instanceof ExecutionStarted => $this->plannedTests = $event->testSuite()->count(),
            $event instanceof Finished => $this->finishedTests++,
            $event instanceof Errored => $this->errored++,
            $event instanceof Failed => $this->failed++,
            $event instanceof Skipped => $this->skipped++,
            $event instanceof MarkedIncomplete => $this->incomplete++,
            $event instanceof TestSuiteSkipped => $this->suitesSkipped++,
            default => null,
        };
    }

    public function completedCleanly(): bool
    {
        return $this->plannedTests !== null &&
            $this->finishedTests === $this->plannedTests &&
            $this->errored + $this->failed + $this->skipped + $this->incomplete === 0 &&
            $this->suitesSkipped === 0;
    }

    /**
     * Why the run is not clean, as a compact list — e.g.
     * `2 failed, 1 skipped, 3 of 4 tests finished`.
     */
    public function describe(): string
    {
        $parts = [];
        foreach ([
            'errored' => $this->errored,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'incomplete' => $this->incomplete,
        ] as $kind => $count) {
            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, $kind);
            }
        }
        if ($this->suitesSkipped > 0) {
            $parts[] = sprintf('%d test suite(s) skipped', $this->suitesSkipped);
        }
        if ($this->plannedTests === null) {
            $parts[] = 'test plan unknown';
        } elseif ($this->finishedTests !== $this->plannedTests) {
            $parts[] = sprintf('%d of %d tests finished', $this->finishedTests, $this->plannedTests);
        }

        return implode(', ', $parts);
    }
}
