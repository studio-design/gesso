<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Event\Event;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\Gesso\PHPUnit\TestRunDefectTracer;

class TestRunDefectTracerTest extends TestCase
{
    #[Test]
    public function a_fresh_tracer_reports_no_defects(): void
    {
        $tracer = new TestRunDefectTracer();

        $this->assertFalse($tracer->hasDefects());
    }

    #[Test]
    public function unrelated_events_are_not_defects(): void
    {
        $tracer = new TestRunDefectTracer();
        $tracer->trace($this->event(ExecutionFinished::class));

        $this->assertFalse($tracer->hasDefects());
    }

    #[Test]
    public function failed_errored_skipped_and_incomplete_tests_are_defects(): void
    {
        foreach ([Failed::class, Errored::class, Skipped::class, MarkedIncomplete::class] as $eventClass) {
            $tracer = new TestRunDefectTracer();
            $tracer->trace($this->event($eventClass));

            $this->assertTrue($tracer->hasDefects(), $eventClass . ' must count as a defect');
        }
    }

    #[Test]
    public function describe_summarizes_the_non_zero_defect_kinds(): void
    {
        $tracer = new TestRunDefectTracer();
        $tracer->trace($this->event(Failed::class));
        $tracer->trace($this->event(Failed::class));
        $tracer->trace($this->event(Skipped::class));

        $this->assertSame('2 failed, 1 skipped', $tracer->describe());
    }

    /**
     * @template T of Event
     *
     * @param class-string<T> $eventClass
     *
     * @return T
     */
    private function event(string $eventClass): Event
    {
        return (new ReflectionClass($eventClass))->newInstanceWithoutConstructor();
    }
}
