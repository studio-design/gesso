<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Baseline\ViolationFingerprint;

use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class ViolationBaselineFileTest extends TestCase
{
    #[Test]
    public function render_produces_a_sorted_versioned_document_with_a_trailing_newline(): void
    {
        $baseline = new ViolationBaseline();
        $baseline->add(new ViolationFingerprint('front', 'POST', '/v1/pets', null, null, 'request.body', '/name', 'type'));
        $baseline->add(new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'));

        $rendered = ViolationBaselineFile::render($baseline);

        $this->assertStringEndsWith("\n", $rendered);
        $decoded = json_decode($rendered, true);
        $this->assertSame(1, $decoded['baseline_version']);
        $this->assertSame([
            [
                'spec' => 'front',
                'method' => 'GET',
                'path' => '/v1/pets',
                'status_code' => '200',
                'content_type' => 'application/json',
                'category' => 'response.body',
                'parameter' => null,
                'instance_path' => '/data/*/id',
                'keyword' => 'type',
            ],
            [
                'spec' => 'front',
                'method' => 'POST',
                'path' => '/v1/pets',
                'status_code' => null,
                'content_type' => null,
                'category' => 'request.body',
                'parameter' => null,
                'instance_path' => '/name',
                'keyword' => 'type',
            ],
        ], $decoded['violations']);
    }

    #[Test]
    public function render_is_deterministic_regardless_of_insertion_order(): void
    {
        $a = new ViolationBaseline();
        $b = new ViolationBaseline();
        $first = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type');
        $second = new ViolationFingerprint('front', 'POST', '/v1/pets', null, null, 'request.body', '/name', 'type');
        $a->add($first);
        $a->add($second);
        $b->add($second);
        $b->add($first);

        $this->assertSame(ViolationBaselineFile::render($a), ViolationBaselineFile::render($b));
    }

    #[Test]
    public function parse_round_trips_a_rendered_document(): void
    {
        $baseline = new ViolationBaseline();
        $baseline->add(new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'));
        $rendered = ViolationBaselineFile::render($baseline);

        $parsed = ViolationBaselineFile::parse($rendered);

        $this->assertSame($rendered, ViolationBaselineFile::render($parsed));
        $this->assertTrue($parsed->contains(
            new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'),
        ));
    }

    #[Test]
    public function parse_normalizes_hand_edited_entries(): void
    {
        $document = (string) json_encode([
            'baseline_version' => 1,
            'violations' => [[
                'spec' => 'front',
                'method' => 'get',
                'path' => '/v1/pets',
                'status_code' => '200',
                'content_type' => 'application/json',
                'category' => 'response.body',
                'instance_path' => '/data/0/id',
                'keyword' => 'type',
            ]],
        ]);

        $parsed = ViolationBaselineFile::parse($document);

        $this->assertTrue($parsed->contains(
            new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'),
        ));
    }

    #[Test]
    public function parse_rejects_an_unknown_baseline_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('baseline_version');

        ViolationBaselineFile::parse((string) json_encode(['baseline_version' => 2, 'violations' => []]));
    }

    #[Test]
    public function parse_rejects_invalid_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ViolationBaselineFile::parse('{nope');
    }

    #[Test]
    public function parse_rejects_an_entry_with_a_missing_required_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('category');

        ViolationBaselineFile::parse((string) json_encode([
            'baseline_version' => 1,
            'violations' => [[
                'spec' => 'front',
                'method' => 'GET',
                'path' => '/v1/pets',
                'status_code' => null,
                'content_type' => null,
                'instance_path' => null,
                'keyword' => null,
            ]],
        ]));
    }

    #[Test]
    public function parse_rejects_an_entry_with_an_unknown_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('surprise');

        ViolationBaselineFile::parse((string) json_encode([
            'baseline_version' => 1,
            'violations' => [[
                'spec' => 'front',
                'method' => 'GET',
                'path' => '/v1/pets',
                'status_code' => null,
                'content_type' => null,
                'category' => 'response.status',
                'instance_path' => null,
                'keyword' => null,
                'surprise' => true,
            ]],
        ]));
    }

    #[Test]
    public function write_and_read_round_trip_through_the_filesystem(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gesso-baseline-');
        $this->assertNotFalse($path);

        try {
            $baseline = new ViolationBaseline();
            $baseline->add(new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'));
            ViolationBaselineFile::write($path, $baseline);

            $read = ViolationBaselineFile::read($path);

            $this->assertSame(ViolationBaselineFile::render($baseline), ViolationBaselineFile::render($read));
        } finally {
            @unlink($path);
        }
    }
}
