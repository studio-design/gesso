<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use PHPUnit\Event\Event;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Tracer\Tracer;

use function implode;
use function sprintf;

/**
 * Observes whether every test in the run actually completed cleanly
 * (issue #402 review). Partial-run detection only reads PHPUnit's
 * *selection* signals (--filter, --testsuite, …); a test that fails,
 * errors, is skipped at runtime (e.g. a failed @depends), or is marked
 * incomplete means later assertions may never have executed — so a
 * baseline entry that went unhit proves nothing and must not be reported
 * as stale. This is especially sharp under --stop-on-failure, where one
 * new violation would otherwise mark every later baselined entry as
 * removable.
 *
 * Registered as a tracer (not per-event subscribers) because PHPUnit's
 * subscriber interfaces declare incompatible `notify()` signatures, so one
 * class cannot implement several of them.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class TestRunDefectTracer implements Tracer
{
    private int $errored = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private int $incomplete = 0;

    public function trace(Event $event): void
    {
        match (true) {
            $event instanceof Errored => $this->errored++,
            $event instanceof Failed => $this->failed++,
            $event instanceof Skipped => $this->skipped++,
            $event instanceof MarkedIncomplete => $this->incomplete++,
            default => null,
        };
    }

    public function hasDefects(): bool
    {
        return $this->errored + $this->failed + $this->skipped + $this->incomplete > 0;
    }

    /** Non-zero defect kinds as a compact list, e.g. `2 failed, 1 skipped`. */
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

        return implode(', ', $parts);
    }
}
