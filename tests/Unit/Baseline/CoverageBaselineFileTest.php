<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\CoverageBaseline;
use Studio\Gesso\Baseline\CoverageBaselineEntry;
use Studio\Gesso\Baseline\CoverageBaselineFile;

use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class CoverageBaselineFileTest extends TestCase
{
    #[Test]
    public function render_produces_a_sorted_versioned_document_with_a_trailing_newline(): void
    {
        $baseline = new CoverageBaseline();
        $baseline->add(new CoverageBaselineEntry('front', 'POST', '/v1/pets', '201', 'application/json'));
        $baseline->add(new CoverageBaselineEntry('front', 'GET', '/v1/pets', '500', '*'));

        $rendered = CoverageBaselineFile::render($baseline);

        $this->assertStringEndsWith("\n", $rendered);
        $decoded = json_decode($rendered, true);
        $this->assertSame(1, $decoded['coverage_baseline_version']);
        $this->assertSame([
            ['spec' => 'front', 'method' => 'GET', 'path' => '/v1/pets', 'status' => '500', 'content_type' => '*'],
            ['spec' => 'front', 'method' => 'POST', 'path' => '/v1/pets', 'status' => '201', 'content_type' => 'application/json'],
        ], $decoded['uncovered_responses']);
    }

    #[Test]
    public function render_is_byte_identical_regardless_of_insertion_order(): void
    {
        $a = new CoverageBaseline();
        $a->add(new CoverageBaselineEntry('front', 'GET', '/a', '200', '*'));
        $a->add(new CoverageBaselineEntry('front', 'GET', '/b', '200', '*'));

        $b = new CoverageBaseline();
        $b->add(new CoverageBaselineEntry('front', 'GET', '/b', '200', '*'));
        $b->add(new CoverageBaselineEntry('front', 'GET', '/a', '200', '*'));

        $this->assertSame(CoverageBaselineFile::render($a), CoverageBaselineFile::render($b));
    }

    #[Test]
    public function parse_round_trips_a_rendered_document(): void
    {
        $baseline = new CoverageBaseline();
        $baseline->add(new CoverageBaselineEntry('front', 'DELETE', '/v1/pets/{id}', '204', '*'));

        $parsed = CoverageBaselineFile::parse(CoverageBaselineFile::render($baseline));

        $this->assertSame(1, $parsed->count());
        $this->assertTrue($parsed->contains(new CoverageBaselineEntry('front', 'DELETE', '/v1/pets/{id}', '204', '*')));
    }

    #[Test]
    public function parse_normalizes_hand_edited_fixed_method_casing(): void
    {
        $parsed = CoverageBaselineFile::parse((string) json_encode([
            'coverage_baseline_version' => 1,
            'uncovered_responses' => [
                ['spec' => 'front', 'method' => 'get', 'path' => '/v1/pets', 'status' => '200', 'content_type' => '*'],
            ],
        ]));

        $this->assertTrue($parsed->contains(new CoverageBaselineEntry('front', 'GET', '/v1/pets', '200', '*')));
    }

    #[Test]
    public function parse_keeps_custom_additional_operation_method_casing_distinct(): void
    {
        $parsed = CoverageBaselineFile::parse((string) json_encode([
            'coverage_baseline_version' => 1,
            'uncovered_responses' => [
                ['spec' => 'front', 'method' => 'COPY', 'path' => '/v1/pets', 'status' => '200', 'content_type' => '*'],
            ],
        ]));

        $this->assertTrue($parsed->contains(new CoverageBaselineEntry('front', 'COPY', '/v1/pets', '200', '*')));
        $this->assertFalse($parsed->contains(new CoverageBaselineEntry('front', 'copy', '/v1/pets', '200', '*')));
    }

    #[Test]
    public function parse_accepts_an_empty_content_type(): void
    {
        $parsed = CoverageBaselineFile::parse((string) json_encode([
            'coverage_baseline_version' => 1,
            'uncovered_responses' => [
                ['spec' => 'front', 'method' => 'GET', 'path' => '/v1/pets', 'status' => '200', 'content_type' => ''],
            ],
        ]));

        $this->assertSame(1, $parsed->count());
    }

    #[Test]
    public function parse_rejects_invalid_json(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Coverage baseline file is not valid JSON');

        CoverageBaselineFile::parse('{');
    }

    #[Test]
    public function parse_rejects_an_unknown_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported coverage_baseline_version');

        CoverageBaselineFile::parse((string) json_encode([
            'coverage_baseline_version' => 99,
            'uncovered_responses' => [],
        ]));
    }

    #[Test]
    public function parse_rejects_a_missing_responses_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"uncovered_responses" must be an array');

        CoverageBaselineFile::parse((string) json_encode(['coverage_baseline_version' => 1]));
    }

    #[Test]
    public function parse_rejects_an_unknown_entry_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown field(s): note');

        CoverageBaselineFile::parse((string) json_encode([
            'coverage_baseline_version' => 1,
            'uncovered_responses' => [
                ['spec' => 'front', 'method' => 'GET', 'path' => '/a', 'status' => '200', 'content_type' => '*', 'note' => 'x'],
            ],
        ]));
    }

    #[Test]
    public function parse_rejects_a_missing_required_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('field "status" must be a non-empty string');

        CoverageBaselineFile::parse((string) json_encode([
            'coverage_baseline_version' => 1,
            'uncovered_responses' => [
                ['spec' => 'front', 'method' => 'GET', 'path' => '/a', 'content_type' => '*'],
            ],
        ]));
    }

    #[Test]
    public function parse_rejects_a_non_string_content_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('field "content_type" must be a string');

        CoverageBaselineFile::parse((string) json_encode([
            'coverage_baseline_version' => 1,
            'uncovered_responses' => [
                ['spec' => 'front', 'method' => 'GET', 'path' => '/a', 'status' => '200', 'content_type' => 42],
            ],
        ]));
    }

    #[Test]
    public function read_rejects_a_missing_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not read coverage baseline file');

        CoverageBaselineFile::read(sys_get_temp_dir() . '/gesso-coverage-baseline-does-not-exist.json');
    }

    #[Test]
    public function write_then_read_round_trips(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'gesso-coverage-baseline');

        try {
            $baseline = new CoverageBaseline();
            $baseline->add(new CoverageBaselineEntry('front', 'GET', '/v1/pets', 'default', 'application/problem+json'));
            CoverageBaselineFile::write($path, $baseline);

            $this->assertSame(1, CoverageBaselineFile::read($path)->count());
        } finally {
            @unlink($path);
        }
    }
}
