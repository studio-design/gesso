<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use const DIRECTORY_SEPARATOR;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\CoverageSidecarReader;
use Studio\Gesso\Coverage\CoverageSidecarWriter;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_map;
use function basename;
use function chmod;
use function chown;
use function file_get_contents;
use function file_put_contents;
use function fileowner;
use function fileperms;
use function function_exists;
use function getmypid;
use function glob;
use function is_dir;
use function is_link;
use function mkdir;
use function posix_geteuid;
use function rmdir;
use function sort;
use function sprintf;
use function symlink;
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
        Deprecations::resetForTesting();
        if (is_dir($this->tmpDir)) {
            $entries = glob($this->tmpDir . '/*') ?: [];
            foreach ($entries as $entry) {
                @unlink($entry);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{int}> */
    public static function provideWriter_rejects_a_group_or_world_writable_sidecar_directoryCases(): iterable
    {
        yield 'group writable' => [0o770];
        yield 'world writable' => [0o707];
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
        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($nested);
            $this->assertNotFalse($permissions);
            $this->assertSame(0o700, $permissions & 0o777);
        }

        @unlink($path);
        @rmdir($nested);
    }

    #[Test]
    public function writer_does_not_follow_a_predictable_legacy_tmp_symlink(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('symlink semantics differ on Windows');
        }

        $target = $this->tmpDir . '/sensitive-target';
        file_put_contents($target, 'unchanged');
        $legacyTmp = sprintf('%s/part-slot-%s.json.tmp', $this->tmpDir, (string) getmypid());
        if (!@symlink($target, $legacyTmp)) {
            $this->markTestSkipped('symlink creation is not available');
        }

        $path = CoverageSidecarWriter::write(
            $this->tmpDir,
            'slot',
            ['version' => 1, 'specs' => []],
        );

        $this->assertSame('unchanged', file_get_contents($target));
        $this->assertTrue(is_link($legacyTmp));
        $this->assertFileExists($path);
        $permissions = fileperms($path);
        $this->assertNotFalse($permissions);
        $this->assertSame(0o600, $permissions & 0o777);
    }

    #[Test]
    public function failure_marker_replaces_a_symlink_without_writing_through_it(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('symlink semantics differ on Windows');
        }

        $target = $this->tmpDir . '/marker-target';
        file_put_contents($target, 'unchanged');
        $marker = sprintf('%s/failed-slot-%s.json', $this->tmpDir, (string) getmypid());
        if (!@symlink($target, $marker)) {
            $this->markTestSkipped('symlink creation is not available');
        }

        $path = CoverageSidecarWriter::writeFailureMarker($this->tmpDir, 'slot', 'worker failed');

        $this->assertSame($marker, $path);
        $this->assertSame('unchanged', file_get_contents($target));
        $this->assertFalse(is_link($marker));
        $this->assertJson((string) file_get_contents($marker));
    }

    #[Test]
    #[DataProvider('provideWriter_rejects_a_group_or_world_writable_sidecar_directoryCases')]
    public function writer_rejects_a_group_or_world_writable_sidecar_directory(int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows');
        }

        $this->assertTrue(chmod($this->tmpDir, $mode));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must not be writable by group or other users');
            CoverageSidecarWriter::write($this->tmpDir, 'slot', ['version' => 1, 'specs' => []]);
        } finally {
            chmod($this->tmpDir, 0o755);
        }
    }

    #[Test]
    public function writer_rejects_a_symlink_sidecar_directory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('symlink semantics differ on Windows');
        }

        $link = $this->tmpDir . '-link';
        if (!@symlink($this->tmpDir, $link)) {
            $this->markTestSkipped('symlink creation is not available');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must not be a symbolic link');
            CoverageSidecarWriter::write($link, 'slot', ['version' => 1, 'specs' => []]);
        } finally {
            @unlink($link);
        }
    }

    #[Test]
    public function writer_rejects_a_sidecar_directory_owned_by_another_user(): void
    {
        $dir = $this->directoryOwnedByAnotherUserOrSkip();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sidecar dir must be owned by the current user');
        CoverageSidecarWriter::write($dir, 'slot', ['version' => 1, 'specs' => []]);
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
    public function envelope_round_trips_strict_additional_properties_state(): void
    {
        $tracker = new StrictAdditionalPropertiesTracker();
        $tracker->recordOn('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', [
            '/trace_id' => 'trace_id',
        ]);

        $envelope = CoverageSidecarEnvelope::build(
            coverageState: OpenApiCoverageTracker::exportState(),
            strictRequiredState: StrictRequiredTracker::exportState(),
            strictAdditionalPropertiesState: $tracker->exportStateOn(),
        );
        $this->assertSame(CoverageSidecarEnvelope::ENVELOPE_VERSION, $envelope['envelopeVersion']);

        $parsed = CoverageSidecarEnvelope::parse($envelope);
        $this->assertNotNull($parsed['strictAdditionalProperties']);

        $imported = new StrictAdditionalPropertiesTracker();
        $imported->importStateOn($parsed['strictAdditionalProperties']);
        $this->assertSame(1, $imported->evaluationsOn());
        $this->assertArrayHasKey('GET /v1/pets', $imported->getObservationsOn('petstore-3.0'));
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
    #[DataProvider('provideWriter_rejects_a_group_or_world_writable_sidecar_directoryCases')]
    public function reader_rejects_a_group_or_world_writable_sidecar_directory(int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows');
        }

        $this->assertTrue(chmod($this->tmpDir, $mode));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must not be writable by group or other users');
            CoverageSidecarReader::readDir($this->tmpDir);
        } finally {
            chmod($this->tmpDir, 0o755);
        }
    }

    #[Test]
    public function reader_rejects_a_symlink_sidecar_directory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('symlink semantics differ on Windows');
        }

        $link = $this->tmpDir . '-reader-link';
        if (!@symlink($this->tmpDir, $link)) {
            $this->markTestSkipped('symlink creation is not available');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must not be a symbolic link');
            CoverageSidecarReader::readDir($link);
        } finally {
            @unlink($link);
        }
    }

    #[Test]
    public function reader_rejects_a_symlink_sidecar_file(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('symlink semantics differ on Windows');
        }

        $target = $this->tmpDir . '/attacker-controlled.json';
        file_put_contents($target, '{"version":1,"specs":[]}');
        $link = $this->tmpDir . '/part-1-9999.json';
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('symlink creation is not available');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a regular non-symlink file');

        CoverageSidecarReader::readDir($this->tmpDir);
    }

    #[Test]
    #[DataProvider('provideWriter_rejects_a_group_or_world_writable_sidecar_directoryCases')]
    public function reader_rejects_a_group_or_world_writable_sidecar_file(int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows');
        }

        $path = $this->tmpDir . '/part-1-9999.json';
        file_put_contents($path, '{"version":1,"specs":[]}');
        $this->assertTrue(chmod($path, $mode));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('sidecar must not be writable by group or other users');
            CoverageSidecarReader::readDir($this->tmpDir);
        } finally {
            chmod($path, 0o600);
        }
    }

    #[Test]
    public function reader_rejects_a_sidecar_directory_owned_by_another_user(): void
    {
        $dir = $this->directoryOwnedByAnotherUserOrSkip();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sidecar dir must be owned by the current user');
        CoverageSidecarReader::readDir($dir);
    }

    #[Test]
    public function reader_rejects_a_sidecar_file_owned_by_another_user(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('planting a file owned by another user requires root on POSIX');
        }

        $path = $this->tmpDir . '/part-1-9999.json';
        file_put_contents($path, '{"version":1,"specs":[]}');
        $this->assertTrue(chmod($path, 0o644));
        if (!@chown($path, 65534)) {
            $this->markTestSkipped('chown to another user is not available');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sidecar must be owned by the current user');
        CoverageSidecarReader::readDir($this->tmpDir);
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

    #[Test]
    public function a_run_id_is_sanitised_into_the_filename_and_read_back(): void
    {
        $sidecar = CoverageSidecarWriter::write($this->tmpDir, '1', ['version' => 1, 'specs' => []], runId: 'ci/7 a');
        $marker = CoverageSidecarWriter::writeFailureMarker($this->tmpDir, '2', 'simulated failure', runId: 'ci/7 a');

        $this->assertMatchesRegularExpression('/^part-1-\d+\.ci_7_a\.json$/', basename($sidecar));
        $this->assertNotNull($marker);
        $this->assertMatchesRegularExpression('/^failed-2-\d+\.ci_7_a\.json$/', basename($marker));
        $this->assertSame('ci_7_a', CoverageSidecarReader::runIdOf($sidecar));
        $this->assertSame('ci_7_a', CoverageSidecarReader::runIdOf($marker));
        $this->assertNull(CoverageSidecarReader::runIdOf($this->tmpDir . '/part-1-4242.json'));
        $this->assertSame(['version' => 1, 'specs' => []], CoverageSidecarReader::read($sidecar));
    }

    /**
     * A non-group/world-writable directory owned by someone else, so only the
     * ownership check can reject it. As root the test dir is handed to
     * `nobody`; otherwise the root-owned `/usr` stands in.
     */
    private function directoryOwnedByAnotherUserOrSkip(): string
    {
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('posix_geteuid')) {
            $this->markTestSkipped('ownership checks require POSIX and ext-posix');
        }

        if (posix_geteuid() === 0) {
            if (!@chown($this->tmpDir, 65534)) {
                $this->markTestSkipped('chown to another user is not available');
            }

            return $this->tmpDir;
        }

        $dir = '/usr';
        $permissions = @fileperms($dir);
        if (is_link($dir) || @fileowner($dir) !== 0 || $permissions === false || ($permissions & 0o022) !== 0) {
            $this->markTestSkipped('no root-owned, non-writable directory available');
        }

        return $dir;
    }
}
