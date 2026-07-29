<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Event\Event;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestSuite\Skipped as TestSuiteSkipped;
use PHPUnit\Event\TestSuite\TestSuite;
use PHPUnit\Event\TestSuite\TestSuiteWithName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Studio\Gesso\PHPUnit\TestRunCompletionTracer;

class TestRunCompletionTracerTest extends TestCase
{
    #[Test]
    public function a_tracer_that_never_saw_the_test_plan_is_not_clean(): void
    {
        $tracer = new TestRunCompletionTracer();

        $this->assertFalse($tracer->completedCleanly());
        $this->assertSame('test plan unknown', $tracer->describe());
    }

    #[Test]
    public function a_run_where_every_planned_test_finished_without_defects_is_clean(): void
    {
        $tracer = new TestRunCompletionTracer();
        $tracer->trace($this->executionStarted(2));
        $tracer->trace($this->event(Finished::class));
        $tracer->trace($this->event(Finished::class));

        $this->assertTrue($tracer->completedCleanly());
    }

    #[Test]
    public function an_interrupted_run_without_outcome_defects_is_not_clean(): void
    {
        // --stop-on-warning / --stop-on-risky / --stop-on-deprecation /
        // --stop-on-notice abort the suite without emitting any of the four
        // outcome-defect events; the planned-vs-finished comparison must
        // catch the truncation regardless of the interruption reason.
        $tracer = new TestRunCompletionTracer();
        $tracer->trace($this->executionStarted(3));
        $tracer->trace($this->event(Finished::class));
        $tracer->trace($this->event(Finished::class));

        $this->assertFalse($tracer->completedCleanly());
        $this->assertSame('2 of 3 tests finished', $tracer->describe());
    }

    #[Test]
    public function failed_errored_skipped_and_incomplete_tests_are_defects_even_on_a_complete_run(): void
    {
        foreach ([Failed::class, Errored::class, Skipped::class, MarkedIncomplete::class] as $eventClass) {
            $tracer = new TestRunCompletionTracer();
            $tracer->trace($this->executionStarted(1));
            $tracer->trace($this->event($eventClass));
            $tracer->trace($this->event(Finished::class));

            $this->assertFalse($tracer->completedCleanly(), $eventClass . ' must count as a defect');
        }
    }

    #[Test]
    public function a_skipped_test_suite_is_not_clean(): void
    {
        // Class-level requirement skips surface as TestSuite\Skipped; their
        // tests never run individually.
        $tracer = new TestRunCompletionTracer();
        $tracer->trace($this->executionStarted(0));
        $tracer->trace($this->event(TestSuiteSkipped::class));

        $this->assertFalse($tracer->completedCleanly());
        $this->assertSame('1 test suite(s) skipped', $tracer->describe());
    }

    #[Test]
    public function describe_combines_defect_kinds_and_the_finished_count(): void
    {
        $tracer = new TestRunCompletionTracer();
        $tracer->trace($this->executionStarted(4));
        $tracer->trace($this->event(Failed::class));
        $tracer->trace($this->event(Failed::class));
        $tracer->trace($this->event(Skipped::class));
        $tracer->trace($this->event(Finished::class));
        $tracer->trace($this->event(Finished::class));
        $tracer->trace($this->event(Finished::class));

        $this->assertSame('2 failed, 1 skipped, 3 of 4 tests finished', $tracer->describe());
    }

    private function executionStarted(int $plannedTests): ExecutionStarted
    {
        $suite = (new ReflectionClass(TestSuiteWithName::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TestSuite::class, 'count'))->setValue($suite, $plannedTests);

        $event = (new ReflectionClass(ExecutionStarted::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ExecutionStarted::class, 'testSuite'))->setValue($event, $suite);

        return $event;
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
