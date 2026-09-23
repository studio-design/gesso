<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_DIRNAME;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

use JsonException;
use RuntimeException;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Throwable;

use function array_keys;
use function array_map;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_int;
use function is_readable;
use function json_decode;
use function pathinfo;
use function realpath;
use function sprintf;

/**
 * Input documents the `gesso stubs` / `gesso coverage:gate` commands read:
 * an OpenAPI entry document resolved through the runtime loader, and one
 * spec's entry in a `schema_version: 3` coverage JSON.
 *
 * @internal CLI implementation detail.
 */
final class CliDocuments
{
    private function __construct() {}

    /**
     * Resolve the document through the runtime loader so the command sees the
     * same `$ref`-resolved tree the validators enforce.
     *
     * @param string $path absolute path
     * @param string $inputPath the path as the user typed it, for messages
     *
     * @return array<string, mixed>
     */
    public static function loadSpec(string $path, string $inputPath): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Spec is not a readable file: {$inputPath}");
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw new RuntimeException("Unsupported spec extension: .{$extension} ({$inputPath})");
        }

        // The loader resolves a *name*, searching .json before .yaml before
        // .yml, so `--spec=openapi.yaml` next to an openapi.json would silently
        // pick the JSON document instead. Fail the way `gesso doctor` does
        // rather than act on a file the user did not name.
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        foreach (['json', 'yaml', 'yml'] as $candidateExtension) {
            $candidate = $directory . '/' . $name . '.' . $candidateExtension;
            if (!is_file($candidate)) {
                continue;
            }
            if (realpath($candidate) !== realpath($path)) {
                throw new RuntimeException(sprintf(
                    'The runtime loader selects %s before the requested %s. '
                    . 'Remove or rename the shadowing entry document.',
                    $candidate,
                    $inputPath,
                ));
            }

            break;
        }

        try {
            // The loader caches by spec name, so two documents sharing a
            // filename (the common `openapi.json` case) would otherwise
            // collide on the second load().
            OpenApiSpecLoader::reset();
            OpenApiSpecLoader::configure($directory);

            return OpenApiSpecLoader::load($name);
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot load {$inputPath}: " . $e->getMessage(), previous: $e);
        } finally {
            OpenApiSpecLoader::reset();
        }
    }

    /**
     * Read one spec's entry out of a coverage document.
     *
     * @param string $path absolute path
     * @param string $inputPath the path as the user typed it, for messages
     *
     * @return array<string, mixed>
     */
    public static function loadCoverageSpec(string $path, string $inputPath, string $specName): array
    {
        $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException("Coverage file is not a readable file: {$inputPath}");
        }

        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Coverage file is not valid JSON: {$inputPath}", previous: $e);
        }
        if (!is_array($document)) {
            throw new RuntimeException("Coverage file must decode to a JSON object: {$inputPath}");
        }

        $version = $document['schema_version'] ?? null;
        if (!is_int($version) || $version !== JsonCoverageRenderer::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Unsupported coverage schema_version in %s: expected %d.',
                $inputPath,
                JsonCoverageRenderer::SCHEMA_VERSION,
            ));
        }

        $specs = is_array($document['specs'] ?? null) ? $document['specs'] : [];
        $spec = $specs[$specName] ?? null;
        if (!is_array($spec)) {
            $available = array_map(static fn(mixed $name): string => (string) $name, array_keys($specs));

            throw new RuntimeException(sprintf(
                'Coverage document has no spec named "%s". Available: %s. Use --spec-name to select one.',
                $specName,
                $available === [] ? '(none)' : implode(', ', $available),
            ));
        }

        /** @var array<string, mixed> $spec */
        return $spec;
    }
}
