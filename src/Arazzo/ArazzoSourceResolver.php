<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

use InvalidArgumentException;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Studio\Gesso\Internal\OperationIndex;
use Studio\Gesso\Internal\SpecPath;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function in_array;
use function pathinfo;
use function realpath;
use function sprintf;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;

/**
 * Maps every source description to a named OpenAPI spec loaded through
 * {@see OpenApiSpecLoader}, so Arazzo steps see exactly the documents the
 * validators see and every loader protection (base-path confinement, the
 * remote opt-in, host allowlist, byte limit, redirects) applies. Arazzo never
 * fetches a source on its own: a remote source must be a configured
 * `remoteSpecs` entry.
 *
 * @internal Arazzo runner implementation detail; not public API.
 */
final class ArazzoSourceResolver
{
    private function __construct() {}

    /**
     * @return array<string, ArazzoResolvedSource> keyed by source name
     *
     * @throws InvalidArazzoDescriptionException
     */
    public static function resolve(ArazzoDescription $description): array
    {
        $resolved = [];
        foreach ($description->sources as $source) {
            $name = $source->name;
            $at = sprintf('sourceDescriptions[%s]', $name);
            if ($source->type !== 'openapi') {
                throw InvalidArazzoDescriptionException::single(
                    ArazzoDescriptionReason::UnsupportedSourceType,
                    sprintf("%s: '%s' sources are not supported; only openapi sources can be executed.", $at, $source->type),
                    $description->path,
                );
            }

            $specName = $source->isRemote()
                ? self::remoteSpecName($description, $source, $at)
                : self::localSpecName($description, $source, $at);

            try {
                $spec = OpenApiSpecLoader::load($specName);
                $operations = OperationIndex::build($specName, $spec);
            } catch (InvalidArgumentException|InvalidOpenApiSpecException|SpecFileNotFoundException $e) {
                throw InvalidArazzoDescriptionException::single(
                    ArazzoDescriptionReason::SourceUnloadable,
                    sprintf("%s: spec '%s' could not be loaded: %s", $at, $specName, $e->getMessage()),
                    $description->path,
                    $e,
                );
            }

            $resolved[$name] = new ArazzoResolvedSource($name, $specName, $spec, $operations);
        }

        return $resolved;
    }

    private static function remoteSpecName(ArazzoDescription $description, ArazzoSourceDescription $source, string $at): string
    {
        return OpenApiSpecLoader::remoteSpecNameForUrl($source->resolvedUrl) ?? throw InvalidArazzoDescriptionException::single(
            ArazzoDescriptionReason::SourceNotConfigured,
            sprintf(
                "%s: remote source '%s' is not configured; register it under remoteSpecs in OpenApiSpecLoader::configure(). Arazzo never fetches a source on its own.",
                $at,
                $source->resolvedUrl,
            ),
            $description->path,
        );
    }

    private static function localSpecName(ArazzoDescription $description, ArazzoSourceDescription $source, string $at): string
    {
        $real = realpath($source->resolvedUrl);
        if ($real === false) {
            throw InvalidArazzoDescriptionException::single(
                ArazzoDescriptionReason::SourceNotFound,
                sprintf("%s: source file '%s' does not exist.", $at, $source->resolvedUrl),
                $description->path,
            );
        }

        $base = realpath(OpenApiSpecLoader::getBasePath());
        if ($base === false || !SpecPath::isInsideRoot($real, $base, foldCase: false)) {
            throw InvalidArazzoDescriptionException::single(
                ArazzoDescriptionReason::SourceOutsideBasePath,
                sprintf("%s: source file '%s' is outside the configured spec base path.", $at, $real),
                $description->path,
            );
        }

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw InvalidArazzoDescriptionException::single(
                ArazzoDescriptionReason::UnsupportedExtension,
                sprintf("%s: source file '%s' must be .json, .yaml or .yml.", $at, $real),
                $description->path,
            );
        }

        $relative = substr($real, strlen($base) + 1, -(strlen($extension) + 1));
        $specName = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        try {
            $loaderFile = OpenApiSpecLoader::resolvedSpecFile($specName);
        } catch (SpecFileNotFoundException $e) {
            throw InvalidArazzoDescriptionException::single(ArazzoDescriptionReason::SourceNotFound, sprintf('%s: %s', $at, $e->getMessage()), $description->path, $e);
        }

        if (OpenApiSpecLoader::isRemoteSpecName($specName)) {
            throw InvalidArazzoDescriptionException::single(
                ArazzoDescriptionReason::AmbiguousSourceFile,
                sprintf("%s: spec name '%s' is also a configured remoteSpecs entry, which the loader would use instead of '%s'.", $at, $specName, $real),
                $description->path,
            );
        }

        // The loader searches json, yaml, yml in order: `pet.yaml` beside
        // `pet.json` would silently load the JSON file instead.
        if ($loaderFile !== $real) {
            throw InvalidArazzoDescriptionException::single(
                ArazzoDescriptionReason::AmbiguousSourceFile,
                sprintf("%s: '%s' would load '%s' instead; spec names resolve json, yaml, yml in that order.", $at, $real, $loaderFile),
                $description->path,
            );
        }

        return $specName;
    }
}
