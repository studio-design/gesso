<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarEnvelope;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarReader;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarWriter;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function array_map;
use function file_put_contents;
use function getmypid;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Pins the contract between the worker-side sidecar writer and the CLI-side
 * reader. The two sides exchange a JSON file; if the writer's atomicity or
 * the reader's filename pattern drifts, parallel coverage breaks silently.
 */
class CoverageSidecarTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        $this->tmpDir = sys_get_temp_dir() . '/openapi-coverage-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        if (is_dir($this->tmpDir)) {
            $entries = glob($this->tmpDir . '/*') ?: [];
            foreach ($entries as $entry) {
                @unlink($entry);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function writer_writes_a_filename_with_test_token_and_pid(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $path = CoverageSidecarWriter::write(
            sidecarDir: $this->tmpDir,
            testToken: '3',
            state: OpenApiCoverageTracker::exportState(),
        );

        $this->assertStringStartsWith($this->tmpDir . '/', $path);
        $this->assertStringContainsString('part-3-', $path);
        $this->assertStringEndsWith('.json', $path);
        $this->assertStringContainsString('-' . (string) getmypid() . '.json', $path);
    }

    #[Test]
    public function writer_creates_sidecar_dir_when_missing(): void
    {
        $nested = $this->tmpDir . '/nested-dir';
        $this->assertDirectoryDoesNotExist($nested);

        $path = CoverageSidecarWriter::write(
            sidecarDir: $nested,
            testToken: '1',
            state: ['version' => 1, 'specs' => []],
        );

        $this->assertDirectoryExists($nested);
        $this->assertStringStartsWith($nested . '/', $path);

        @unlink($path);
        @rmdir($nested);
    }

    #[Test]
    public function reader_round_trips_state_via_filesystem(): void
    {
        // Capture worker A's recorded state.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $stateA = OpenApiCoverageTracker::exportState();

        // Capture worker B's recorded state.
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            '201',
            'application/json',
            schemaValidated: true,
        );
        $stateB = OpenApiCoverageTracker::exportState();

        CoverageSidecarWriter::write($this->tmpDir, '1', $stateA);
        CoverageSidecarWriter::write($this->tmpDir, '2', $stateB);

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(2, $loaded);

        // Merge both into a fresh tracker — must equal sequential recording.
        OpenApiCoverageTracker::reset();
        foreach ($loaded as $payload) {
            OpenApiCoverageTracker::importState($payload);
        }
        $merged = OpenApiCoverageTracker::exportState();
        $this->assertArrayHasKey('GET /v1/pets', $merged['specs']['petstore-3.0']);
        $this->assertArrayHasKey('POST /v1/pets', $merged['specs']['petstore-3.0']);
    }

    #[Test]
    public function reader_round_trips_envelope_with_strict_required_observations(): void
    {
        // Two workers observe the same endpoint with intersecting key sets.
        // After merging via the envelope, the tracker must hold the
        // intersection-of-intersections — pin that the sidecar carries the
        // strict_required half end-to-end.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        StrictRequiredTracker::record('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', ['/' => ['id', 'name', 'tag']]);
        $envelopeA = CoverageSidecarEnvelope::build(
            OpenApiCoverageTracker::exportState(),
            StrictRequiredTracker::exportState(),
        );

        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();

        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        StrictRequiredTracker::record('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', ['/' => ['id', 'name']]);
        $envelopeB = CoverageSidecarEnvelope::build(
            OpenApiCoverageTracker::exportState(),
            StrictRequiredTracker::exportState(),
        );

        CoverageSidecarWriter::write($this->tmpDir, '1', $envelopeA);
        CoverageSidecarWriter::write($this->tmpDir, '2', $envelopeB);

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(2, $loaded);

        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        foreach ($loaded as $payload) {
            $parsed = CoverageSidecarEnvelope::parse($payload);
            OpenApiCoverageTracker::importState($parsed['coverage']);
            $this->assertNotNull($parsed['strictRequired']);
            StrictRequiredTracker::importState($parsed['strictRequired']);
        }

        $observed = StrictRequiredTracker::getObservations('petstore-3.0');
        $this->assertArrayHasKey('GET /v1/pets', $observed);
        $row = $observed['GET /v1/pets']['200:application/json'];
        $this->assertSame(2, $row['hits']);
        // Intersection: A had [id, name, tag], B had [id, name] → [id, name].
        $this->assertSame(['/' => ['id', 'name']], $row['pointers']);
    }

    #[Test]
    public function reader_still_loads_legacy_v1_payloads(): void
    {
        // Worker on an older library version writes a bare v1 coverage
        // payload (no envelopeVersion). The reader returns it verbatim;
        // the envelope parser yields a null strictRequired half so the
        // merge CLI can mix versioned sidecars during an upgrade window.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $legacyPayload = OpenApiCoverageTracker::exportState();

        CoverageSidecarWriter::write($this->tmpDir, '1', $legacyPayload);

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(1, $loaded);

        $parsed = CoverageSidecarEnvelope::parse($loaded[0]);
        $this->assertSame($legacyPayload, $parsed['coverage']);
        $this->assertNull($parsed['strictRequired']);
    }

    #[Test]
    public function reader_only_picks_up_part_prefixed_files(): void
    {
        // A sidecar plus an unrelated json — only the sidecar should be read.
        CoverageSidecarWriter::write($this->tmpDir, '1', ['version' => 1, 'specs' => []]);
        file_put_contents($this->tmpDir . '/coverage-report.md', '# unrelated');
        file_put_contents($this->tmpDir . '/other.json', '{"ignored":true}');

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(1, $loaded);
    }

    #[Test]
    public function reader_returns_empty_array_when_dir_is_missing(): void
    {
        $loaded = CoverageSidecarReader::readDir($this->tmpDir . '/does-not-exist');
        $this->assertSame([], $loaded);
    }

    #[Test]
    public function reader_throws_on_malformed_json(): void
    {
        file_put_contents($this->tmpDir . '/part-1-9999.json', '{not valid json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed to decode sidecar');

        CoverageSidecarReader::readDir($this->tmpDir);
    }

    #[Test]
    public function reader_distinguishes_sidecars_from_failure_markers(): void
    {
        // The merge CLI reads sidecars and failure markers separately —
        // markers fail loudly, sidecars decode and merge. Pin that the
        // reader's filename patterns don't bleed into each other.
        CoverageSidecarWriter::write($this->tmpDir, '1', ['version' => 1, 'specs' => []]);
        CoverageSidecarWriter::writeFailureMarker($this->tmpDir, '2', 'simulated failure');

        $sidecars = CoverageSidecarReader::listPaths($this->tmpDir);
        $markers = CoverageSidecarReader::listFailureMarkerPaths($this->tmpDir);

        $this->assertCount(1, $sidecars);
        $this->assertCount(1, $markers);
        $this->assertStringContainsString('part-1-', $sidecars[0]);
        $this->assertStringContainsString('failed-2-', $markers[0]);
    }

    #[Test]
    public function reader_lists_paths_for_cleanup(): void
    {
        $a = CoverageSidecarWriter::write($this->tmpDir, '1', ['version' => 1, 'specs' => []]);
        $b = CoverageSidecarWriter::write($this->tmpDir, '2', ['version' => 1, 'specs' => []]);

        $paths = CoverageSidecarReader::listPaths($this->tmpDir);
        sort($paths);
        $expected = [$a, $b];
        sort($expected);
        // Compare via realpath both sides — macOS resolves /var → /private/var
        // so the writer's logical path and the glob's resolved path can diverge.
        $this->assertSame(array_map('realpath', $expected), array_map('realpath', $paths));
    }
}
