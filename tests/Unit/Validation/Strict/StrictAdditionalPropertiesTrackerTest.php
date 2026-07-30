<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Strict;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesAsserter;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;

final class StrictAdditionalPropertiesTrackerTest extends TestCase
{
    #[Test]
    public function export_import_merges_hits_and_clean_evaluation_counts(): void
    {
        $workerA = new StrictAdditionalPropertiesTracker();
        $workerA->recordOn('front', 'GET', '/users', '200', 'application/json', [
            '/trace_id' => 'trace_id',
        ]);
        $workerA->recordOn('front', 'GET', '/clean', '200', 'application/json', []);

        $workerB = new StrictAdditionalPropertiesTracker();
        $workerB->recordOn('front', 'GET', '/users', '200', 'application/json', [
            '/trace_id' => 'trace_id',
        ]);

        $merged = new StrictAdditionalPropertiesTracker();
        $merged->importStateOn($workerA->exportStateOn());
        $merged->importStateOn($workerB->exportStateOn());

        $reports = StrictAdditionalPropertiesAsserter::detectAll($merged);
        $this->assertCount(1, $reports);
        $this->assertSame(2, $reports[0]->hits);
        $this->assertSame(3, $merged->evaluationsOn());
    }

    #[Test]
    public function import_rejects_unknown_state_versions(): void
    {
        $tracker = new StrictAdditionalPropertiesTracker();

        $this->expectException(InvalidArgumentException::class);
        $tracker->importStateOn([
            'version' => 99,
            'evaluations' => 0,
            'observations' => [],
        ]);
    }
}
