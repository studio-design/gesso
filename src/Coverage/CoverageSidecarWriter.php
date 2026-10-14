<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;
use Random\RandomException;
use RuntimeException;

use function bin2hex;
use function chmod;
use function fclose;
use function fflush;
use function fileperms;
use function fopen;
use function fwrite;
use function getmypid;
use function is_dir;
use function is_link;
use function is_resource;
use function json_encode;
use function mkdir;
use function preg_replace;
use function random_bytes;
use function rename;
use function rtrim;
use function sprintf;
use function strlen;
use function substr;
use function sys_get_temp_dir;
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
 * Atomicity and local-user safety: payloads are written to a cryptographically
 * random sibling file opened with exclusive-create semantics, chmodded to
 * `0600`, and then `rename()`d into place. Keeping the temporary file in the
 * sidecar directory prevents cross-filesystem moves and avoids predictable
 * `*.tmp` names that could be replaced with symlinks. POSIX `rename()` is
 * atomic; Windows may not provide the same replacement guarantee when the
 * destination already exists, in which case the write fails loudly.
 *
 * @internal PHPUnit extension implementation detail. Do not use from user code.
 */
final class CoverageSidecarWriter
{
    /** Stable default under the system temp dir, so the merge CLI finds it without coordination. */
    public const DEFAULT_SUBDIR = 'openapi-coverage-sidecars';

    public const FAILURE_MARKER_PREFIX = 'failed-';
    public const FILENAME_PREFIX = 'part-';
    public const FILENAME_SUFFIX = '.json';
    private const TEMP_FILE_ATTEMPTS = 10;
    private const TEMP_FILE_PREFIX = '.gesso-sidecar-';

    private function __construct() {}

    public static function defaultDir(): string
    {
        return sys_get_temp_dir() . '/' . self::DEFAULT_SUBDIR;
    }

    /**
     * @param array<string, mixed> $state from {@see OpenApiCoverageTracker::exportState()}
     *
     * @throws RuntimeException when the sidecar cannot be created or persisted
     */
    public static function write(string $sidecarDir, string $testToken, array $state): string
    {
        $dir = self::ensureDir($sidecarDir);
        $finalPath = self::pathFor($dir, self::FILENAME_PREFIX, $testToken);

        try {
            $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('failed to encode coverage state: %s', $e->getMessage()), 0, $e);
        }

        self::writeAtomically($dir, $finalPath, $payload);

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
        if ($body === false) {
            return null;
        }

        try {
            self::writeAtomically($dir, $path, $body);
        } catch (RuntimeException) {
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
        if (is_link($dir)) {
            throw new RuntimeException(sprintf('sidecar dir must not be a symbolic link: "%s"', $dir));
        }

        if (!is_dir($dir) && !@mkdir($dir, 0o700, recursive: true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('failed to create sidecar dir "%s"', $dir));
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = @fileperms($dir);
            if ($permissions !== false && ($permissions & 0o022) !== 0) {
                throw new RuntimeException(sprintf('sidecar dir must not be writable by group or other users: "%s"', $dir));
            }
        }

        return $dir;
    }

    /**
     * Persist a payload without ever opening a caller-predictable temporary
     * path. The exclusive handle prevents a pre-existing symlink from being
     * followed; rename replaces the final directory entry rather than writing
     * through a symlink at that path.
     *
     * @throws RuntimeException
     */
    private static function writeAtomically(string $dir, string $finalPath, string $payload): void
    {
        [$handle, $tmpPath] = self::openExclusiveTempFile($dir);
        $published = false;

        try {
            if (!@chmod($tmpPath, 0o600)) {
                throw new RuntimeException(sprintf('failed to restrict sidecar tmp file permissions: "%s"', $tmpPath));
            }

            $length = strlen($payload);
            $offset = 0;
            while ($offset < $length) {
                $written = @fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException(sprintf('failed to write sidecar tmp file "%s"', $tmpPath));
                }
                $offset += $written;
            }

            if (!@fflush($handle)) {
                throw new RuntimeException(sprintf('failed to flush sidecar tmp file "%s"', $tmpPath));
            }
            if (!@fclose($handle)) {
                throw new RuntimeException(sprintf('failed to close sidecar tmp file "%s"', $tmpPath));
            }
            $handle = null;

            if (!@rename($tmpPath, $finalPath)) {
                throw new RuntimeException(sprintf('failed to rename sidecar "%s" -> "%s"', $tmpPath, $finalPath));
            }
            $published = true;
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            if (!$published) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * @return array{0: resource, 1: string}
     *
     * @throws RuntimeException
     */
    private static function openExclusiveTempFile(string $dir): array
    {
        for ($attempt = 0; $attempt < self::TEMP_FILE_ATTEMPTS; $attempt++) {
            try {
                $random = bin2hex(random_bytes(16));
            } catch (RandomException $e) {
                throw new RuntimeException('failed to generate a secure sidecar temporary filename', 0, $e);
            }

            $tmpPath = sprintf('%s/%s%s.tmp', $dir, self::TEMP_FILE_PREFIX, $random);
            $handle = @fopen($tmpPath, 'xb');
            if ($handle !== false) {
                return [$handle, $tmpPath];
            }
        }

        throw new RuntimeException(sprintf('failed to create an exclusive sidecar tmp file in "%s"', $dir));
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
