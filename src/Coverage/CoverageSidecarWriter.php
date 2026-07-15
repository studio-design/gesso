<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use RuntimeException;

use function file_put_contents;
use function getmypid;
use function is_dir;
use function json_encode;
use function mkdir;
use function preg_replace;
use function rename;
use function rtrim;
use function sprintf;
use function unlink;

/**
 * Writes a paratest worker's coverage state to a JSON sidecar that the
 * merge CLI will later combine into a single report.
 *
 * Filename format: `part-<token>-<pid>.json`. `<token>` identifies the
 * paratest slot (paratest currently uses 1..N integers, but anything is
 * accepted and sanitised); `<pid>` is appended so a leftover sidecar from
 * a crashed previous run cannot be mistaken for the current one.
 *
 * Atomicity: written to a sibling `*.tmp` first, then `rename()`d into place
 * so a partial file is never observable by the reader. POSIX `rename()` is
 * atomic when source and destination live on the same filesystem; on
 * Windows or across filesystem boundaries the underlying `MoveFileEx` /
 * copy+unlink fallback may not provide the same guarantee. Configure
 * `sidecar_dir` to live alongside the merge target (same FS) to keep the
 * atomic guarantee.
 *
 * @internal PHPUnit extension implementation detail. Do not use from user code.
 */
final class CoverageSidecarWriter
{
    public const FAILURE_MARKER_PREFIX = 'failed-';
    public const FILENAME_PREFIX = 'part-';
    public const FILENAME_SUFFIX = '.json';

    private function __construct() {}

    /**
     * @param array<string, mixed> $state from {@see OpenApiCoverageTracker::exportState()}
     *
     * @throws RuntimeException when the sidecar cannot be created or persisted
     */
    public static function write(string $sidecarDir, string $testToken, array $state): string
    {
        $dir = self::ensureDir($sidecarDir);
        $finalPath = self::pathFor($dir, self::FILENAME_PREFIX, $testToken);
        $tmpPath = $finalPath . '.tmp';

        try {
            $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('failed to encode coverage state: %s', $e->getMessage()), 0, $e);
        }

        $written = @file_put_contents($tmpPath, $payload);
        if ($written === false) {
            throw new RuntimeException(sprintf('failed to write sidecar tmp file "%s"', $tmpPath));
        }

        if (!@rename($tmpPath, $finalPath)) {
            // Best-effort cleanup; the rename failure is the primary error.
            @unlink($tmpPath);

            throw new RuntimeException(sprintf('failed to rename sidecar "%s" -> "%s"', $tmpPath, $finalPath));
        }

        return $finalPath;
    }

    /**
     * Drop a marker file when {@see self::write()} fails. The merge CLI
     * detects markers and exits non-zero rather than silently producing an
     * under-counted report from N-1 workers.
     *
     * Marker is itself written best-effort: if the sidecar dir cannot be
     * created at all, there's nowhere to put a marker either, and the
     * caller falls back to STDERR-only signalling. That's acceptable
     * because the failure mode is already loud (worker can't write to its
     * sidecar dir at all).
     */
    public static function writeFailureMarker(string $sidecarDir, string $testToken, string $reason): ?string
    {
        try {
            $dir = self::ensureDir($sidecarDir);
        } catch (RuntimeException) {
            return null;
        }
        $path = self::pathFor($dir, self::FAILURE_MARKER_PREFIX, $testToken);
        $body = json_encode(['testToken' => $testToken, 'reason' => $reason]);
        if ($body === false || @file_put_contents($path, $body) === false) {
            return null;
        }

        return $path;
    }

    /**
     * @throws RuntimeException
     */
    private static function ensureDir(string $sidecarDir): string
    {
        $dir = rtrim($sidecarDir, '/' . DIRECTORY_SEPARATOR);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, recursive: true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('failed to create sidecar dir "%s"', $dir));
        }

        return $dir;
    }

    /**
     * @throws RuntimeException when the runtime cannot return a PID
     */
    private static function pathFor(string $dir, string $prefix, string $testToken): string
    {
        $pid = getmypid();
        if ($pid === false) {
            // Fail loudly — colliding sidecars from PID-less workers would
            // silently overwrite each other.
            throw new RuntimeException('getmypid() returned false; cannot derive a unique sidecar filename');
        }

        return sprintf('%s/%s%s-%s%s', $dir, $prefix, self::sanitise($testToken), (string) $pid, self::FILENAME_SUFFIX);
    }

    /**
     * Strip anything that's not safe in a filename so a hostile or malformed
     * `TEST_TOKEN` cannot escape the sidecar dir or break the reader's glob.
     */
    private static function sanitise(string $token): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9_-]/', '_', $token);

        return $cleaned === null || $cleaned === '' ? 'unknown' : $cleaned;
    }
}
