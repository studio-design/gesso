<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

use JsonException;
use RuntimeException;

use function file_get_contents;
use function glob;
use function is_array;
use function is_dir;
use function json_decode;
use function rtrim;
use function sort;
use function sprintf;

/**
 * Reads paratest worker sidecars produced by {@see CoverageSidecarWriter}.
 * Used by the merge CLI to assemble per-worker coverage state into a single
 * report; the reader is intentionally narrow — it just decodes JSON and
 * hands back raw payloads, leaving merge logic to the tracker.
 *
 * @internal Merge CLI implementation detail. Do not use from user code.
 */
final class CoverageSidecarReader
{
    private function __construct() {}

    /**
     * Decode every sidecar in the directory, in stable filename order
     * within a single run so `--cleanup` is deterministic and merge order
     * is reproducible across the same set of sidecars (PIDs are not stable
     * across runs, so this is intra-run determinism only).
     *
     * Returns an empty list (rather than throwing) for a missing directory:
     * the merge CLI runs in CI right after paratest, where a no-coverage run
     * is not a hard error — it just renders an empty report.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when a sidecar cannot be read or decoded
     */
    public static function readDir(string $sidecarDir): array
    {
        $payloads = [];
        foreach (self::listPaths($sidecarDir) as $path) {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException(sprintf('failed to read sidecar "%s"', $path));
            }

            try {
                $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(sprintf('failed to decode sidecar "%s": %s', $path, $e->getMessage()), 0, $e);
            }
            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf('failed to decode sidecar "%s": expected JSON object, got scalar', $path));
            }
            $payloads[] = $decoded;
        }

        return $payloads;
    }

    /**
     * @return list<string>
     */
    public static function listPaths(string $sidecarDir): array
    {
        return self::globPattern($sidecarDir, CoverageSidecarWriter::FILENAME_PREFIX . '*' . CoverageSidecarWriter::FILENAME_SUFFIX);
    }

    /**
     * Worker-side failure markers (see {@see CoverageSidecarWriter::writeFailureMarker()}).
     * The merge CLI fails loudly when any are present so a missing worker
     * cannot silently under-count coverage.
     *
     * @return list<string>
     */
    public static function listFailureMarkerPaths(string $sidecarDir): array
    {
        return self::globPattern($sidecarDir, CoverageSidecarWriter::FAILURE_MARKER_PREFIX . '*' . CoverageSidecarWriter::FILENAME_SUFFIX);
    }

    /**
     * @return list<string>
     */
    private static function globPattern(string $sidecarDir, string $pattern): array
    {
        $dir = rtrim($sidecarDir, '/' . DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            return [];
        }

        $matches = glob($dir . '/' . $pattern);
        if ($matches === false) {
            // glob() distinguishes "no matches" (empty array) from a real
            // I/O error (false). Treat false as a hard failure — the dir
            // existed two lines ago, so a permission flip or libc glob
            // failure is a genuine problem the user needs to see.
            throw new RuntimeException(sprintf('failed to enumerate sidecar dir "%s"', $dir));
        }
        sort($matches);

        /** @var list<string> */
        return $matches;
    }
}
