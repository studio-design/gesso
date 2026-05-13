<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredReport;

final class StrictRequiredReportTest extends TestCase
{
    #[Test]
    public function has_drift_returns_true_when_missing_list_non_empty(): void
    {
        $report = new StrictRequiredReport(
            specName: 'front',
            method: 'GET',
            path: '/projects/{id}',
            statusKey: '200',
            contentTypeKey: 'application/json',
            missingFromRequired: ['created_at'],
            hits: 3,
        );

        $this->assertTrue($report->hasDrift());
    }

    #[Test]
    public function has_drift_returns_false_when_missing_list_empty(): void
    {
        $report = new StrictRequiredReport(
            specName: 'front',
            method: 'GET',
            path: '/projects/{id}',
            statusKey: '200',
            contentTypeKey: 'application/json',
            missingFromRequired: [],
            hits: 1,
        );

        $this->assertFalse($report->hasDrift());
    }

    #[Test]
    public function schema_pointer_defaults_to_root(): void
    {
        $report = new StrictRequiredReport(
            specName: 'front',
            method: 'GET',
            path: '/x',
            statusKey: '200',
            contentTypeKey: 'application/json',
            missingFromRequired: ['a'],
            hits: 1,
        );

        $this->assertSame('/', $report->schemaPointer);
    }

    #[Test]
    public function schema_pointer_round_trips_when_provided(): void
    {
        $report = new StrictRequiredReport(
            specName: 'front',
            method: 'GET',
            path: '/x',
            statusKey: '200',
            contentTypeKey: 'application/json',
            missingFromRequired: ['created_at'],
            hits: 1,
            schemaPointer: '/data/items[*]',
        );

        $this->assertSame('/data/items[*]', $report->schemaPointer);
        $this->assertTrue($report->hasDrift());
    }

    #[Test]
    public function constructor_rejects_zero_hits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StrictRequiredReport requires hits >= 1');

        new StrictRequiredReport(
            specName: 'front',
            method: 'GET',
            path: '/x',
            statusKey: '200',
            contentTypeKey: 'application/json',
            missingFromRequired: ['a'],
            hits: 0,
        );
    }

    #[Test]
    public function constructor_rejects_negative_hits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StrictRequiredReport(
            specName: 'front',
            method: 'GET',
            path: '/x',
            statusKey: '200',
            contentTypeKey: 'application/json',
            missingFromRequired: [],
            hits: -1,
        );
    }

    #[Test]
    public function constructor_rejects_empty_schema_pointer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty schemaPointer');

        new StrictRequiredReport(
            specName: 'front',
            method: 'GET',
            path: '/x',
            statusKey: '200',
            contentTypeKey: 'application/json',
            missingFromRequired: ['a'],
            hits: 1,
            schemaPointer: '',
        );
    }
}
