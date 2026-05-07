<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Schema\EnumDriftDetector;

use function array_keys;

class EnumDriftDetectorTest extends TestCase
{
    #[Test]
    public function reports_no_drift_when_values_match(): void
    {
        $report = EnumDriftDetector::detect(
            enumFqcn: 'App\\Enums\\Color',
            specPath: 'enums/color.json',
            phpValues: ['red', 'green', 'blue'],
            specValues: ['red', 'green', 'blue'],
        );

        $this->assertFalse($report->hasDrift());
        $this->assertSame([], $report->phpOnly);
        $this->assertSame([], $report->specOnly);
        $this->assertSame('App\\Enums\\Color', $report->enumFqcn);
        $this->assertSame('enums/color.json', $report->specPath);
    }

    #[Test]
    public function reports_no_drift_when_values_match_in_different_order(): void
    {
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: ['a', 'b', 'c'],
            specValues: ['c', 'a', 'b'],
        );

        $this->assertFalse($report->hasDrift());
    }

    #[Test]
    public function reports_php_only_values_when_php_has_extras(): void
    {
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: ['a', 'b', 'c', 'd'],
            specValues: ['a', 'b'],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertSame(['c', 'd'], $report->phpOnly);
        $this->assertSame([], $report->specOnly);
    }

    #[Test]
    public function reports_spec_only_values_when_spec_has_extras(): void
    {
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: ['a'],
            specValues: ['a', 'b', 'c'],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertSame([], $report->phpOnly);
        $this->assertSame(['b', 'c'], $report->specOnly);
    }

    #[Test]
    public function reports_drift_in_both_directions_simultaneously(): void
    {
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: ['shared', 'php-only'],
            specValues: ['shared', 'spec-only'],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertSame(['php-only'], $report->phpOnly);
        $this->assertSame(['spec-only'], $report->specOnly);
    }

    #[Test]
    public function treats_string_values_strictly_against_int_values(): void
    {
        // Strict comparison: '1' (string) and 1 (int) must NOT match.
        // The library enforces strict comparisons everywhere; backed PHP enums
        // have either string or int values, never both — so '1' vs 1 is a real
        // type drift the user should know about.
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: ['1', '2'],
            specValues: [1, 2],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertSame(['1', '2'], $report->phpOnly);
        $this->assertSame([1, 2], $report->specOnly);
    }

    #[Test]
    public function reindexes_diff_results_to_zero_based_lists(): void
    {
        // array_diff preserves original keys; the detector must reindex so
        // consumers can json_encode the report cleanly without holes.
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: ['a', 'b', 'c'],
            specValues: ['b'],
        );

        $this->assertSame([0, 1], array_keys($report->phpOnly));
        $this->assertSame(['a', 'c'], $report->phpOnly);
    }

    #[Test]
    public function handles_empty_php_values(): void
    {
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: [],
            specValues: ['a'],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertSame([], $report->phpOnly);
        $this->assertSame(['a'], $report->specOnly);
    }

    #[Test]
    public function handles_empty_spec_values(): void
    {
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: ['a'],
            specValues: [],
        );

        $this->assertTrue($report->hasDrift());
        $this->assertSame(['a'], $report->phpOnly);
        $this->assertSame([], $report->specOnly);
    }

    #[Test]
    public function handles_both_empty(): void
    {
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: [],
            specValues: [],
        );

        $this->assertFalse($report->hasDrift());
    }

    #[Test]
    public function dedupes_php_values_before_diffing(): void
    {
        // Duplicate values cannot occur in real PHP enums (the language
        // forbids it), but a future caller might pass raw input. Dedup
        // defensively so the diff output isn't doubled.
        $report = EnumDriftDetector::detect(
            enumFqcn: 'X',
            specPath: 'x.json',
            phpValues: ['a', 'a', 'b'],
            specValues: ['a', 'b'],
        );

        $this->assertFalse($report->hasDrift());
    }
}
